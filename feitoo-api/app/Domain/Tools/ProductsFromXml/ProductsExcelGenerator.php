<?php

declare(strict_types=1);

namespace App\Domain\Tools\ProductsFromXml;

use App\Domain\Tools\WritesFiscalWorkbook;
use DateTimeImmutable;
use DateTimeZone;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;

class ProductsExcelGenerator
{
    use WritesFiscalWorkbook;

    public const PRODUCTS = ['Código', 'Produto', 'NCM', 'Unidade', 'Qtd total', 'NF-es',
        'Menor unitário', 'Maior unitário', 'Médio unitário', 'Total', 'CNPJ/CPF emitente', 'Emitente'];

    public const ITEMS = ['NF-e', 'Série', 'Emissão (UTC)', 'Emitente', 'CNPJ/CPF emitente', 'Código',
        'Produto', 'NCM', 'CFOP', 'Unidade', 'Quantidade', 'Unitário', 'Total'];

    /** @param list<ConsolidatedProduct> $products
     * @param  list<ProductItem>  $items
     */
    public function generate(array $products, array $items, string $path): void
    {
        if ($items === []) {
            throw new RuntimeException('No valid documents.');
        }
        $book = new Spreadsheet;
        try {
            $consolidated = $book->getActiveSheet()->setTitle('Produtos');
            $detail = $book->createSheet()->setTitle('Itens');
            $this->row($consolidated, 1, self::PRODUCTS);
            $this->row($detail, 1, self::ITEMS);
            foreach ($products as $index => $product) {
                $this->row($consolidated, $index + 2, [$product->code, $product->product, $product->ncm,
                    $product->unit, $product->total_quantity, $product->invoice_count, $product->minimum_unit_value,
                    $product->maximum_unit_value, $product->average_unit_value, $product->total_value,
                    $product->issuer_document, $product->issuer_name], [5, 7, 8, 9, 10], [6]);
            }
            foreach ($items as $index => $item) {
                $date = new DateTimeImmutable($item->issued_at);
                $excelDate = Date::PHPToExcel(strlen($item->issued_at) > 10 ? $date->setTimezone(new DateTimeZone('UTC')) : $date);
                $this->row($detail, $index + 2, [$item->note_number, $item->series, $excelDate, $item->issuer_name,
                    $item->issuer_document, $item->code, $item->product, $item->ncm, $item->cfop, $item->unit,
                    $item->quantity, $item->unit_value, $item->total_value], [11, 12, 13], [3]);
            }
            $this->format($consolidated, count($products) + 1, count(self::PRODUCTS), [5, 7, 8, 9, 10], null);
            $this->format($detail, count($items) + 1, count(self::ITEMS), [11, 12, 13], 3);
            $writer = new Xlsx($book);
            $writer->setPreCalculateFormulas(false);
            $writer->save($path);
        } finally {
            $book->disconnectWorksheets();
        }
    }
}
