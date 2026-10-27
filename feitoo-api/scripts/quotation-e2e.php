<?php

declare(strict_types=1);
use App\Models\Processing;
use App\Models\QuotationGeneration;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

// Local fixture/router for the browser E2E only; never loads the operational .env or database.
$root = getenv('QUOTATION_E2E_ROOT');
$validation = str_replace('\\', '/', dirname(__DIR__).'/storage/app/private/quotation-validation');
$root = is_string($root) ? str_replace('\\', '/', $root) : '';
if (! str_starts_with($root, $validation.'/e2e-phase4-') || ! preg_match('/\/e2e-phase4-[a-f0-9]{12}$/D', $root)
    || ! is_file($root.'/isolated.marker') || getenv('DB_CONNECTION') !== 'sqlite' || getenv('DB_DATABASE') !== $root.'/database.sqlite') {
    exit(2);
}
require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
if (preg_match('/^[A-Za-z]:\//', $root)) {
    $app->addAbsoluteCachePathPrefix(substr($root, 0, 2));
}
$app->useEnvironmentPath($root)->loadEnvironmentFrom('.e2e-no-env');
$app->useStoragePath($root.'/runtime');
if (PHP_SAPI === 'cli-server') {
    $app->handleRequest(Request::capture());

    return;
}
try {
    $kernel = $app->make(Kernel::class);
    $kernel->bootstrap();
    if (DB::getDriverName() !== 'sqlite' || config('database.connections.sqlite.database') !== $root.'/database.sqlite') {
        throw new RuntimeException('Unsafe test database.');
    }
    $mode = $argv[1] ?? '';
    if ($mode === 'setup') {
        if ($kernel->call('migrate', ['--force' => true]) !== 0) {
            throw new RuntimeException('Test migration failed.');
        }
        User::factory()->create(['name' => 'Conta E2E Fictícia', 'email' => 'quotation-e2e@example.test', 'password' => 'a-strong-password']);
        echo json_encode(['setup' => true]);
    } elseif ($mode === 'work') {
        $job = Queue::connection('quotations')->pop('quotations');
        if ($job) {
            $job->fire();
            $job->delete();
        }
        echo json_encode(['worked' => $job !== null]);
    } elseif ($mode === 'inspect') {
        $generation = QuotationGeneration::latest('id')->first();
        $processing = $generation ? Processing::find($generation->processing_id) : null;
        $quota = DB::table('quotation_daily_quotas')->first();
        $snapshot = $generation?->snapshot_path ? json_decode(Crypt::decryptString(Storage::disk('quotation_generations')->get($generation->snapshot_path)), true) : null;
        echo json_encode(['generations' => QuotationGeneration::count(), 'jobs' => DB::table('jobs')->count(),
            'reserved' => (int) ($quota->reserved ?? 0), 'consumed' => (int) ($quota->consumed ?? 0),
            'status' => $processing?->status->value, 'download_seconds' => $processing?->completed_at ? $processing->expires_at->timestamp - $processing->completed_at->timestamp : null,
            'immutable_issuer' => ($snapshot['document']['issuer']['name'] ?? null) === 'Prestador E2E Fictício',
            'copied_logo' => $generation?->logo_path !== null]);
    } elseif ($mode === 'expire') {
        $processing = Processing::latest('id')->firstOrFail();
        $processing->update(['expires_at' => now()->subSecond()]);
        $processing->files()->update(['expires_at' => now()->subSecond()]);
        echo json_encode(['expired' => true]);
    } else {
        throw new RuntimeException('Unknown fixture operation.');
    }
} catch (Throwable $error) {
    // Safe test diagnostics only; never dump snapshots, exception traces or SQL bindings.
    fwrite(STDERR, 'Quotation E2E fixture failed: '.$error::class.PHP_EOL);
    exit(1);
}
