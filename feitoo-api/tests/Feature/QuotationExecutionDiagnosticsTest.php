<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Quotations\Quotation;
use App\Services\Quotations\QuotationExecutionRunner;
use App\Services\Quotations\QuotationPdfException;
use App\Support\Quotations\QuotationDemoFixtures;
use Illuminate\Encryption\Encrypter;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class QuotationExecutionDiagnosticsTest extends TestCase
{
    private string $root;

    private string $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/feitoo-quotation-diagnostic-test-'.Str::uuid();
        $this->workspace = $this->root.'/'.Str::uuid().'/'.Str::uuid();
        (new Filesystem)->makeDirectory($this->workspace, 0700, true);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->root);
        $this->assertDirectoryDoesNotExist($this->root);
        parent::tearDown();
    }

    // Subprocess doubles exercise Symfony exit/timeout/signal handling. The
    // separate Linux command test exercises the unchanged production sandbox.
    private function runner(array $command): QuotationExecutionRunner
    {
        $runner = Mockery::mock(QuotationExecutionRunner::class)->makePartial();
        $runner->shouldReceive('command')->once()->with($this->workspace)->andReturn($command);

        return $runner;
    }

    private function assertReason(QuotationExecutionRunner $runner, string $reason, ?string $key = null): void
    {
        try {
            $runner->execute($this->workspace, $key ?? random_bytes(32), 'AES-256-CBC');
            $this->fail('A failed child must never pass.');
        } catch (QuotationPdfException $error) {
            $this->assertSame($reason, $error->reason);
            $this->assertNull($error->getPrevious());
            $this->assertStringNotContainsString($this->workspace, $error->getMessage());
        }
    }

    public static function reports(): iterable
    {
        foreach (QuotationPdfException::DIAGNOSTIC_REASONS as $code) {
            yield $code => [json_encode(['code' => $code]), $code];
        }
        yield 'unknown' => ['{"code":"SECRET /private/.env"}', 'execution_failed'];
        yield 'malformed' => ['{', 'execution_failed'];
        yield 'wrong type' => ['{"code":[]}', 'execution_failed'];
        yield 'scalar' => ['true', 'execution_failed'];
        yield 'oversized' => [str_repeat('x', 1025), 'execution_failed'];
    }

    #[DataProvider('reports')]
    public function test_only_bounded_allowlisted_child_codes_are_accepted(string $report, string $reason): void
    {
        file_put_contents($this->workspace.'/failure.json', $report);
        $runner = $this->runner([PHP_BINARY, '-r', 'fwrite(STDOUT, "SECRET"); fwrite(STDERR, "SECRET"); exit(2);']);
        $this->assertReason($runner, $reason);
    }

    public function test_symlink_failure_report_is_not_followed(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Requires unprivileged symlink support.');
        }
        file_put_contents($this->root.'/private.json', '{"code":"memory_limit_exceeded"}');
        symlink($this->root.'/private.json', $this->workspace.'/failure.json');
        $this->assertReason($this->runner([PHP_BINARY, '-r', 'exit(2);']), 'execution_failed');
    }

    public function test_wall_timeout_is_distinct_from_a_render_error(): void
    {
        config(['quotations.render_seconds' => -4]); // A one-second timeout in this subprocess double only.
        $this->assertReason($this->runner([PHP_BINARY, '-r', 'sleep(10);']), 'execution_timeout');
    }

    public static function signals(): iterable
    {
        yield 'CPU' => [24, 'cpu_limit_exceeded'];
        yield 'file size' => [25, 'file_size_limit_exceeded'];
        yield 'SIGKILL is not proof of OOM' => [9, 'execution_terminated'];
    }

    #[DataProvider('signals')]
    public function test_signal_classification_requires_os_evidence(int $signal, string $reason): void
    {
        if (PHP_OS_FAMILY !== 'Linux' || ! function_exists('posix_kill')) {
            $this->markTestSkipped('Requires Linux signals and POSIX.');
        }
        $this->assertReason($this->runner([PHP_BINARY, '-r', 'posix_kill(getmypid(), '.$signal.');']), $reason);
    }

    public function test_numeric_exit_status_is_not_proof_of_a_resource_limit(): void
    {
        $this->assertReason($this->runner([PHP_BINARY, '-r', 'exit(137);']), 'execution_failed');
    }

    public static function entrypointFailures(): iterable
    {
        yield 'ciphertext' => ['ciphertext', 'snapshot_invalid'];
        yield 'schema' => ['schema', 'snapshot_invalid'];
        yield 'document' => ['document', 'document_preparation_failed'];
        yield 'totals' => ['totals', 'snapshot_invalid'];
        yield 'logo' => ['logo', 'logo_normalization_failed'];
        yield 'result' => ['result', 'result_write_failed'];
        yield 'PHP memory fatal' => ['memory', 'memory_limit_exceeded'];
    }

    #[DataProvider('entrypointFailures')]
    public function test_real_entrypoint_reports_preparation_output_and_php_memory_failures(string $case, string $reason): void
    {
        $document = QuotationDemoFixtures::simple();
        $snapshot = ['schema_version' => 1, 'document' => $document, 'totals' => (array) Quotation::fromArray($document)->totals];
        if ($case === 'schema') {
            unset($snapshot['document']);
        }
        if ($case === 'document') {
            $snapshot['document']['items'] = [];
        }
        if ($case === 'totals') {
            $snapshot['totals']['totalCents'] = 1;
        }
        if ($case === 'logo') {
            file_put_contents($this->workspace.'/logo.png', 'INVALID-SECRET-LOGO');
        }
        if ($case === 'result') {
            mkdir($this->workspace.'/result.json');
        }
        $key = Encrypter::generateKey('AES-256-CBC');
        $cipher = new Encrypter($key, 'AES-256-CBC');
        file_put_contents($this->workspace.'/snapshot.enc', $case === 'ciphertext' ? 'INVALID-SECRET-SNAPSHOT' : $cipher->encryptString(json_encode($snapshot)));
        $runner = $this->runner([PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=0',
            '-d', 'memory_limit='.($case === 'memory' ? '8M' : '192M'), base_path('scripts/render-quotation.php')]);
        $this->assertReason($runner, $reason, $key);
        $this->assertSame(['code' => $reason], json_decode(file_get_contents($this->workspace.'/failure.json'), true));
        $this->assertSame([], glob($this->workspace.'/runtime/app/private/quotation-temporary/*') ?: []);
    }
}
