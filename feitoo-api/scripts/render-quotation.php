<?php

declare(strict_types=1);
use App\Domain\Quotations\NormalizedQuotationLogo;
use App\Domain\Quotations\Quotation;
use App\Services\Quotations\QuotationPdfException;
use App\Services\Quotations\QuotationPdfRenderer;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Crypt;

// Private subprocess entrypoint. No HTTP, queue payload, arbitrary input paths or database access.
$workspace = getenv('QUOTATION_WORKSPACE');
$workspace = is_string($workspace) ? str_replace('\\', '/', $workspace) : null;
if (! is_string($workspace) || ! preg_match('~/([0-9a-f-]{36})/([0-9a-f-]{36})/?$~D', $workspace)
    || ! is_dir($workspace) || is_link($workspace) || ! is_file($workspace.'/snapshot.enc')) {
    exit(2);
}
try {
    require dirname(__DIR__).'/vendor/autoload.php';
    foreach (['bootstrap', 'runtime/framework/views', 'runtime/framework/cache', 'runtime/logs'] as $part) {
        if (! is_dir($workspace.'/'.$part)) {
            mkdir($workspace.'/'.$part, 0700, true);
        }
    }
    $app = require dirname(__DIR__).'/bootstrap/app.php';
    if (preg_match('/^[A-Za-z]:\//', $workspace)) {
        $app->addAbsoluteCachePathPrefix(substr($workspace, 0, 2));
    }
    $app->useEnvironmentPath($workspace)->loadEnvironmentFrom('.renderer-no-env');
    $app->useStoragePath($workspace.'/runtime');
    $app->make(Kernel::class)->bootstrap();
    config(['view.compiled' => $workspace.'/runtime/framework/views']);
    $snapshot = json_decode(Crypt::decryptString(file_get_contents($workspace.'/snapshot.enc')), true, flags: JSON_THROW_ON_ERROR);
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
