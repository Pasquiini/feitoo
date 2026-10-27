<?php

namespace App\Services\Quotations;

use App\Domain\Quotations\QuotationLimits;
use App\Models\QuotationGeneration;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

class QuotationExecutionRunner
{
    public function available(): bool
    {
        if (! function_exists('proc_open') || ! is_file(config('quotations.php_binary'))) {
            return false;
        }
        $sandbox = config('quotations.sandbox');
        if ($sandbox === 'php') {
            return app()->environment('local', 'testing');
        }

        return $sandbox === 'bubblewrap' && PHP_OS_FAMILY === 'Linux'
            && is_executable(config('quotations.bubblewrap_binary')) && is_executable(config('quotations.prlimit_binary'));
    }

    /** One host-wide rendering slot, separate from financial locks. Busy jobs are released without claiming an attempt. */
    public function acquireSlot(): mixed
    {
        $disk = Storage::disk('quotation_execution');
        $disk->makeDirectory('locks');
        $handle = fopen($disk->path('locks/render.lock'), 'c');
        if (! $handle) {
            throw new QuotationPdfException('execution_failed');
        }
        if (! flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return null;
        }

        return $handle;
    }

    public function render(QuotationGeneration $generation): QuotationPdf
    {
        if (! $this->available()) {
            throw new QuotationPdfException('execution_failed');
        }
        if ($generation->renderer_version !== config('quotations.renderer_version')) {
            throw new QuotationPdfException('renderer_version_unavailable');
        }
        $disk = Storage::disk('quotation_execution');
        $directory = $generation->uuid.'/'.$generation->attempt_token;
        $disk->makeDirectory($directory);
        $workspace = $disk->path($directory);
        if (PHP_OS_FAMILY !== 'Windows') {
            chmod($workspace, 0700);
        }
        try {
            $source = Storage::disk('quotation_generations');
            $snapshot = $source->get($generation->snapshot_path);
            if (! hash_equals($generation->snapshot_checksum, hash('sha256', $snapshot))) {
                throw new QuotationPdfException('snapshot_invalid');
            }
            // Give the renderer only a one-use key, never the application's encryption key.
            $cipher = config('app.cipher');
            $executionKey = Encrypter::generateKey($cipher);
            $executionCipher = new Encrypter($executionKey, $cipher);
            $disk->put($directory.'/snapshot.enc', $executionCipher->encryptString(Crypt::decryptString($snapshot)));
            if ($generation->logo_path) {
                $logo = $source->get($generation->logo_path);
                if (! hash_equals($generation->logo_checksum, hash('sha256', $logo))) {
                    throw new QuotationPdfException('snapshot_invalid');
                }
                $disk->put($directory.'/logo.png', $logo);
            }
            $this->execute($workspace, $executionKey, $cipher);

            return $this->readResult($workspace);
        } catch (Throwable $error) {
            throw $error instanceof QuotationPdfException ? $error : new QuotationPdfException('execution_failed');
        } finally {
            $disk->deleteDirectory($directory);
        }
    }

    /** Shared by the commercial worker and the synthetic production diagnostic; no models or storage disks. */
    public function execute(string $workspace, string $executionKey, string $cipher, bool $sandboxCheck = false): void
    {
        $process = new Process($this->command($workspace), $workspace, $this->environment($workspace, $executionKey, $cipher, $sandboxCheck));
        $process->setTimeout(config('quotations.render_seconds') + 5);
        $process->disableOutput();
        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            throw new QuotationPdfException('execution_timeout');
        } catch (ProcessSignaledException) {
            // A kill alone does not prove OOM or CPU exhaustion. Only classify
            // resource signals when the OS actually reports that specific signal.
            throw new QuotationPdfException(match ($process->getTermSignal()) {
                24 => 'cpu_limit_exceeded', // Linux SIGXCPU
                25 => 'file_size_limit_exceeded', // Linux SIGXFSZ
                default => 'execution_terminated',
            });
        } catch (Throwable) {
            throw new QuotationPdfException('execution_failed');
        } finally {
            if ($process->isRunning()) {
                $process->stop(0);
            }
        }
        if (! $process->isSuccessful()) {
            throw new QuotationPdfException($this->failureReason($workspace));
        }
    }

    private function failureReason(string $workspace): string
    {
        $path = $workspace.'/failure.json';
        try {
            clearstatcache(true, $path);
            if (! is_link($path) && is_file($path) && filesize($path) <= 1024) {
                $failure = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
                if (is_array($failure) && in_array($failure['code'] ?? null, QuotationPdfException::DIAGNOSTIC_REASONS, true)) {
                    return $failure['code'];
                }
            }
        } catch (Throwable) {
            // An absent/malformed/unreadable report supplies no evidence of cause.
        }

        return 'execution_failed';
    }

    public function environment(string $workspace, string $executionKey, string $cipher, bool $sandboxCheck = false): array
    {
        // Symfony also inherits $_SERVER/$_ENV, including credentials not returned by getenv().
        $env = array_fill_keys(array_unique([...array_keys(getenv()), ...array_keys($_SERVER), ...array_keys($_ENV)]), false);
        foreach (['SystemRoot', 'WINDIR', 'PATH', 'TEMP', 'TMP'] as $name) {
            if (getenv($name) !== false) {
                $env[$name] = getenv($name);
            }
        }

        return array_replace($env, ['APP_ENV' => 'production', 'APP_DEBUG' => 'false',
            'APP_KEY' => 'base64:'.base64_encode($executionKey), 'APP_CIPHER' => $cipher,
            'QUOTATION_WORKSPACE' => $workspace, 'QUOTATION_SANDBOX_CHECK' => $sandboxCheck ? '1' : '0']);
    }

    public function readResult(string $workspace): QuotationPdf
    {
        try {
            $path = $workspace.'/result.pdf';
            clearstatcache(true, $path);
            if (is_link($path) || ! is_file($path) || filesize($path) > QuotationLimits::MAX_PDF_BYTES || filesize($path) < 100
                || is_link($workspace.'/result.json') || ! is_file($workspace.'/result.json') || filesize($workspace.'/result.json') > 1024) {
                throw new QuotationPdfException('render_failed');
            }
            $bytes = file_get_contents($path);
            $meta = json_decode(file_get_contents($workspace.'/result.json'), true, flags: JSON_THROW_ON_ERROR);
            $pages = preg_match_all('~(?:\A|\r?\n)\d+\s+\d+\s+obj\s*<<\s*/Type\s*/Page\b~', $bytes);
            if (! str_starts_with($bytes, '%PDF-') || ! preg_match('/startxref\s+(\d+)\s+%%EOF\s*\z/', substr($bytes, -2048), $xref)
                || (int) $xref[1] >= strlen($bytes) || substr($bytes, (int) $xref[1], 4) !== 'xref'
                || ! is_string($meta['sha256'] ?? null) || ! preg_match('/\A[0-9a-f]{64}\z/', $meta['sha256'])
                || ! hash_equals($meta['sha256'], hash('sha256', $bytes)) || ! is_int($meta['pages'] ?? null)
                || $meta['pages'] < 1 || $meta['pages'] > QuotationLimits::MAX_PAGES || $pages !== $meta['pages']) {
                throw new QuotationPdfException('render_failed');
            }

            return new QuotationPdf($bytes, $meta['pages'], $meta['sha256']);
        } catch (Throwable) {
            throw new QuotationPdfException('render_failed');
        }
    }

    /** Array arguments, never a shell command. Production refuses the development-only PHP isolation mode. */
    public function command(string $workspace): array
    {
        $args = [config('quotations.php_binary'), '-d', 'memory_limit='.config('quotations.memory_mb').'M',
            '-d', 'max_execution_time='.config('quotations.render_seconds'), '-d', 'allow_url_fopen=0', '-d', 'allow_url_include=0',
            '-d', 'display_errors=0', '-d', 'log_errors=0', '-d', 'disable_functions=exec,shell_exec,system,passthru,popen,proc_open,pcntl_exec,mail',
            base_path('scripts/render-quotation.php')];
        if (config('quotations.sandbox') === 'php' && app()->environment('local', 'testing')) {
            return $args;
        }
        if (! $this->available()) {
            throw new QuotationPdfException('execution_failed');
        }
        $prefix = [config('quotations.prlimit_binary'), '--as=335544320', '--cpu='.(config('quotations.render_seconds') + 2), '--fsize=12582912', '--',
            config('quotations.bubblewrap_binary'), '--unshare-all', '--die-with-parent', '--new-session', '--cap-drop', 'ALL',
            '--ro-bind', '/usr', '/usr'];
        foreach (['/lib', '/lib64', '/bin', '/etc/ld.so.cache', '/etc/php'] as $path) {
            // Ubuntu's merged /usr uses relative symlinks. Preserve them instead of
            // binding their resolved directories over destinations that may already be links.
            if (is_link($path)) {
                array_push($prefix, '--symlink', readlink($path), $path);
            } elseif (file_exists($path)) {
                array_push($prefix, '--ro-bind', $path, $path);
            }
        }
        foreach (['app', 'config', 'resources', 'routes', 'scripts', 'vendor'] as $path) {
            array_push($prefix, '--ro-bind', base_path($path), base_path($path));
        }
        // Production's bootstrap/cache/config.php can contain secrets: never mount that directory.
        foreach (['bootstrap/app.php', 'bootstrap/providers.php', 'composer.json'] as $path) {
            array_push($prefix, '--ro-bind', base_path($path), base_path($path));
        }
        array_push($prefix, '--proc', '/proc', '--dev', '/dev', '--tmpfs', '/tmp', '--bind', $workspace, $workspace,
            '--chdir', $workspace, '--');

        return [...$prefix, ...$args];
    }
}
