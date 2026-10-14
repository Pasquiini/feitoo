<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Fiscal\Nfe\NfeParser;
use App\Domain\Tools\ProductsFromXml\ProductConsolidationService;
use App\Domain\Tools\ProductsFromXml\ProductItem;
use App\Domain\Tools\ProductsFromXml\ProductsExcelGenerator;
use Illuminate\Console\Command;

class BenchmarkProductsFromXml extends Command
{
    protected $signature = 'feitoo:benchmark-products-from-xml {--documents=20} {--items=16} {--unique : Different product codes in every invoice}';

    protected $description = 'Benchmark synthetic products parsing, consolidation and Excel; no user files or database';

    public function handle(NfeParser $parser, ProductConsolidationService $consolidation, ProductsExcelGenerator $generator): int
    {
        $documents = filter_var($this->option('documents'), FILTER_VALIDATE_INT);
        $count = filter_var($this->option('items'), FILTER_VALIDATE_INT);
        if (! app()->environment('local', 'testing') || $documents === false || $count === false
            || $documents < 1 || $documents > config('feitoo.uploads.max_processing_files') || $count < 1 || $count > 990
            || $documents * $count > config('feitoo.uploads.max_processing_items')) {
            $this->error('Use local/testing and stay within the configured document/item capacity.');

            return self::FAILURE;
        }
        $source = app(BenchmarkXmlToExcel::class);
        $template = $source->template($count);
        $path = tempnam(sys_get_temp_dir(), 'feitoo-products-benchmark-');
        if ($path === false) {
            return self::FAILURE;
        }
        try {
            memory_reset_peak_usage();
            $start = hrtime(true);
            $items = [];
            for ($index = 1; $index <= $documents; $index++) {
                $xml = $source->document($template, $index);
                if ($this->option('unique')) {
                    $xml = str_replace('SYNTHETIC-', 'SYNTHETIC-'.$index.'-', $xml);
                }
                // Match the persisted JSON roundtrip used by the worker.
                foreach (ProductItem::fromDocument($parser->parse($xml, productAnalysis: true)) as $item) {
                    $items[] = new ProductItem(...json_decode(json_encode(get_object_vars($item), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
                }
            }
            $parsed = hrtime(true);
            $products = $consolidation->consolidate($items);
            $consolidated = hrtime(true);
            $generator->generate($products, $items, $path);
            $done = hrtime(true);
            $this->line(json_encode(['documents' => $documents, 'items' => count($items), 'products' => count($products),
                'unique' => (bool) $this->option('unique'), 'parse_seconds' => round(($parsed - $start) / 1e9, 4),
                'consolidation_seconds' => round(($consolidated - $parsed) / 1e9, 4), 'xlsx_seconds' => round(($done - $consolidated) / 1e9, 4),
                'total_seconds' => round(($done - $start) / 1e9, 4), 'peak_memory_mb' => round(memory_get_peak_usage(true) / 1048576, 2),
                'xlsx_bytes' => filesize($path)], JSON_THROW_ON_ERROR));
        } finally {
            unlink($path);
        }

        return self::SUCCESS;
    }
}
