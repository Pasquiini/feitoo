<?php

use App\Enums\ProcessingStatus;
use App\Exceptions\BillingException;
use App\Models\Processing;
use App\Models\User;
use App\Services\EntitlementService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

// Local integration test with two real MySQL connections; no fiscal files or jobs.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('local', 'testing') || DB::getDriverName() !== 'mysql') {
    throw new RuntimeException('This test requires local MySQL.');
}
config(['monetization.xml_demo.daily' => 5, 'monetization.xml_demo.per_processing' => 20]);
if (($argv[1] ?? '') === 'worker') {
    try {
        DB::transaction(function () use ($argv): void {
            $processing = Processing::whereKey((int) $argv[2])->lockForUpdate()->firstOrFail();
            app(EntitlementService::class)->accept($processing, User::findOrFail($processing->user_id));
            $processing->update(['status' => ProcessingStatus::Queued]);
            if (($argv[3] ?? '') !== '') {
                file_put_contents($argv[3], 'locked');
                usleep(1200000);
            }
        }, 5);
        echo 'accepted';
    } catch (BillingException $exception) {
        echo $exception->reason;
    }
    exit;
}
$owner = User::factory()->create(['email' => 'quota-'.Str::uuid().'@example.invalid']);
$ids = [];
$marker = storage_path('app/private/quota-test-'.Str::uuid());
try {
    foreach ([1, 2] as $number) {
        $ids[] = Processing::create(['user_id' => $owner->id, 'tool' => 'xml-to-excel',
            'status' => ProcessingStatus::Pending, 'files_count' => 5, 'expires_at' => now()->addHour()])->id;
    }
    $first = new Process([PHP_BINARY, __FILE__, 'worker', (string) $ids[0], $marker]);
    $second = new Process([PHP_BINARY, __FILE__, 'worker', (string) $ids[1], '']);
    $first->start();
    $deadline = microtime(true) + 5;
    while (! is_file($marker) && microtime(true) < $deadline && $first->isRunning()) {
        usleep(20000);
    }
    if (! is_file($marker)) {
        throw new RuntimeException('First transaction did not acquire its lock.');
    }
    $second->start();
    $first->wait();
    $second->wait();
    $used = app(EntitlementService::class)->usage($owner)['limits']['used_today'];
    if ($first->getOutput() !== 'accepted' || $second->getOutput() !== 'usage_limit_exceeded' || $used !== 5) {
        throw new RuntimeException('Concurrent quota invariant failed.');
    }
    echo json_encode(['accepted' => 1, 'rejected' => 1, 'used_today' => $used, 'daily_limit' => 5], JSON_THROW_ON_ERROR).PHP_EOL;
} finally {
    if (isset($first) && $first->isRunning()) {
        $first->stop();
    }
    if (isset($second) && $second->isRunning()) {
        $second->stop();
    }
    Processing::whereIn('id', $ids)->where('user_id', $owner->id)->delete();
    $owner->delete();
    if (is_file($marker)) {
        unlink($marker);
    }
}
