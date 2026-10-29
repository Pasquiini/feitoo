<?php

declare(strict_types=1);

// Read-only operational preflight. Never prints configuration secrets, SQL, user data or exception messages.
require dirname(__DIR__).'/vendor/autoload.php';

use App\Services\Quotations\QuotationExecutionRunner;
use App\Services\Quotations\QuotationStripeGateway;
use App\Services\StripeEnvironment;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$result = [];
try {
    $app = require dirname(__DIR__).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    $result['php'] = PHP_VERSION;
    $result['os'] = PHP_OS_FAMILY;
    $result['extensions'] = array_combine(
        $extensions = ['gd', 'dom', 'mbstring', 'fileinfo', 'openssl', 'curl', 'xml', 'zip', 'pdo_mysql', 'pcntl', 'posix'],
        array_map('extension_loaded', $extensions),
    );
    $result['generation_enabled'] = (bool) config('quotations.enabled');
    $result['purchases_enabled'] = (bool) config('quotations.purchases_enabled');
    $result['stripe_mode'] = config('monetization.stripe.mode');
    $result['stripe_configured'] = app(StripeEnvironment::class)->available();
    $result['credit_price_configured'] = str_starts_with((string) config('quotations.credit_price_id'), 'price_');
    $result['sandbox_available'] = config('quotations.sandbox') === 'bubblewrap' && app(QuotationExecutionRunner::class)->available();
    $queue = config('queue.connections.quotations');
    $result['queue_transactional'] = ($queue['driver'] ?? '') === 'database' && ($queue['table'] ?? '') === 'jobs'
        && ($queue['connection'] ?? config('database.default')) === config('database.default') && ! ($queue['after_commit'] ?? true)
        && ($queue['retry_after'] ?? 0) > config('quotations.lease_seconds') && config('quotations.lease_seconds') > 60;
    $result['database_driver'] = DB::getDriverName();
    $result['database_version'] = $result['database_driver'] === 'mysql' ? DB::selectOne('SELECT VERSION() AS version')->version : null;
    foreach (['jobs', 'quotation_drafts', 'quotation_generations', 'quotation_generation_requests', 'quotation_daily_quotas',
        'quotation_credit_orders', 'quotation_credit_grants', 'quotation_pro_buckets'] as $table) {
        $result['tables'][$table] = Schema::hasTable($table);
    }
    $result['transactional_tables'] = false;
    if ($result['database_driver'] === 'mysql') {
        $result['table_engines'] = DB::table('information_schema.TABLES')->whereRaw('TABLE_SCHEMA = DATABASE()')
            ->whereIn('TABLE_NAME', array_keys($result['tables']))->selectRaw('TABLE_NAME AS table_name, ENGINE AS engine')->get()
            ->mapWithKeys(fn ($table) => [$table->table_name => $table->engine])->all();
        $result['transactional_tables'] = count($result['table_engines']) === count($result['tables'])
            && count(array_filter($result['table_engines'], fn ($engine) => strcasecmp($engine, 'InnoDB') !== 0)) === 0;
        $result['sql_mode'] = DB::selectOne('SELECT @@SESSION.sql_mode AS mode')->mode;
        $result['isolation'] = DB::selectOne(str_contains($result['database_version'], 'MariaDB')
            ? 'SELECT @@SESSION.tx_isolation AS level' : 'SELECT @@SESSION.transaction_isolation AS level')->level;
    }
    $result['stripe_mode_column'] = Schema::hasColumn('quotation_credit_orders', 'stripe_mode');
    foreach (['quotation_drafts', 'quotation_generations', 'quotation_execution'] as $disk) {
        $configuration = config('filesystems.disks.'.$disk);
        $root = $configuration['root'] ?? '';
        $result['private_storage'][$disk] = ($configuration['visibility'] ?? '') === 'private'
            && ! ($configuration['serve'] ?? true) && is_writable(is_dir($root) ? $root : dirname($root));
    }
    if (in_array('--stripe', $argv, true)) {
        if (! $result['stripe_configured'] || ! $result['credit_price_configured']) {
            throw new RuntimeException('Unavailable configuration');
        }
        app(QuotationStripeGateway::class)->verifyPrice(config('quotations.credit_price_id'));
        $result['stripe_price_product_verified'] = true;
    }
    $result['ok'] = $result['database_driver'] === 'mysql' && $result['transactional_tables'] && $result['stripe_mode_column']
        && ! in_array(false, $result['tables'], true) && ! in_array(false, $result['extensions'], true)
        && ! in_array(false, $result['private_storage'], true)
        && $result['sandbox_available'] && $result['queue_transactional'] && $result['stripe_configured'] && $result['credit_price_configured'];
} catch (Throwable $error) {
    $result['ok'] = false;
    $result['error_type'] = $error::class;
}
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
exit(($result['ok'] ?? false) ? 0 : 1);
