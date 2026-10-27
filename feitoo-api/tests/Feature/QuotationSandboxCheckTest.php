<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Quotations\QuotationLimits;
use App\Services\Quotations\QuotationExecutionRunner;
use App\Services\Quotations\QuotationPdfException;
use App\Services\Quotations\QuotationSandboxProbe;
use Illuminate\Database\DatabaseManager;
use Illuminate\Encryption\Encrypter;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class QuotationSandboxCheckTest extends TestCase
{
    private array $roots = [];

    private const CHECKS = ['workspace_readable', 'network_namespace', 'network_denied', 'network_interfaces', 'env_hidden',
        'private_hidden', 'canaries_hidden', 'symlink_denied', 'database_unbound', 'resource_limits'];

    protected function setUp(): void
    {
        parent::setUp();
        // Production behavior, generation disabled and no usable database. No RefreshDatabase/migrations.
        $this->app->instance('env', 'production');
        config(['quotations.sandbox' => 'bubblewrap', 'quotations.enabled' => false,
            'database.default' => 'mysql', 'database.connections' => []]);
        $this->app->instance('db', Mockery::mock(DatabaseManager::class));
        Storage::shouldReceive('disk')->never();
        Queue::shouldReceive('connection')->never();
        Queue::shouldReceive('push')->never();
    }

    protected function tearDown(): void
    {
        foreach ($this->roots as $root) {
            $this->assertDirectoryDoesNotExist($root, 'All private artifacts must be removed, also after failure.');
        }
        parent::tearDown();
    }

    private function fakeProbe(): void
    {
        $probe = Mockery::mock(QuotationSandboxProbe::class);
        $probe->shouldReceive('prepare')->once()->andReturnUsing(function (string $workspace, string $canary): array {
            $this->roots[] = dirname($workspace, 2);
            $this->assertFileExists($canary);
            $this->assertStringStartsWith(realpath(sys_get_temp_dir()).DIRECTORY_SEPARATOR.'feitoo-quotation-check-', dirname($workspace, 2));
            if (PHP_OS_FAMILY !== 'Windows') {
                $this->assertSame(0700, fileperms(dirname($workspace, 2)) & 0777);
            }

            return ['network_address' => 'tcp://127.0.0.1:1', 'network_namespace' => 'synthetic-test-control'];
        });
        $probe->shouldReceive('close')->once();
        $this->app->instance(QuotationSandboxProbe::class, $probe);
    }

    private function fakeRunner(): mixed
    {
        $runner = Mockery::mock(QuotationExecutionRunner::class)->makePartial();
        $runner->shouldReceive('available')->once()->andReturnTrue();
        $this->app->instance(QuotationExecutionRunner::class, $runner);

        return $runner;
    }

    private function report(string $workspace, array $checks = []): void
    {
        file_put_contents($workspace.'/isolation.json', json_encode(array_replace(array_fill_keys(self::CHECKS, true), $checks)));
    }

    private function pdf(string $workspace): void
    {
        $bytes = "%PDF-1.7\n";
        $offsets = [0];
        foreach (['<< /Type /Catalog /Pages 2 0 R >>', '<< /Type /Pages /Kids [3 0 R] /Count 1 >>', '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] >>'] as $object) {
            $offsets[] = strlen($bytes);
            $bytes .= count($offsets) - 1 ." 0 obj\n".$object."\nendobj\n";
        }
        $xref = strlen($bytes);
        $bytes .= "xref\n0 4\n0000000000 65535 f \n";
        foreach (array_slice($offsets, 1) as $offset) {
            $bytes .= sprintf("%010d 00000 n \n", $offset);
        }
        $bytes .= "trailer\n<< /Size 4 /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF\n";
        file_put_contents($workspace.'/result.pdf', $bytes);
        file_put_contents($workspace.'/result.json', json_encode(['pages' => 1, 'sha256' => hash('sha256', $bytes)]));
    }

    public function test_production_command_renders_both_real_templates_without_database_queue_or_storage_and_cleans_up(): void
    {
        $this->fakeProbe();
        $runner = $this->fakeRunner();
        $templates = [];
        $runner->shouldReceive('execute')->twice()->andReturnUsing(function (string $workspace, string $key, string $cipher, bool $sandboxCheck) use (&$templates, $runner): void {
            $this->assertTrue($sandboxCheck);
            $snapshot = json_decode((new Encrypter($key, $cipher))->decryptString(file_get_contents($workspace.'/snapshot.enc')), true);
            $this->assertSame('contato@example.test', $snapshot['document']['issuer']['email']);
            $templates[] = $snapshot['document']['template_id'];
            // Exercise the actual entrypoint, validator, Blade and Dompdf on every OS.
            // Only OS controls are simulated here; the separate Linux test runs real namespaces.
            $env = $runner->environment($workspace, $key, $cipher);
            $this->assertSame('production', $env['APP_ENV']);
            $env['QUOTATION_SANDBOX_CHECK'] = '0';
            $process = new Process([PHP_BINARY, base_path('scripts/render-quotation.php')], $workspace, $env);
            $process->setTimeout(35);
            $process->disableOutput();
            $this->assertSame(0, $process->run(), 'Commercial renderer must work without app providers or testing environment.');
            $this->report($workspace);
        });

        $this->artisan('feitoo:quotation-sandbox-check')->expectsOutputToContain('artefatos temporários removidos')->assertSuccessful();
        $this->assertSame(['classic', 'modern'], $templates);
    }

    public static function failures(): array
    {
        $cases = [];
        foreach (self::CHECKS as $check) {
            $cases[$check] = [$check];
        }
        foreach (['missing_report', 'missing_check', 'missing_pdf', 'checksum', 'pages', 'page_type', 'small', 'large', 'truncated', 'xref', 'process', 'timeout', 'modern_stale'] as $case) {
            $cases[$case] = [$case];
        }

        return $cases;
    }

    #[DataProvider('failures')]
    public function test_mandatory_failure_is_nonzero_sanitized_and_always_cleans_up(string $case): void
    {
        $this->fakeProbe();
        $runner = $this->fakeRunner();
        $calls = 0;
        $runner->shouldReceive('execute')->andReturnUsing(function (string $workspace) use ($case, &$calls): void {
            $calls++;
            if ($case === 'process') {
                throw new RuntimeException('SENSITIVE-CONTENT /private/customer .env-secret');
            }
            if ($case === 'timeout') {
                throw new QuotationPdfException('execution_timeout');
            }
            if ($case === 'modern_stale' && $calls === 2) {
                return; // First result must have been deleted before the second invocation.
            }
            $this->report($workspace, in_array($case, self::CHECKS, true) ? [$case => false] : []);
            $this->pdf($workspace);
            switch ($case) {
                case 'missing_report': unlink($workspace.'/isolation.json');
                    break;
                case 'missing_check': file_put_contents($workspace.'/isolation.json', '{}');
                    break;
                case 'missing_pdf': unlink($workspace.'/result.pdf');
                    break;
                case 'checksum': file_put_contents($workspace.'/result.json', json_encode(['pages' => 1, 'sha256' => str_repeat('a', 64)]));
                    break;
                case 'pages':
                case 'page_type':
                    $meta = json_decode(file_get_contents($workspace.'/result.json'), true);
                    $meta['pages'] = $case === 'pages' ? 2 : '1';
                    file_put_contents($workspace.'/result.json', json_encode($meta));
                    break;
                case 'small': file_put_contents($workspace.'/result.pdf', '%PDF-1.7');
                    break;
                case 'large': file_put_contents($workspace.'/result.pdf', str_repeat('x', QuotationLimits::MAX_PDF_BYTES + 1));
                    break;
                case 'truncated':
                    $bytes = "%PDF-1.7\n/Type /Page\n".str_repeat('x', 100);
                    file_put_contents($workspace.'/result.pdf', $bytes);
                    file_put_contents($workspace.'/result.json', json_encode(['pages' => 1, 'sha256' => hash('sha256', $bytes)]));
                    break;
                case 'xref':
                    $bytes = preg_replace('/startxref\s+\d+/', "startxref\n1", file_get_contents($workspace.'/result.pdf'));
                    file_put_contents($workspace.'/result.pdf', $bytes);
                    file_put_contents($workspace.'/result.json', json_encode(['pages' => 1, 'sha256' => hash('sha256', $bytes)]));
                    break;
            }
        });

        $this->assertSame(1, Artisan::call('feitoo:quotation-sandbox-check'));
        $output = Artisan::output();
        $this->assertStringContainsString('Checagem falhou:', $output);
        $this->assertStringNotContainsString('SENSITIVE-CONTENT', $output);
        $this->assertStringNotContainsString('.env-secret', $output);
        $this->assertStringNotContainsString($this->roots[0], $output);
        $this->assertStringNotContainsString('Checagem concluída', $output);
    }

    public function test_php_fallback_is_refused_even_if_runner_claims_available(): void
    {
        config(['quotations.sandbox' => 'php']);
        $runner = Mockery::mock(QuotationExecutionRunner::class);
        $runner->shouldNotReceive('execute');
        $this->app->instance(QuotationExecutionRunner::class, $runner);
        $this->artisan('feitoo:quotation-sandbox-check')->expectsOutputToContain('sandbox_unavailable')->assertFailed();
    }

    public function test_unavailable_bubblewrap_or_prlimit_fails_closed(): void
    {
        $runner = Mockery::mock(QuotationExecutionRunner::class);
        $runner->shouldReceive('available')->once()->andReturnFalse();
        $runner->shouldNotReceive('execute');
        $this->app->instance(QuotationExecutionRunner::class, $runner);
        $this->artisan('feitoo:quotation-sandbox-check')->expectsOutputToContain('sandbox_unavailable')->assertFailed();
    }

    public function test_environment_does_not_inherit_secrets_from_any_symfony_source(): void
    {
        $_ENV['QUOTATION_ENV_SECRET'] = 'sensitive';
        $_SERVER['QUOTATION_SERVER_SECRET'] = 'sensitive';
        putenv('QUOTATION_PROCESS_SECRET=sensitive');
        try {
            $env = (new QuotationExecutionRunner)->environment('/synthetic', random_bytes(32), 'AES-256-CBC');
            foreach (['QUOTATION_ENV_SECRET', 'QUOTATION_SERVER_SECRET', 'QUOTATION_PROCESS_SECRET'] as $name) {
                $this->assertFalse($env[$name]);
            }
            $this->assertSame('production', $env['APP_ENV']);
            $this->assertSame('0', $env['QUOTATION_SANDBOX_CHECK']);
        } finally {
            unset($_ENV['QUOTATION_ENV_SECRET'], $_SERVER['QUOTATION_SERVER_SECRET']);
            putenv('QUOTATION_PROCESS_SECRET');
        }
    }

    public function test_child_output_is_discarded_and_nonzero_exit_cleans_everything(): void
    {
        $this->fakeProbe();
        $runner = $this->fakeRunner();
        $runner->shouldReceive('command')->once()->andReturn([PHP_BINARY, '-r',
            'fwrite(STDOUT, "CHILD-SENSITIVE"); fwrite(STDERR, "CHILD-SENSITIVE"); exit(5);']);
        $this->assertSame(1, Artisan::call('feitoo:quotation-sandbox-check'));
        $output = Artisan::output();
        $this->assertStringContainsString('classic_render_failed', $output);
        $this->assertStringNotContainsString('CHILD-SENSITIVE', $output);
    }

    public function test_failed_positive_control_is_not_a_successful_isolation_check(): void
    {
        $probe = Mockery::mock(QuotationSandboxProbe::class);
        $probe->shouldReceive('prepare')->once()->andReturnUsing(function (string $workspace): never {
            $this->roots[] = dirname($workspace, 2);
            throw new RuntimeException('SENSITIVE-CONTROL-DETAIL');
        });
        $probe->shouldReceive('close')->once();
        $this->app->instance(QuotationSandboxProbe::class, $probe);
        $runner = $this->fakeRunner();
        $runner->shouldNotReceive('execute');
        $this->assertSame(1, Artisan::call('feitoo:quotation-sandbox-check'));
        $output = Artisan::output();
        $this->assertStringContainsString('isolation_control_failed', $output);
        $this->assertStringNotContainsString('SENSITIVE-CONTROL-DETAIL', $output);
    }

    public function test_cleanup_failure_turns_an_otherwise_successful_check_into_failure(): void
    {
        $this->fakeProbe();
        $runner = $this->fakeRunner();
        $runner->shouldReceive('execute')->twice()->andReturnUsing(function (string $workspace, string $key, string $cipher) use ($runner): void {
            $process = new Process([PHP_BINARY, base_path('scripts/render-quotation.php')], $workspace, $runner->environment($workspace, $key, $cipher));
            $process->disableOutput();
            $this->assertSame(0, $process->run());
            $this->report($workspace);
        });
        $files = Mockery::mock(Filesystem::class)->makePartial();
        $files->shouldReceive('deleteDirectory')->once()->andReturnFalse();
        $this->app->instance(Filesystem::class, $files);
        try {
            $this->assertSame(1, Artisan::call('feitoo:quotation-sandbox-check'));
            $output = Artisan::output();
            $this->assertStringContainsString('Isolamento:', $output);
            $this->assertStringContainsString('cleanup_failed', $output);
            $this->assertStringNotContainsString('Checagem concluída', $output);
        } finally {
            foreach ($this->roots as $root) {
                $this->assertSame(realpath(sys_get_temp_dir()), realpath(dirname($root)));
                $this->assertStringStartsWith('feitoo-quotation-check-', basename($root));
                (new Filesystem)->deleteDirectory($root);
            }
        }
    }

    public function test_linux_command_preserves_system_symlinks_and_required_restrictions(): void
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            $this->markTestSkipped('Linux runtime layout required.');
        }
        $runner = Mockery::mock(QuotationExecutionRunner::class)->makePartial();
        $runner->shouldReceive('available')->andReturnTrue();
        $args = $runner->command('/tmp/synthetic-workspace');
        foreach (['/lib', '/lib64', '/bin'] as $path) {
            if (is_link($path)) {
                $index = array_search($path, $args, true);
                $this->assertSame(['--symlink', readlink($path), $path], array_slice($args, $index - 2, 3));
            }
        }
        $this->assertSame(config('quotations.prlimit_binary'), $args[0]);
        foreach (['--unshare-all', '--die-with-parent', '--new-session', '--cap-drop', '--as=335544320'] as $flag) {
            $this->assertContains($flag, $args);
        }
        $this->assertNotContains(base_path('.env'), $args);
        $this->assertNotContains(storage_path('app/private'), $args);
    }

    public function test_real_linux_bubblewrap_command(): void
    {
        if (PHP_OS_FAMILY !== 'Linux' || ! (new QuotationExecutionRunner)->available()) {
            $this->markTestSkipped('Requires Linux, Bubblewrap and prlimit; no PHP fallback.');
        }
        $this->artisan('feitoo:quotation-sandbox-check')->assertSuccessful();
    }
}
