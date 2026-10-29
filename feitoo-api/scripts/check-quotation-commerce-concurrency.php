<?php

declare(strict_types=1);

/** Disposable MariaDB/MySQL ONLY. The running daemon's datadir must be inside this test's private workspace. */
require dirname(__DIR__).'/vendor/autoload.php';

use App\Exceptions\QuotationGenerationException;
use App\Models\Processing;
use App\Models\QuotationCreditOrder;
use App\Models\QuotationDraft;
use App\Models\QuotationGeneration;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Quotations\QuotationGenerationService;
use App\Services\Quotations\QuotationOrderService;
use App\Services\Quotations\QuotationPdf;
use App\Services\Quotations\QuotationStripeGateway;
use App\Services\StripeWebhookService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Stripe\Checkout\Session;
use Stripe\Event;
use Symfony\Component\Process\Process;

$mode = $argv[1] ?? 'parent';
$base = str_replace('\\', '/', dirname(__DIR__));
$root = $base.'/storage/app/private/quotation-validation/mysql-phase5-20261024';
$port = (int) (getenv('QUOTATION_TEST_PORT') ?: 33078);
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
    'monetization.stripe.mode' => 'test', 'monetization.stripe.secret_key' => 'sk_test_fixture', 'monetization.stripe.webhook_secret' => 'whsec_fixture', 'quotations.credit_price_id' => 'price_fixture', 'quotations.enabled' => true, 'quotations.purchases_enabled' => true, 'quotations.sandbox' => 'php',
    'filesystems.disks.quotation_generations.root' => $root.'/fixtures', 'filesystems.disks.quotation_execution.root' => $root.'/execution']);
DB::purge('mysql');
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, 'Isolated concurrency test failed: '.$error::class.' at line '.$error->getLine().($error::class === RuntimeException::class ? ' '.$error->getMessage() : '').PHP_EOL);
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
            $result = $service->start(User::findOrFail($argv[3]), $argv[4], 1, $argv[5], $argv[6] ?? null);
            $output = ['accepted' => $result->uuid];
        } elseif ($mode === 'checkout') {
            $gateway = new class extends QuotationStripeGateway
            {
                public function available(): bool
                {
                    return true;
                }

                public function checkout(QuotationCreditOrder $o): Session
                {
                    return Session::constructFrom(['id' => 'cs_test_'.$o->uuid, 'livemode' => false,
                        'mode' => 'payment', 'client_reference_id' => $o->uuid, 'amount_total' => 990, 'currency' => 'brl',
                        'metadata' => ['quotation_order' => $o->uuid, 'operation' => 'quotation_credits'], 'url' => 'https://checkout.stripe.com/c/pay/fixture']);
                }
            };
            $order = (new QuotationOrderService($gateway))->checkout(User::findOrFail($argv[3]), $argv[4]);
            $output = ['order' => $order->uuid, 'session' => $order->session_id];
        } elseif ($mode === 'webhook') {
            $order = QuotationCreditOrder::where('uuid', $argv[3])->firstOrFail();
            $type = $argv[4];
            $object = $type === 'charge.refunded' ? ['id' => 'ch_fixture', 'livemode' => false,
                'payment_intent' => $order->payment_intent_id, 'amount' => 990, 'amount_refunded' => 990, 'currency' => 'brl'] :
                ['id' => $order->session_id, 'livemode' => false, 'mode' => 'payment', 'status' => 'complete',
                    'payment_status' => 'paid', 'payment_intent' => 'pi_'.$order->uuid, 'client_reference_id' => $order->uuid,
                    'amount_total' => 990, 'currency' => 'brl', 'metadata' => ['quotation_order' => $order->uuid, 'operation' => 'quotation_credits']];
            app(StripeWebhookService::class)->handle(Event::constructFrom(['id' => $argv[5], 'type' => $type,
                'livemode' => false, 'data' => ['object' => $object]]));
            $output = ['webhook' => true];
        } elseif ($mode === 'complete') {
            $claim = QuotationGeneration::where('uuid', $argv[3])->firstOrFail();
            $output = ['completed' => $service->complete($claim, new QuotationPdf('%PDF-fixture', 1, hash('sha256', '%PDF-fixture')))];
        } elseif ($mode === 'subscription') {
            DB::transaction(function () use ($argv) {
                User::whereKey($argv[3])->lockForUpdate()->firstOrFail();
                $sub = Subscription::where('user_id', $argv[3])->lockForUpdate()->firstOrFail();
                $sub->update($argv[4] === 'cancel' ? ['status' => 'canceled'] : ['current_period_start' => now(), 'current_period_end' => now()->addMonth()]);
            }, 5);
            $output = ['subscription' => true];
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
    $newOrder = static function ($user, bool $paid = false) {
        $o = QuotationCreditOrder::create(['uuid' => (string) Str::uuid(), 'user_id' => $user->id,
            'idempotency_key' => (string) Str::uuid(), 'package_code' => 'quotation_5', 'amount' => 990, 'currency' => 'brl',
            'credits' => 5, 'price_id' => 'price_fixture', 'expires_at' => now()->addHour(), 'session_id' => 'cs_test_'.Str::random(20)]);
        if ($paid) {
            $o->update(['status' => 'paid', 'paid_at' => now(), 'payment_intent_id' => 'pi_'.$o->uuid]);
            DB::table('quotation_credit_grants')->insert(['user_id' => $user->id, 'order_id' => $o->id, 'granted' => 5, 'consumed' => 4,
                'created_at' => now(), 'updated_at' => now()]);
        }

        return $o;
    };
    $newSubscription = static fn ($u) => Subscription::create(['user_id' => $u->id, 'provider_customer_id' => 'cus_'.$u->id,
        'provider_price_id' => 'price_pro', 'provider_subscription_id' => 'sub_'.$u->id, 'status' => 'active',
        'current_period_start' => now()->subDay(), 'current_period_end' => now()->addDays(29)]);
    $cases = [];
    $user = $newUser();
    $order = $newOrder($user, true);
    $a = $newDraft($user);
    $b = $newDraft($user);
    $results = $pair(['reserve', (string) $user->id, $a->uuid, (string) Str::uuid(), 'credit'], ['reserve', (string) $user->id, $b->uuid, (string) Str::uuid(), 'credit']);
    $assert(count(array_filter($results, fn ($r) => isset($r['accepted']))) === 1, 'Last credit must have exactly one reservation.');
    $assert((int) DB::table('quotation_credit_grants')->where('order_id', $order->id)->value('reserved') === 1, 'Credit reservation cannot be duplicated.');
    $cases['last_credit'] = $results;
    $uuid = QuotationGeneration::where('user_id', $user->id)->value('uuid');
    $claim = $service->claim($uuid);
    $cases['refund_vs_finalize'] = $pair(['webhook', $order->uuid, 'charge.refunded', 'evt_'.Str::random(20)], ['complete', $uuid]);
    $grant = DB::table('quotation_credit_grants')->where('order_id', $order->id)->first();
    $assert($grant->reserved === 0 && $grant->consumed + $grant->revoked === 5, 'Refund and completion must conserve the original grant.');
    $assert(in_array($grant->consumed, [4, 5], true), 'Only the already published result may be consumed.');
    $assert($order->fresh()->status === 'refunded', 'Refund must be retained.');

    $user = $newUser();
    $order = $newOrder($user, true);
    $draft = $newDraft($user);
    $results = $pair(['reserve', (string) $user->id, $draft->uuid, (string) Str::uuid(), 'credit'],
        ['webhook', $order->uuid, 'charge.refunded', 'evt_'.Str::random(20)]);
    $pending = Processing::where('user_id', $user->id)->first();
    if ($pending) {
        $service->purge($pending);
    }
    $grant = DB::table('quotation_credit_grants')->where('order_id', $order->id)->first();
    $assert($grant->reserved === 0 && $grant->consumed === 4 && $grant->revoked === 1, 'Refund racing a reservation must preserve consumed history and revoke the remaining unit.');
    $cases['refund_vs_reservation'] = $results;

    $user = $newUser();
    $key = (string) Str::uuid();
    $results = $pair(['checkout', (string) $user->id, $key], ['checkout', (string) $user->id, $key]);
    $assert($results[0]['order'] === $results[1]['order'] && $results[0]['session'] === $results[1]['session'], 'Checkout key must resolve to one local order and one provider key.');
    $assert(QuotationCreditOrder::where('user_id', $user->id)->count() === 1, 'One order only.');
    $cases['same_checkout_key'] = $results;
    foreach ([true, false] as $sameEvent) {
        $user = $newUser();
        $order = $newOrder($user);
        $event = 'evt_'.Str::random(20);
        $results = $pair(['webhook', $order->uuid, 'checkout.session.completed', $event],
            ['webhook', $order->uuid, $sameEvent ? 'checkout.session.completed' : 'checkout.session.async_payment_succeeded', $sameEvent ? $event : 'evt_'.Str::random(20)]);
        $grants = DB::table('quotation_credit_grants')->where('order_id', $order->id)->get();
        $assert($grants->count() === 1 && $grants[0]->granted === 5, 'Multiple confirmations must grant one package only.');
        $cases[$sameEvent ? 'same_webhook' : 'distinct_events_same_session'] = $results;
    }
    foreach (['renew', 'cancel'] as $action) {
        $user = $newUser();
        $sub = $newSubscription($user);
        $draft = $newDraft($user);
        $results = $pair(['reserve', (string) $user->id, $draft->uuid, (string) Str::uuid(), 'pro'], ['subscription', (string) $user->id, $action]);
        $generation = QuotationGeneration::where('user_id', $user->id)->first();
        if ($generation) {
            $claim = $service->claim($generation->uuid);
            $assert($service->complete($claim, new QuotationPdf('%PDF-test', 1, hash('sha256', '%PDF-test'))), 'Previously authorized reservation must complete.');
            $assert((int) DB::table('quotation_pro_buckets')->where('id', $generation->pro_bucket_id)->value('consumed') === 1, 'Consume only the original period.');
        } else {
            $assert($action === 'cancel', 'Renewal always provides a valid period.');
        }
        $assert((int) DB::table('quotation_pro_buckets')->where('user_id', $user->id)->sum('reserved') === 0, 'No stranded period reservation.');
        $cases['subscription_'.$action] = $results;
    }
    foreach ([['free', 'credit'], ['pro', 'credit'], ['free', 'pro']] as [$one, $two]) {
        $user = $newUser();
        $order = $newOrder($user, true);
        if ($one === 'pro' || $two === 'pro') {
            $newSubscription($user);
        }
        $a = $newDraft($user);
        $b = $newDraft($user);
        $results = $pair(['reserve', (string) $user->id, $a->uuid, (string) Str::uuid(), $one], ['reserve', (string) $user->id, $b->uuid, (string) Str::uuid(), $two]);
        $assert(count(array_filter($results, fn ($r) => isset($r['accepted']))) === 1, 'One active issuance per account across all sources.');
        $cases[$one.'_vs_'.$two] = $results;
    }
    $user = $newUser();
    $newOrder($user, true);
    $draft = $newDraft($user);
    $key = (string) Str::uuid();
    $results = $pair(['reserve', (string) $user->id, $draft->uuid, $key, 'credit'], ['reserve', (string) $user->id, $draft->uuid, $key, 'credit']);
    $assert($results[0]['accepted'] === $results[1]['accepted'], 'Timeout replay must retain the same paid issuance.');
    $cases['paid_replay'] = $results;
    $uuid = $results[0]['accepted'];
    $claims = $pair(['claim', $uuid], ['claim', $uuid]);
    $assert(count(array_filter($claims, fn ($r) => $r['token'] !== null)) === 1, 'Job retry cannot claim twice.');
    $cases['paid_duplicate_claim'] = $claims;
    $old = QuotationGeneration::where('uuid', $uuid)->firstOrFail();
    config(['quotations.max_attempts' => 1]);
    $service->failAttempt($old, 'render_failed');
    $assert((int) DB::table('quotation_credit_grants')->where('id', $old->credit_grant_id)->value('reserved') === 0, 'Terminal failure must release the credit.');
    $report = ['server' => $server['version'], 'driver' => 'mysql', 'independent_connection_pairs' => count($cases), 'cases' => $cases,
        'stripe' => 'contract fake; no remote requests', 'mysql8' => ! str_contains($server['version'], 'MariaDB') && str_starts_with($server['version'], '8.')];
    file_put_contents($root.'/commerce-concurrency-report.json', json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    echo json_encode(['server' => $server['version'], 'independent_connection_pairs' => count($cases), 'result' => 'passed'], JSON_THROW_ON_ERROR).PHP_EOL;
} finally {
    DB::disconnect('mysql');
    $pdo->exec('DROP DATABASE `'.$database.'`');
}
