<?php

declare(strict_types=1);

/** Disposable MariaDB/MySQL ONLY. The running daemon's datadir must be inside this test's private workspace. */
require dirname(__DIR__).'/vendor/autoload.php';

use App\Enums\ProcessingStatus;
use App\Exceptions\QuotationGenerationException;
use App\Models\Processing;
use App\Models\QuotationDraft;
use App\Models\QuotationGeneration;
use App\Models\User;
use App\Services\Quotations\QuotationExecutionRunner;
use App\Services\Quotations\QuotationGenerationService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

$mode = $argv[1] ?? 'parent';
$base = str_replace('\\', '/', dirname(__DIR__));
$root = $base.'/storage/app/private/quotation-validation/mysql-phase4-20261022';
$port = (int) (getenv('QUOTATION_TEST_PORT') ?: 33077);
if ($port < 33070 || $port > 33100 || ! is_dir($root.'/mysql')) {
    throw new RuntimeException('Dedicated initialized test daemon required.');
}
$pdo = new PDO('mysql:host=127.0.0.1;port='.$port, 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$server = $pdo->query('SELECT VERSION() version, @@datadir datadir')->fetch(PDO::FETCH_ASSOC);
if (rtrim(str_replace('\\', '/', $server['datadir']), '/') !== $root) {
    throw new RuntimeException('Refusing non-test database server.');
}
if (! (int) $pdo->query('SELECT @@explicit_defaults_for_timestamp')->fetchColumn()) {
    throw new RuntimeException('Start the disposable daemon with --explicit-defaults-for-timestamp=1.');
}
$database = getenv('QUOTATION_TEST_DATABASE') ?: 'feitoo_quotation_test_'.bin2hex(random_bytes(6));
if (! preg_match('/^feitoo_quotation_test_[0-9a-f]{12}$/D', $database)) {
    throw new RuntimeException('Refusing non-disposable database name.');
}
if ($mode === 'parent') {
    $pdo->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
}
foreach (['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => (string) $port,
    'DB_DATABASE' => $database, 'DB_USERNAME' => 'root', 'DB_PASSWORD' => '', 'DB_URL' => '', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array'] as $name => $value) {
    putenv($name.'='.$value);
}
$app = require $base.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['database.default' => 'mysql', 'database.connections.mysql.url' => null, 'database.connections.mysql.host' => '127.0.0.1',
    'database.connections.mysql.port' => $port, 'database.connections.mysql.database' => $database,
    'database.connections.mysql.username' => 'root', 'database.connections.mysql.password' => '',
    'quotations.enabled' => true, 'quotations.sandbox' => 'php',
    'filesystems.disks.quotation_generations.root' => $root.'/fixtures', 'filesystems.disks.quotation_execution.root' => $root.'/execution']);
DB::purge('mysql');
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, 'Isolated concurrency test failed: '.$error::class.PHP_EOL);
    exit(1);
});
$service = app(QuotationGenerationService::class);
$connectionId = DB::selectOne('SELECT CONNECTION_ID() id')->id;
if ($mode !== 'parent') {
    $barrier = getenv('QUOTATION_TEST_BARRIER');
    $workerId = $argv[2];
    if (! $barrier || ! str_starts_with($barrier, $root.'/barrier-') || ! preg_match('/^[0-9]+$/', $workerId)) {
        throw new RuntimeException('Invalid test barrier.');
    }
    file_put_contents($barrier.'.'.$workerId.'.ready', 'ready');
    $until = microtime(true) + 15;
    while (! is_file($barrier.'.go')) {
        if (microtime(true) > $until) {
            throw new RuntimeException('Barrier timeout.');
        } usleep(10000);
    }
    try {
        if ($mode === 'reserve') {
            $result = $service->start(User::findOrFail($argv[3]), $argv[4], 1, $argv[5]);
            $output = ['accepted' => $result->uuid];
        } elseif ($mode === 'claim') {
            $claimed = $service->claim($argv[3]);
            $output = ['token' => $claimed?->attempt_token];
        } elseif ($mode === 'reconcile') {
            $service->reconcile($argv[3]);
            $output = ['reconciled' => true];
        } else {
            throw new RuntimeException('Unknown test operation.');
        }
    } catch (QuotationGenerationException $error) {
        $output = ['rejected' => $error->reason];
    }
    echo json_encode(['connection' => $connectionId, ...$output], JSON_THROW_ON_ERROR);
    exit(0);
}

try {
    if ($app->make(Kernel::class)->call('migrate', ['--force' => true]) !== 0) {
        throw new RuntimeException('Test migrations failed.');
    }
    $assert = static function (bool $ok, string $message): void {
        if (! $ok) {
            throw new RuntimeException($message);
        }
    };
    $pairNumber = 0;
    $pair = function (array $one, array $two) use ($root, $database, $port, &$pairNumber, $assert): array {
        $barrier = $root.'/barrier-'.getmypid().'-'.(++$pairNumber);
        $env = ['QUOTATION_TEST_DATABASE' => $database, 'QUOTATION_TEST_PORT' => (string) $port, 'QUOTATION_TEST_BARRIER' => $barrier];
        $processes = [];
        foreach ([$one, $two] as $index => $args) {
            $process = new Process([PHP_BINARY, '-d', 'extension=soap', __FILE__, $args[0], (string) $index, ...array_slice($args, 1)], dirname(__DIR__), $env);
            $process->setTimeout(30);
            $process->start();
            $processes[] = $process;
        }
        $until = microtime(true) + 15;
        while (! is_file($barrier.'.0.ready') || ! is_file($barrier.'.1.ready')) {
            if (microtime(true) > $until) {
                throw new RuntimeException('Workers did not reach independent connections.');
            }
            usleep(10000);
        }
        file_put_contents($barrier.'.go', 'go');
        $results = [];
        foreach ($processes as $process) {
            $process->wait();
            $assert($process->isSuccessful(), 'Concurrent worker failed.');
            $results[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        }
        $assert($results[0]['connection'] !== $results[1]['connection'], 'Connections must be independent.');

        return $results;
    };
    $newUser = static fn () => User::factory()->create(['name' => 'Concorrência Fictícia', 'email' => 'test-'.Str::uuid().'@example.test']);
    $newDraft = static function ($user) {
        $party = ['name' => 'Fictício', 'document' => '', 'email' => '', 'phone' => '', 'address' => ''];

        return QuotationDraft::create(['uuid' => (string) Str::uuid(), 'user_id' => $user->id, 'import_key' => (string) Str::uuid(),
            'import_hash' => str_repeat('a', 64), 'revision' => 1, 'schema_version' => 1, 'expires_at' => now()->addDay(),
            'payload' => ['number' => 'TEST', 'issue_date' => '2026-10-22', 'valid_until' => '2026-11-22', 'issuer' => $party, 'customer' => $party,
                'items' => [['description' => 'Serviço fictício', 'quantity' => '1', 'unit_price_cents' => 100]], 'discount_type' => 'fixed',
                'discount_fixed_cents' => 0, 'discount_percent' => '0', 'conditions' => '', 'notes' => '', 'template_id' => 'modern']]);
    };
    $cases = [];
    $user = $newUser();
    $a = $newDraft($user);
    $b = $newDraft($user);
    $results = $pair(['reserve', (string) $user->id, $a->uuid, (string) Str::uuid()], ['reserve', (string) $user->id, $b->uuid, (string) Str::uuid()]);
    $assert(count(array_filter($results, fn ($r) => isset($r['accepted']))) === 1, 'Exactly one competing document can reserve.');
    $assert(DB::table('quotation_daily_quotas')->where('user_id', $user->id)->value('reserved') === 1, 'Persistent reservation must equal one.');
    $cases['different_drafts_last_unit'] = $results;
    foreach ([true, false] as $sameKey) {
        $user = $newUser();
        $draft = $newDraft($user);
        $key = (string) Str::uuid();
        $results = $pair(['reserve', (string) $user->id, $draft->uuid, $key], ['reserve', (string) $user->id, $draft->uuid, $sameKey ? $key : (string) Str::uuid()]);
        $assert(isset($results[0]['accepted'], $results[1]['accepted']) && $results[0]['accepted'] === $results[1]['accepted'], 'Concurrent idempotency must return one issuance.');
        $assert(QuotationGeneration::where('user_id', $user->id)->count() === 1, 'One generation only.');
        $assert(DB::table('jobs')->where('payload', 'like', '%'.$results[0]['accepted'].'%')->count() === 1, 'One atomic queue publication only.');
        $cases[$sameKey ? 'same_key' : 'same_revision_different_keys'] = $results;
    }
    $uuid = $results[0]['accepted'];
    $claims = $pair(['claim', $uuid], ['claim', $uuid]);
    $assert(count(array_filter($claims, fn ($r) => $r['token'] !== null)) === 1, 'One live attempt token only.');
    $cases['duplicate_delivery_claim'] = $claims;
    $old = QuotationGeneration::where('uuid', $uuid)->firstOrFail();
    QuotationGeneration::whereKey($old->id)->update(['lease_expires_at' => now()->subSecond()]);
    $jobsBefore = DB::table('jobs')->count();
    $cases['concurrent_reconciliation'] = $pair(['reconcile', $uuid], ['reconcile', $uuid]);
    $assert(DB::table('jobs')->count() === $jobsBefore + 1, 'One replacement job only.');
    $fresh = $service->claim($uuid);
    $assert($fresh && $fresh->attempt_token !== $old->attempt_token, 'New worker must have a new fencing token.');
    $pdf = app(QuotationExecutionRunner::class)->render($fresh);
    $assert(! $service->complete($old, $pdf), 'Old worker must be fenced.');
    $assert($service->complete($fresh, $pdf), 'Current worker must publish.');
    $assert(! $service->complete($fresh, $pdf), 'Repeated finalization must not consume twice.');
    $quota = DB::table('quotation_daily_quotas')->where('id', $fresh->quota_id)->first();
    $assert((int) $quota->reserved === 0 && (int) $quota->consumed === 1, 'Exactly one terminal consumption.');
    $processing = Processing::where('uuid', $uuid)->firstOrFail();
    $assert($processing->status === ProcessingStatus::Completed && $processing->output()->count() === 1, 'Exactly one downloadable PDF.');
    $assert(Storage::disk('quotation_generations')->exists($processing->output()->first()->storage_path), 'A duplicate finalizer must not delete the published PDF.');
    $service->purge($processing);
    $assert((int) DB::table('quotation_daily_quotas')->where('id', $fresh->quota_id)->value('consumed') === 1, 'Purge cannot refund a consumed unit.');
    $report = ['server' => $server['version'], 'driver' => 'mysql', 'independent_connection_pairs' => count($cases), 'cases' => $cases,
        'fencing_and_finalization' => 'passed', 'purge_does_not_refund' => 'passed'];
    file_put_contents($root.'/concurrency-report.json', json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    echo json_encode(['server' => $server['version'], 'independent_connection_pairs' => count($cases), 'result' => 'passed'], JSON_THROW_ON_ERROR).PHP_EOL;
} finally {
    DB::disconnect('mysql');
    // The daemon datadir and database name were checked before any DDL; this is never the operational server.
    $pdo->exec('DROP DATABASE `'.$database.'`');
}
