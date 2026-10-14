<?php

declare(strict_types=1);

namespace App\Domain\Tools\XmlToExcel;

use App\Domain\Fiscal\DTO\ParsedFiscalDocument;
use App\Domain\Tools\WritesFiscalWorkbook;
use DateTimeZone;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;

class XmlToExcelGenerator
{
    use WritesFiscalWorkbook;

    public const NOTES = ['Chave de acesso', 'Número', 'Série', 'Data de emissão (UTC)', 'Natureza da operação',
        'CNPJ/CPF emitente', 'Razão social emitente', 'Nome fantasia emitente', 'IE emitente', 'Município emitente', 'UF emitente',
        'CNPJ/CPF destinatário', 'Nome/Razão social destinatário', 'IE destinatário', 'Município destinatário', 'UF destinatário',
        'Quantidade de itens', 'Valor dos produtos', 'Frete', 'Seguro', 'Desconto', 'Outras despesas', 'IPI', 'ICMS', 'PIS', 'COFINS',
        'Valor total da NF-e', 'Número do protocolo', 'Status protocolo', 'Motivo protocolo', 'Offset original'];

    public const ITEMS = ['Chave da NF-e', 'Número da NF-e', 'Série', 'Data de emissão (UTC)', 'CNPJ/CPF emitente', 'Fornecedor', 'UF emitente',
        'Número do item', 'Código do produto', 'Descrição do produto', 'EAN/GTIN', 'NCM', 'CEST', 'CFOP', 'Unidade comercial',
        'Quantidade', 'Valor unitário', 'Valor total', 'EAN tributável', 'Unidade tributável', 'Quantidade tributável',
        'Valor unitário tributável', 'Número do pedido', 'Item do pedido', 'Offset original'];

    /** @param iterable<ParsedFiscalDocument> $documents */
    public function generate(iterable $documents, string $temporaryPath): void
    {
        $book = new Spreadsheet;
        try {
            $notes = $book->getActiveSheet()->setTitle('Notas');
            $items = $book->createSheet()->setTitle('Itens');
            $this->row($notes, 1, self::NOTES);
            $this->row($items, 1, self::ITEMS);
            $noteRow = $itemRow = 2;
            foreach ($documents as $document) {
                $issuer = $document->issuer;
                $recipient = $document->recipient;
                $totals = $document->totals;
                $date = Date::PHPToExcel($document->identification->issuedAtHasTime
                    ? $document->issuedAt->setTimezone(new DateTimeZone('UTC')) : $document->issuedAt);
                $offset = $document->identification->issuedAtHasTime ? $document->issuedAt->format('P') : null;
                $this->row($notes, $noteRow++, [$document->accessKey, $document->number, $document->series, $date, $document->operationNature,
                    $issuer->cnpj ?? $issuer->cpf, $issuer->name, $issuer->tradeName, $issuer->stateRegistration, $issuer->address?->municipality, $issuer->address?->state,
                    $recipient?->cnpj ?? $recipient?->cpf, $recipient?->name, $recipient?->stateRegistration, $recipient?->address?->municipality, $recipient?->address?->state,
                    count($document->items), $totals?->vProd, $totals?->vFrete, $totals?->vSeg, $totals?->vDesc, $totals?->vOutro,
                    $totals?->vIPI, $totals?->vICMS, $totals?->vPIS, $totals?->vCOFINS, $totals?->vNF,
                    $document->protocol?->number, $document->protocol?->statusCode, $document->protocol?->reason, $offset], range(18, 27), [4, 17]);
                foreach ($document->items as $item) {
                    if ($itemRow > 1048576) {
                        throw new RuntimeException('Workbook row limit exceeded.');
                    }
                    $this->row($items, $itemRow++, [$document->accessKey, $document->number, $document->series, $date,
                        $issuer->cnpj ?? $issuer->cpf, $issuer->name, $issuer->address?->state, $item->number,
                        $item->productCode, $item->description, $item->gtin, $item->ncm, $item->cest, $item->cfop,
                        $item->commercialUnit, $item->commercialQuantity, $item->commercialUnitPrice, $item->productTotal,
                        $item->taxableGtin, $item->taxableUnit, $item->taxableQuantity, $item->taxableUnitPrice,
                        $item->purchaseOrder, $item->purchaseOrderItem, $offset], [16, 17, 18, 21, 22], [4, 8]);
                }
            }
            if ($noteRow === 2) {
                throw new RuntimeException('No valid documents.');
            }
            $this->format($notes, $noteRow - 1, count(self::NOTES), range(18, 27));
            $this->format($items, $itemRow - 1, count(self::ITEMS), [16, 17, 18, 21, 22]);
            $book->setActiveSheetIndex(0);
            $writer = new Xlsx($book);
            $writer->setPreCalculateFormulas(false);
            $writer->save($temporaryPath);
        } finally {
            $book->disconnectWorksheets();
        }
    }
}
