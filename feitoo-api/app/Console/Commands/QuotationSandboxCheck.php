<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Quotations\NormalizedQuotationLogo;
use App\Domain\Quotations\Quotation;
use App\Services\Quotations\QuotationExecutionRunner;
use App\Services\Quotations\QuotationPdfException;
use App\Services\Quotations\QuotationSandboxProbe;
use App\Support\Quotations\QuotationDemoFixtures;
use Illuminate\Console\Command;
use Illuminate\Encryption\Encrypter;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class QuotationSandboxCheck extends Command
{
    protected $signature = 'feitoo:quotation-sandbox-check';

    protected $description = 'Verifica PDF comercial e isolamento Bubblewrap/prlimit com dados fictícios, sem banco ou consumo';

    private const CHECKS = ['workspace_readable', 'network_namespace', 'network_denied', 'network_interfaces', 'env_hidden',
        'private_hidden', 'canaries_hidden', 'symlink_denied', 'database_unbound', 'resource_limits'];

    public function handle(QuotationExecutionRunner $runner, Filesystem $files, QuotationSandboxProbe $probe): int
    {
        $root = null;
        $previousMask = umask(0077);
        set_error_handler(static function (int $severity): bool {
            if (error_reporting() & $severity) {
                throw new RuntimeException;
            }

            return true;
        });
        $status = self::FAILURE;
        $stage = 'sandbox_unavailable';
        try {
            if (extension_loaded('pcntl')) {
                $this->trap([SIGINT, SIGTERM], static function (): void {
                    throw new RuntimeException;
                });
            }
            // No PHP fallback, including when invoked from local/testing or with generation disabled.
            if (config('quotations.sandbox') !== 'bubblewrap' || ! $runner->available()) {
                throw new RuntimeException;
            }
            $stage = 'temporary_workspace_failed';
            $temporary = realpath(sys_get_temp_dir());
            if ($temporary === false) {
                throw new RuntimeException;
            }
            $candidate = $temporary.DIRECTORY_SEPARATOR.'feitoo-quotation-check-'.Str::uuid();
            if (! mkdir($candidate, 0700)) {
                throw new RuntimeException;
            }
            // Only this newly created directory can ever be removed by this command.
            $root = $candidate;
            if (is_link($root) || realpath(dirname($root)) !== $temporary
                || (PHP_OS_FAMILY !== 'Windows' && (fileperms($root) & 0777) !== 0700)) {
                throw new RuntimeException;
            }
            $workspace = $root.'/'.Str::uuid().'/'.Str::uuid();
            $files->makeDirectory($workspace, 0700, true);
            $files->makeDirectory($root.'/private/processing', 0700, true);
            $files->makeDirectory($root.'/private/docx', 0700, true);
            $canaries = [$root.'/.env', $root.'/private/processing/example.xml', $root.'/private/docx/example.docx'];
            foreach ($canaries as $path) {
                $this->write($path, 'FICTITIOUS-SANDBOX-CANARY');
            }
            $stage = 'isolation_control_failed';
            $control = $probe->prepare($workspace, $canaries[0]);
            $stage = 'isolation_failed';
            $this->write($workspace.'/probe.json', json_encode([...$control,
                'cpu_seconds' => config('quotations.render_seconds') + 2,
                'env_paths' => array_unique([base_path('.env'), app()->environmentFilePath(), base_path('bootstrap/cache/config.php')]),
                'private_paths' => array_values(array_unique([
                    storage_path('app/private'), storage_path('app/private/processing'),
                    (string) config('filesystems.disks.processing.root'),
                    (string) config('filesystems.disks.quotation_generations.root'),
                    (string) config('filesystems.disks.quotation_drafts.root'),
                ])),
                'canary_paths' => $canaries,
            ], JSON_THROW_ON_ERROR));

            $key = Encrypter::generateKey('AES-256-CBC');
            $cipher = new Encrypter($key, 'AES-256-CBC');
            foreach (['classic', 'modern'] as $template) {
                $stage = $template.'_document_preparation_failed';
                $document = array_replace(QuotationDemoFixtures::simple(), ['template_id' => $template]);
                if ($template === 'modern') {
                    $document['items'] = array_map(static fn (int $number): array => [
                        'description' => 'Serviço fictício '.$number.': instalação, manutenção preventiva e verificação de equipamentos de demonstração.',
                        'quantity' => '1.25', 'unit_price_cents' => 10000,
                    ], range(1, 24));
                    $stage = $template.'_logo_fixture_failed';
                    $logoBytes = QuotationDemoFixtures::logo('png');
                    $stage = $template.'_logo_normalization_failed';
                    $logo = NormalizedQuotationLogo::fromBytes($logoBytes);
                    $stage = $template.'_logo_write_failed';
                    $this->write($workspace.'/logo.png', $logo->png);
                }
                $stage = $template.'_document_validation_failed';
                $quotation = Quotation::fromArray($document);
                $stage = $template.'_snapshot_preparation_failed';
                $this->write($workspace.'/snapshot.enc', $cipher->encryptString(json_encode([
                    'schema_version' => 1, 'document' => $document, 'totals' => (array) $quotation->totals,
                ], JSON_THROW_ON_ERROR)));
                // A stale report/result can never make a second subprocess appear successful.
                $stage = $template.'_artifact_cleanup_failed';
                $files->delete([$workspace.'/result.pdf', $workspace.'/result.json', $workspace.'/failure.json', $workspace.'/isolation.json']);
                foreach (['result.pdf', 'result.json', 'failure.json', 'isolation.json'] as $artifact) {
                    clearstatcache(true, $workspace.'/'.$artifact);
                    if (file_exists($workspace.'/'.$artifact) || is_link($workspace.'/'.$artifact)) {
                        throw new RuntimeException;
                    }
                }
                $stage = $template.'_execution_failed';
                try {
                    $runner->execute($workspace, $key, 'AES-256-CBC', sandboxCheck: true);
                } catch (QuotationPdfException $error) {
                    if (in_array($error->reason, QuotationPdfException::DIAGNOSTIC_REASONS, true)) {
                        $stage = $template.'_'.$error->reason;
                    }
                    throw $error;
                }
                $stage = 'isolation_failed';
                $report = $workspace.'/isolation.json';
                if (is_link($report) || ! is_file($report) || filesize($report) > 2048) {
                    throw new RuntimeException;
                }
                $checks = json_decode(file_get_contents($report), true, flags: JSON_THROW_ON_ERROR);
                foreach (self::CHECKS as $check) {
                    if (($checks[$check] ?? null) !== true) {
                        throw new RuntimeException;
                    }
                }
                $stage = $template.'_pdf_invalid';
                $pdf = $runner->readResult($workspace);
                if ($template === 'modern' && ($pdf->pages < 2 || ! str_contains($pdf->bytes, '/Subtype /Image'))) {
                    throw new RuntimeException;
                }
                $this->line($template.': PDF, checksum, páginas e tamanho OK ('.$pdf->pages.' página(s), '.strlen($pdf->bytes).' bytes).');
            }
            $this->line('Isolamento: rede, .env, arquivos privados e escape por symlink OK.');
            $status = self::SUCCESS;
        } catch (Throwable) {
            // Fixed codes only: no exceptions, paths, environment values or child stdout/stderr.
            $this->error('Checagem falhou: '.$stage.'.');
        } finally {
            $probe->close();
            if ($root !== null) {
                try {
                    $files->deleteDirectory($root);
                    clearstatcache();
                    if (file_exists($root) || is_link($root)) {
                        throw new RuntimeException;
                    }
                } catch (Throwable) {
                    $this->error('Checagem falhou: cleanup_failed.');
                    $status = self::FAILURE;
                }
            }
            umask($previousMask);
            restore_error_handler();
        }
        if ($status === self::SUCCESS) {
            $this->info('Checagem concluída; artefatos temporários removidos.');
        }

        return $status;
    }

    private function write(string $path, string $bytes): void
    {
        if (file_put_contents($path, $bytes) !== strlen($bytes)) {
            throw new RuntimeException;
        }
    }
}
