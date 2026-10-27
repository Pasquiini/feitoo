<?php

declare(strict_types=1);
use App\Domain\Quotations\NormalizedQuotationLogo;
use App\Domain\Quotations\Quotation;
use App\Services\Quotations\QuotationPdfException;
use App\Services\Quotations\QuotationPdfRenderer;
use Illuminate\Config\Repository;
use Illuminate\Encryption\Encrypter;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use Illuminate\View\ViewServiceProvider;

// Private subprocess entrypoint. No HTTP, queue payload, arbitrary input paths or database access.
$workspace = getenv('QUOTATION_WORKSPACE');
$workspace = is_string($workspace) ? str_replace('\\', '/', $workspace) : null;
if (! is_string($workspace) || ! preg_match('~/([0-9a-f-]{36})/([0-9a-f-]{36})/?$~D', $workspace)
    || ! is_dir($workspace) || is_link($workspace) || ! is_file($workspace.'/snapshot.enc')) {
    exit(2);
}
try {
    require dirname(__DIR__).'/vendor/autoload.php';
    umask(0077);
    foreach (['runtime/framework/views'] as $part) {
        if (! is_dir($workspace.'/'.$part)) {
            mkdir($workspace.'/'.$part, 0700, true);
        }
    }
    // Only the services used by the real renderer. Never load .env, cached config,
    // application/package providers, database, queues, sessions or production logging.
    $app = new Application(dirname(__DIR__));
    $app->instance('env', 'production');
    $app->useStoragePath($workspace.'/runtime');
    $app->instance('config', new Repository(['view' => [
        'paths' => [dirname(__DIR__).'/resources/views'], 'compiled' => $workspace.'/runtime/framework/views',
    ]]));
    $app->instance('files', new Filesystem);
    $translator = new Translator(new ArrayLoader, 'en');
    $app->instance('translator', $translator);
    $app->instance('validator', new Factory($translator, $app));
    $app->register(ViewServiceProvider::class);
    Facade::setFacadeApplication($app);
    if (getenv('QUOTATION_SANDBOX_CHECK') === '1') {
        require __DIR__.'/probe-quotation-sandbox.php';
    }
    $key = base64_decode(substr((string) getenv('APP_KEY'), 7), true);
    $cipher = new Encrypter($key, (string) getenv('APP_CIPHER'));
    $snapshot = json_decode($cipher->decryptString(file_get_contents($workspace.'/snapshot.enc')), true, flags: JSON_THROW_ON_ERROR);
    $quotation = Quotation::fromArray($snapshot['document']);
    if ($snapshot['schema_version'] !== 1 || $snapshot['totals'] !== (array) $quotation->totals) {
        throw new QuotationPdfException('snapshot_invalid');
    }
    $logo = is_file($workspace.'/logo.png') ? NormalizedQuotationLogo::fromBytes(file_get_contents($workspace.'/logo.png')) : null;
    $pdf = (new QuotationPdfRenderer)->render($quotation, $logo);
    if (file_put_contents($workspace.'/result.pdf', $pdf->bytes) !== strlen($pdf->bytes)) {
        exit(2);
    }
    file_put_contents($workspace.'/result.json', json_encode(['pages' => $pdf->pages, 'sha256' => $pdf->sha256], JSON_THROW_ON_ERROR));
    exit(0);
} catch (Throwable $error) {
    $code = $error instanceof QuotationPdfException ? $error->reason : 'render_failed';
    file_put_contents($workspace.'/failure.json', json_encode(['code' => $code]));
    exit(2);
}
