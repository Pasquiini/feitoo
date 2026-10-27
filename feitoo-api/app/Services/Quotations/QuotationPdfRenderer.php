<?php

declare(strict_types=1);

namespace App\Services\Quotations;

use App\Domain\Quotations\NormalizedQuotationLogo;
use App\Domain\Quotations\Quotation;
use App\Domain\Quotations\QuotationLimits;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\View;
use RuntimeException;
use Throwable;

final class QuotationPdfRenderer
{
    public function render(Quotation $quotation, ?NormalizedQuotationLogo $logo = null): QuotationPdf
    {
        $workspace = storage_path('app/private/quotation-temporary/'.bin2hex(random_bytes(16)));
        $files = new Filesystem;
        $dompdf = null;
        try {
            if (! $files->makeDirectory($workspace, 0700, true)) {
                throw new RuntimeException;
            }
            $fontDirectory = base_path('vendor/dompdf/dompdf/lib/fonts');
            $options = new Options;
            $options->setIsRemoteEnabled(false);
            $options->setAllowedRemoteHosts([]);
            $options->setIsPhpEnabled(false);
            $options->setIsJavascriptEnabled(false);
            $options->setAllowedProtocols(['file://' => ['rules' => [static function (string $uri) use ($workspace, $fontDirectory): array {
                $resolved = realpath(str_replace('file://', '', $uri));
                $fonts = realpath($fontDirectory);
                $allowed = $resolved !== false && ($resolved === realpath($workspace.'/logo.png')
                    || ($fonts !== false && str_starts_with($resolved, $fonts.DIRECTORY_SEPARATOR)));

                return [$allowed, $allowed ? null : 'Resource not allowed.'];
            }]]]);
            $options->setChroot([$workspace, $fontDirectory]);
            $options->setTempDir($workspace);
            $options->setFontDir($fontDirectory);
            $options->setFontCache($workspace);
            $options->setLogOutputFile('');
            $options->setDefaultFont('DejaVu Sans');
            $options->setDefaultPaperSize('a4');
            $options->setImageByteSizeLimit(32 * 1048576);
            $options->setArtifactPathValidation(static function (?string $path) use ($workspace): bool {
                return $path === null || str_starts_with(str_replace('\\', '/', $path), str_replace('\\', '/', $workspace).'/');
            });
            $dompdf = new Dompdf($options);
            $dompdf->setPaper('A4', 'portrait');
            $metrics = $dompdf->getFontMetrics();
            $font = $metrics->getFont('DejaVu Sans', 'normal');
            $boldFont = $metrics->getFont('DejaVu Sans', 'bold');
            $texts = array_merge([$quotation->number, $quotation->conditions, $quotation->notes],
                array_values((array) $quotation->issuer), array_values((array) $quotation->customer),
                array_map(fn ($item) => $item->description, $quotation->items));
            foreach (array_unique(mb_str_split(implode('', $texts))) as $character) {
                if (! in_array($character, ["\n", "\r", "\t"], true)
                    && (! $dompdf->getCanvas()->font_supports_char($font, $character) || ! $dompdf->getCanvas()->font_supports_char($boldFont, $character))) {
                    throw new QuotationPdfException('unsupported_character');
                }
            }
            $layout = new QuotationTextLayout;
            $issuerHeading = $layout->lines($quotation->issuer->name, $metrics, $boldFont, $logo ? 385 : 505,
                $quotation->template->value === 'modern' ? 17 : 15);
            $numberLines = $layout->lines($quotation->number, $metrics, $font, 315, 10);
            $parties = [];
            foreach (['Prestador' => $quotation->issuer, 'Cliente' => $quotation->customer] as $label => $party) {
                $details = [];
                foreach ([$party->document, $party->email, $party->phone, $party->address] as $detail) {
                    if ($detail !== '') {
                        $details = array_merge($details, $layout->lines($detail, $metrics, $font, 220, 8.3));
                    }
                }
                $parties[] = ['label' => $label, 'name' => $layout->lines($party->name, $metrics, $boldFont, 220, 10), 'details' => $details];
            }
            $rows = [];
            foreach ($quotation->items as $index => $item) {
                foreach (array_chunk($layout->lines($item->description, $metrics, $font, 219), 4) as $part => $lines) {
                    $rows[] = ['index' => $index + 1, 'item' => $item, 'continuation' => $part > 0, 'lines' => $lines];
                }
            }
            $sections = [];
            foreach (['Condições de pagamento' => $quotation->conditions, 'Observações' => $quotation->notes] as $title => $text) {
                if ($text !== '') {
                    $sections[] = ['title' => $title, 'chunks' => array_chunk($layout->lines($text, $metrics, $font, 485), 8)];
                }
            }
            // A strict lower bound: even without headers/padding this text cannot fit.
            // Reject before Dompdf builds an unnecessarily large frame tree.
            $minimumLines = array_sum(array_map(fn (array $row) => count($row['lines']), $rows));
            foreach ($sections as $section) {
                $minimumLines += array_sum(array_map('count', $section['chunks']));
            }
            if ($minimumLines * 14 > QuotationLimits::MAX_PAGES * 750) {
                throw new QuotationPdfException('page_limit_exceeded');
            }
            $logoUri = null;
            if ($logo !== null) {
                if (file_put_contents($workspace.'/logo.png', $logo->png) !== strlen($logo->png)) {
                    throw new RuntimeException;
                }
                $logoUri = 'file://'.str_replace('\\', '/', $workspace.'/logo.png');
            }
            $html = View::make('quotations.'.$quotation->template->value, compact('quotation', 'rows', 'sections', 'logoUri', 'issuerHeading', 'numberLines', 'parties'))->render();
            $dompdf->loadHtml($html, 'UTF-8');
            $dompdf->render();
            $canvas = $dompdf->getCanvas();
            $pages = $canvas->get_page_count();
            if ($pages < 1 || $pages > QuotationLimits::MAX_PAGES) {
                throw new QuotationPdfException('page_limit_exceeded');
            }
            $canvas->page_text(36, 812, 'Feitoo • Documento comercial, sem valor fiscal', $font, 8, [0.35, 0.41, 0.38]);
            $canvas->page_text(445, 812, 'Página {PAGE_NUM} de {PAGE_COUNT}', $font, 8, [0.35, 0.41, 0.38]);
            $bytes = $dompdf->output();
            if (strlen($bytes) < 100 || strlen($bytes) > QuotationLimits::MAX_PDF_BYTES || ! str_starts_with($bytes, '%PDF-')
                || ! preg_match('/startxref\s+\d+\s+%%EOF\s*\z/', substr($bytes, -2048))
                || ($logo !== null && ! str_contains($bytes, '/Subtype /Image'))) {
                throw new RuntimeException;
            }

            return new QuotationPdf($bytes, $pages, hash('sha256', $bytes));
        } catch (Throwable $exception) {
            // Do not chain exceptions: renderer messages may contain personal data, HTML or paths.
            throw $exception instanceof QuotationPdfException ? $exception : new QuotationPdfException('render_failed');
        } finally {
            unset($dompdf);
            if (is_dir($workspace) && ! $files->deleteDirectory($workspace)) {
                throw new RuntimeException('Falha ao remover arquivos temporários do orçamento.');
            }
        }
    }
}
