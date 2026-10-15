<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Tools\WritesFiscalWorkbook;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class AnalyzerExcelGenerator
{
    use WritesFiscalWorkbook;

    public function generate(array $view, string $path): void
    {
        $book = new Spreadsheet;
        try {
            $summary = $book->getActiveSheet()->setTitle('Resumo');
            $this->row($summary, 1, ['Indicador / escopo', 'Valor']);
            $n = 2;
            $this->row($summary, $n++, ['Aviso', AnalyzerAggregator::NOTICE]);
            $this->row($summary, $n++, ['Consumo', 'Cada XML lido com sucesso consome uma unidade, inclusive cópias e conflitos. Indicadores incluem somente documentos considerados, sem repetições/conflitos.']);
            $this->row($summary, $n++, ['Campos ausentes', 'Total/média usam somente vNF informado. Tributos usam apenas total/ICMSTot; ausência não significa zero. Datas preservam o fuso original.']);
            $this->row($summary, $n++, ['Produtos', 'Emitente + código (ou descrição exata normalizada) + NCM + unidade. Quantidades de unidades distintas não são somadas. Emitente não equivale necessariamente a fornecedor do usuário.']);
            $this->row($summary, $n++, ['Filtros', json_encode($view['filters'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
            $this->row($summary, $n++, ['Escopo da pesquisa de produtos', 'Afeta apenas a aba Produtos. Indicadores, documentos e tributos mantêm o filtro de documentos. Inconsistências e contagens do lote são globais.']);
            $labels = ['documents_count' => 'Documentos considerados no filtro', 'total_value' => 'Total vNF informado', 'documents_with_total' => 'Documentos com vNF', 'documents_without_total' => 'Documentos sem vNF', 'average_value' => 'Média por documento com vNF (2 casas, HALF_UP)', 'issuers_count' => 'Emitentes com identificação confiável', 'untrusted_issuer_groups' => 'Grupos isolados sem identificação confiável', 'products_count' => 'Produtos distintos antes da pesquisa de produto', 'items_count' => 'Linhas de itens consideradas', 'period_start' => 'Início do período fiscal', 'period_end' => 'Fim do período fiscal'];
            foreach ($labels as $field => $label) {
                $this->row($summary, $n++, [$label, $view['summary'][$field] ?? 'Não informado']);
            }
            foreach ($view['audit'] as $key => $value) {
                $this->row($summary, $n++, ['Lote completo: '.$key, $value]);
            }
            foreach ($view['monthly'] as $month) {
                $this->row($summary, $n++, ['Mês '.$month['month'].' · '.$month['documents_count'].' notas · total vNF', $month['total_value'] ?? 'Não informado']);
            }
            $this->format($summary, $n - 1, 2, [], null);
            $this->sheet($book, 'Documentos', ['Arquivo original', 'Número', 'Série', 'Chave', 'Emissão (fuso original)', 'Emitente', 'CNPJ/CPF', 'Destinatário', 'Valor vNF (XML)', 'Status da análise', 'Incluído nos indicadores', 'Observações'],
                array_map(fn ($d) => [$d['file_name'], $d['number'] ?? null, $d['series'] ?? null, $d['access_key'] ?? null, $d['issued_at'] ?? null, $d['issuer_name'] ?? null, $d['issuer_document'] ?? null, $d['recipient_name'] ?? null, $d['total_value'] ?? null, $d['analysis_status'], $d['included'] ? 'Sim' : 'Não', implode(' ', $d['notes'] ?? [$d['error_message'] ?? 'Falha na leitura.'])], $view['documents']));
            $this->sheet($book, 'Emitentes', ['Emitente', 'CNPJ/CPF', 'Identificação confiável (formato/DV)', 'Notas', 'Total vNF informado', '% do valor conhecido', 'Última emissão (data fiscal)', 'Notas sem vNF'],
                array_map(fn ($i) => [$i['name'], $i['document'], $i['trusted'] ? 'Sim' : 'Não; grupo isolado por nota', $i['documents_count'], $i['total_value'], $i['percentage'], $i['last_issue_date'], $i['documents_without_total']], $view['issuers']));
            $this->sheet($book, 'Produtos', ['Código', 'Descrição', 'NCM', 'Unidade', 'Quantidade comercial', 'Valor dos produtos', 'Ocorrências em notas distintas', 'Emitente', 'CNPJ/CPF'],
                array_map(fn ($p) => [$p['code'], $p['product'], $p['ncm'], $p['unit'], $p['total_quantity'], $p['total_value'], $p['invoice_count'], $p['issuer_name'], $p['issuer_document']], $view['products']));
            $taxRows = [];
            foreach ($view['taxes'] as $name => $tax) {
                $taxRows[] = [$name, $tax['total'], $tax['documents_with_field'], $tax['documents_without_field'], $tax['source'], 'Destacado no XML; não representa recolhimento/crédito/imposto devido. Ausência: não informado ou não aplicável.'];
            }
            $this->sheet($book, 'Tributos', ['Tributo', 'Total destacado informado', 'Documentos com campo', 'Documentos sem campo', 'Origem exclusiva', 'Escopo'], $taxRows);
            $this->sheet($book, 'Inconsistências', ['Arquivo (lote completo)', 'Chave', 'Status da análise', 'Observação'], array_map(fn ($i) => [$i['file_name'], $i['access_key'], $i['status'], $i['message']], $view['inconsistencies']));
            $writer = new Xlsx($book);
            $writer->setPreCalculateFormulas(false);
            $writer->save($path);
        } finally {
            $book->disconnectWorksheets();
        }
    }

    private function sheet(Spreadsheet $book, string $name, array $headers, array $rows): void
    {
        $sheet = $book->createSheet()->setTitle($name);
        $this->row($sheet, 1, $headers);
        foreach ($rows as $i => $row) {
            $this->row($sheet, $i + 2, $row);
        }
        $this->format($sheet, max(2, count($rows) + 1), count($headers), [], null);
    }
}
