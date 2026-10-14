<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Tools\ProductsFromXml\ProductConsolidationService;
use App\Domain\Tools\ProductsFromXml\ProductItem;
use App\Domain\Tools\ProductsFromXml\ProductsExcelGenerator;
use App\Models\Processing;
use App\Models\ProcessingFileResult;
use Brick\Math\BigDecimal;
use RuntimeException;

class ProductsFromXmlResultService
{
    public function __construct(private readonly ProductConsolidationService $consolidation,
        private readonly ProductsExcelGenerator $generator) {}

    public function generate(Processing $processing, string $path): void
    {
        $items = [];
        $query = ProcessingFileResult::query()->whereIn('processing_file_id', $processing->files()
            ->where('kind', 'input')->where('status', 'completed')->whereNull('removed_at')->select('id'));
        foreach ($query->select(['id', 'products_items'])->orderBy('id')->lazyById(100) as $result) {
            foreach ($result->products_items ?? [] as $fields) {
                $items[] = new ProductItem(...$fields);
                if (count($items) > config('feitoo.uploads.max_processing_items')) {
                    throw new RuntimeException('Technical item capacity exceeded.');
                }
            }
        }
        $products = $this->consolidation->consolidate($items);
        $this->generator->generate($products, $items, $path);
        $total = BigDecimal::zero();
        foreach ($products as $product) {
            $total = $total->plus($product->total_value);
        }
        $processing->update(['metadata' => array_merge($processing->metadata ?? [], [
            'products_summary' => ['successful_documents_count' => $processing->successful_files_count,
                'failed_documents_count' => $processing->failed_files_count, 'items_count' => count($items),
                'products_count' => count($products), 'total_value' => (string) $total, 'documents_without_total_count' => 0],
            'products_preview' => array_map(fn ($product) => $product->preview(), array_slice($products, 0, 10)),
            'products_items_preview' => array_map(fn ($item) => $item->preview(), array_slice($items, 0, 10)),
        ])]);
    }

    public function preview(Processing $processing): array
    {
        $metadata = $processing->metadata ?? [];

        return ['summary' => $metadata['products_summary'], 'products_preview' => $metadata['products_preview'] ?? [],
            'items_preview' => $metadata['products_items_preview'] ?? [], 'notes_preview' => []];
    }
}
