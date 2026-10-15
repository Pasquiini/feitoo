<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Tools\WritesFiscalWorkbook;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class OrganizerReport
{
    use WritesFiscalWorkbook;

    public function generate(array $rows, array $summary, string $path): void
    {
        $book = new Spreadsheet;
        try {
            $sheet = $book->getActiveSheet()->setTitle('Organização');
            $headers = ['Nome original', 'Nome final', 'Pasta de destino', 'Chave de acesso', 'Número', 'Série', 'Modelo', 'Data de emissão', 'Emitente', 'CNPJ/CPF emitente', 'CNPJ/CPF destinatário', 'Valor do documento (XML)', 'Status', 'Duplicado', 'Conflito de chave', 'Observações', 'Situação atual SEFAZ'];
            $this->row($sheet, 1, $headers);
            $n = 2;
            foreach ($rows as $row) {
                $this->row($sheet, $n++, [
                    $row['original_name'], $row['final_name'], $row['folder'], $row['access_key'], $row['number'], $row['series'],
                    $row['model'], $row['issued_at'], $row['issuer_name'], $row['issuer_document'], $row['recipient_document'],
                    $row['total_value'], $row['status'], $row['duplicate_kind'], $row['key_conflict'] ? 'Sim' : 'Não',
                    implode(' ', $row['notes']), XmlOrganizerService::NOTICE,
                ]);
            }
            $this->format($sheet, $n - 1, count($headers), [], null);
            $summarySheet = $book->createSheet()->setTitle('Resumo');
            $this->row($summarySheet, 1, ['Indicador', 'Quantidade']);
            $n = 2;
            foreach (['total_files' => 'Arquivos enviados', 'organized_count' => 'Organizados', 'duplicates_count' => 'Repetições após o primeiro arquivo', 'invalid_count' => 'Inválidos/incompatíveis/com falha', 'suppliers_count' => 'Fornecedores identificados', 'preserved_count' => 'Originais preservados no ZIP', 'conflicting_keys_count' => 'Chaves com conteúdo divergente'] as $key => $label) {
                $this->row($summarySheet, $n++, [$label, $summary[$key]]);
            }
            foreach ($summary['documents_by_month'] as $month => $count) {
                $this->row($summarySheet, $n++, ['Documentos em '.$month, $count]);
            }
            $this->row($summarySheet, $n++, ['Observação', XmlOrganizerService::NOTICE]);
            $this->format($summarySheet, $n - 1, 2, [], null);
            $writer = new Xlsx($book);
            $writer->setPreCalculateFormulas(false);
            $writer->save($path);
        } finally {
            $book->disconnectWorksheets();
        }
    }
}
