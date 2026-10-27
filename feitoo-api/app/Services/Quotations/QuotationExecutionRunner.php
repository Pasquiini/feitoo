<?php

namespace App\Services\Quotations;

use App\Domain\Quotations\QuotationLimits;
use App\Models\QuotationGeneration;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
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
            // Do not inherit database, OAuth, billing or other application secrets into the renderer.
            $env = array_fill_keys(array_keys(getenv()), false);
            foreach (['SystemRoot', 'WINDIR', 'PATH', 'TEMP', 'TMP'] as $name) {
                if (getenv($name) !== false) {
                    $env[$name] = getenv($name);
                }
            }
            $env = array_replace($env, ['APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_KEY' => 'base64:'.base64_encode($executionKey),
                'APP_CIPHER' => $cipher, 'LOG_CHANNEL' => 'null', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
                'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'QUOTATION_WORKSPACE' => $workspace,
                'APP_CONFIG_CACHE' => $workspace.'/bootstrap/config.php', 'APP_SERVICES_CACHE' => $workspace.'/bootstrap/services.php',
                'APP_PACKAGES_CACHE' => $workspace.'/bootstrap/packages.php', 'APP_ROUTES_CACHE' => $workspace.'/bootstrap/routes.php']);
            $process = new Process($this->command($workspace), base_path(), $env);
            $process->setTimeout(config('quotations.render_seconds') + 5);
            $process->disableOutput();
            try {
                $process->run();
            } catch (ProcessTimedOutException) {
                throw new QuotationPdfException('execution_timeout');
            }
            if (! $process->isSuccessful()) {
                $failure = $disk->exists($directory.'/failure.json') ? json_decode($disk->get($directory.'/failure.json'), true) : [];
                $reason = in_array($failure['code'] ?? '', ['unsupported_character', 'page_limit_exceeded', 'snapshot_invalid'], true) ? $failure['code'] : 'render_failed';
                throw new QuotationPdfException($reason);
            }
            $path = $disk->path($directory.'/result.pdf');
            if (! is_file($path) || filesize($path) > QuotationLimits::MAX_PDF_BYTES || filesize($path) < 100) {
                throw new QuotationPdfException('render_failed');
            }
            $bytes = $disk->get($directory.'/result.pdf');
            $meta = json_decode($disk->get($directory.'/result.json'), true, flags: JSON_THROW_ON_ERROR);
            if (! str_starts_with($bytes, '%PDF-') || ! preg_match('/startxref\s+\d+\s+%%EOF\s*\z/', substr($bytes, -2048))
                || ! hash_equals($meta['sha256'], hash('sha256', $bytes)) || $meta['pages'] < 1 || $meta['pages'] > QuotationLimits::MAX_PAGES) {
                throw new QuotationPdfException('render_failed');
            }

            return new QuotationPdf($bytes, $meta['pages'], $meta['sha256']);
        } catch (Throwable $error) {
            throw $error instanceof QuotationPdfException ? $error : new QuotationPdfException('execution_failed');
        } finally {
            $disk->deleteDirectory($directory);
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
        foreach (['/lib', '/lib64', '/etc/ld.so.cache', '/etc/php'] as $path) {
            if (file_exists($path)) {
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
