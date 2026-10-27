<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Quotations\NormalizedQuotationLogo;
use App\Domain\Quotations\Quotation;
use App\Domain\Quotations\QuotationValidationException;
use App\Services\Quotations\QuotationPdfException;
use App\Services\Quotations\QuotationPdfRenderer;
use App\Support\Quotations\QuotationDemoFixtures;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Throwable;

final class QuotationPdfDemo extends Command
{
    protected $signature = 'feitoo:quotation-pdf-demo {--scenario=* : Nome de uma fixture interna; vazio gera todas}';

    protected $description = 'Gera os dois modelos PDF privados com dados fictícios, somente em local/testing';

    public function handle(QuotationPdfRenderer $renderer): int
    {
        if (! app()->environment('local', 'testing')) {
            $this->error('Demonstração permitida somente em local/testing.');

            return self::FAILURE;
        }
        $scenarios = QuotationDemoFixtures::scenarios();
        $selected = $this->option('scenario');
        if (array_diff($selected, array_keys($scenarios))) {
            $this->error('Cenário desconhecido. Use somente fixtures internas.');

            return self::FAILURE;
        }
        if ($selected) {
            $scenarios = array_intersect_key($scenarios, array_flip($selected));
        }
        $directory = storage_path('app/private/quotation-validation/runs/'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4)));
        (new Filesystem)->makeDirectory($directory, 0700, true);
        $manifest = ['synthetic_only' => true, 'engine' => 'dompdf', 'files' => []];
        $failed = false;
        foreach ($scenarios as $name => $scenario) {
            foreach (['classic', 'modern'] as $template) {
                $filename = $name.'-'.$template.'.pdf';
                try {
                    memory_reset_peak_usage();
                    $start = hrtime(true);
                    $data = array_replace($scenario['data'], ['template_id' => $template]);
                    $quotation = Quotation::fromArray($data);
                    $logo = $scenario['logo'] ? NormalizedQuotationLogo::fromBytes(QuotationDemoFixtures::logo($scenario['logo'])) : null;
                    $pdf = $renderer->render($quotation, $logo);
                    if (file_put_contents($directory.'/'.$filename, $pdf->bytes) !== strlen($pdf->bytes)) {
                        throw new \RuntimeException;
                    }
                    $manifest['files'][] = [
                        'file' => $filename, 'scenario' => $name, 'template' => $template,
                        'pages' => $pdf->pages, 'bytes' => strlen($pdf->bytes), 'sha256' => $pdf->sha256,
                        'duration_ms' => round((hrtime(true) - $start) / 1000000, 1),
                        'peak_process_bytes' => memory_get_peak_usage(true),
                        'totals' => (array) $quotation->totals,
                        'last_item_tail' => mb_substr($quotation->items[array_key_last($quotation->items)]->description, -24),
                        'expected_text' => array_merge([$quotation->number, $quotation->conditions, $quotation->notes],
                            array_values(array_filter((array) $quotation->issuer)), array_values(array_filter((array) $quotation->customer)),
                            array_column($data['items'], 'description')),
                    ];
                    $this->line($filename.' | '.$pdf->pages.' página(s) | '.strlen($pdf->bytes).' bytes');
                } catch (Throwable $exception) {
                    $failed = true;
                    $reason = $exception instanceof QuotationPdfException ? $exception->reason : class_basename($exception);
                    if ($exception instanceof QuotationValidationException) {
                        $reason .= ': '.implode(', ', array_keys($exception->errors));
                    }
                    $this->error('Falha na fixture interna '.$name.' / '.$template.' ('.$reason.'). Nenhum conteúdo pessoal foi registrado.');
                }
            }
        }
        file_put_contents($directory.'/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $this->info('Diretório privado: '.$directory);

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
