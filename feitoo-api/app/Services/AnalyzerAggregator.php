<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Fiscal\DTO\ParsedFiscalDocument;
use App\Domain\Tools\ProductsFromXml\ProductConsolidationService;
use App\Domain\Tools\ProductsFromXml\ProductItem;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Str;

class AnalyzerAggregator
{
    public const NOTICE = 'Análise informativa dos dados do XML. Status atual na SEFAZ não consultado; assinatura digital e autorização não verificadas. Tributos destacados não representam recolhimento, crédito ou imposto devido.';

    public function __construct(private readonly ProductConsolidationService $products) {}

    public function document(ParsedFiscalDocument $d): array
    {
        $document = $d->issuer->cnpj ?? $d->issuer->cpf;
        $trusted = ! ($d->issuer->cnpj !== null && $d->issuer->cpf !== null) && $this->trustedDocument($document);
        $issuerId = hash('sha256', $trusted ? 'document:'.$document : 'invoice:'.$d->accessKey);
        $notes = [];
        if (! $trusted) {
            $notes[] = 'Documento do emitente sem identificação confiável (formato/DV). Grupo isolado por nota, sem agrupar pelo nome.';
        }
        if ($d->totals?->vNF === null) {
            $notes[] = 'Total vNF ausente: documento fora da soma monetária e da média.';
        }
        $sum = BigDecimal::zero();
        $allFlags = true;
        foreach ($d->items as $item) {
            $allFlags = $allFlags && in_array($item->includedInTotal, ['0', '1'], true);
            if ($item->includedInTotal === '1') {
                $sum = $sum->plus($item->productTotal);
            }
            if ($item->productCode === '') {
                $notes[] = 'Produto sem código: agrupamento por descrição exata normalizada, NCM e unidade, dentro do emitente.';
            }
        }
        if ($allFlags && $d->totals?->vProd !== null && ! $sum->isEqualTo($d->totals->vProd)) {
            $notes[] = 'vProd da nota diverge da soma dos itens com indTot=1. Valores originais mantidos, sem correção automática.';
        }
        $items = array_map(function (ProductItem $item) use ($issuerId): array {
            $row = get_object_vars($item);
            // Internal group identity is separate from the CNPJ/CPF displayed in the dashboard.
            $row['issuer_document'] = $issuerId;

            return $row;
        }, ProductItem::fromDocument($d));
        $taxes = ['ICMS' => $d->totals?->vICMS, 'IPI' => $d->totals?->vIPI, 'PIS' => $d->totals?->vPIS, 'COFINS' => $d->totals?->vCOFINS];
        if (in_array(null, $taxes, true)) {
            $notes[] = 'Há tributos sem campo de total informado. Ausência não foi convertida em zero.';
        }

        return [
            'access_key' => $d->accessKey, 'number' => $d->number, 'series' => $d->series,
            'issued_at' => $d->issuedAt->format($d->identification->issuedAtHasTime ? 'c' : 'Y-m-d'),
            'issue_date' => $d->issuedAt->format('Y-m-d'), 'month' => $d->issuedAt->format('Y-m'),
            'issuer_id' => $issuerId, 'issuer_trusted' => $trusted, 'issuer_document' => $document,
            'issuer_name' => $d->issuer->name, 'recipient_name' => $d->recipient?->name,
            'total_value' => $d->totals?->vNF, 'items_count' => count($items), 'items' => $items,
            'taxes' => $taxes, 'notes' => array_values(array_unique($notes)),
        ];
    }

    /** All readable members of a conflicting key are excluded, independent of input order. */
    public function classify(array $files): array
    {
        $keys = [];
        foreach ($files as $file) {
            if ($file['data']) {
                $keys[$file['data']['access_key']][$file['checksum']] = true;
            }
        }
        $hashes = [];
        foreach ($files as &$file) {
            $file['included'] = false;
            $file['duplicate_kind'] = null;
            if (! $file['data']) {
                $file['analysis_status'] = 'invalid';

                continue;
            }
            if (count($keys[$file['data']['access_key']]) > 1) {
                $file['analysis_status'] = 'conflict';
                $file['duplicate_kind'] = 'Mesma chave com conteúdos diferentes';
                $file['data']['notes'][] = 'Conflito de chave: todos os participantes foram excluídos da agregação. Reenvie um lote resolvido; nenhum XML foi escolhido como verdadeiro.';
            } elseif (isset($hashes[$file['checksum']])) {
                $file['analysis_status'] = 'duplicate';
                $file['duplicate_kind'] = 'SHA-256 idêntico';
                $file['data']['notes'][] = 'Cópia idêntica ignorada nos indicadores; apenas a primeira ocorrência é considerada.';
            } else {
                $file['analysis_status'] = 'included';
                $file['included'] = true;
                $hashes[$file['checksum']] = true;
            }
        }
        unset($file);

        return $files;
    }

    public function view(array $files, array $filters = []): array
    {
        $audit = ['uploaded_files' => count($files), 'analyzed_files' => 0, 'considered_documents' => 0, 'duplicates_ignored' => 0, 'conflict_files' => 0, 'conflicting_keys' => 0, 'invalid_files' => 0];
        $conflicts = $issues = [];
        foreach ($files as $file) {
            if ($file['data']) {
                $audit['analyzed_files']++;
            } else {
                $audit['invalid_files']++;
            }
            if ($file['included']) {
                $audit['considered_documents']++;
            }
            if ($file['analysis_status'] === 'duplicate') {
                $audit['duplicates_ignored']++;
            }
            if ($file['analysis_status'] === 'conflict') {
                $audit['conflict_files']++;
                $conflicts[$file['data']['access_key']] = true;
            }
            foreach ($file['data']['notes'] ?? [$file['error_message'] ?? 'Falha na leitura.'] as $note) {
                $issues[] = ['file_name' => $file['file_name'], 'access_key' => $file['data']['access_key'] ?? null, 'status' => $file['analysis_status'], 'message' => $note];
            }
        }
        $audit['conflicting_keys'] = count($conflicts);
        $selected = array_values(array_filter($files, fn ($file) => $this->matches($file, $filters)));
        $documents = $issuerGroups = $monthly = $items = [];
        $total = BigDecimal::zero();
        $knownTotals = $lines = $included = 0;
        $dates = [];
        $taxes = array_fill_keys(['ICMS', 'IPI', 'PIS', 'COFINS'], ['total' => null, 'documents_with_field' => 0, 'documents_without_field' => 0, 'source' => 'total/ICMSTot']);
        foreach ($selected as $file) {
            $d = $file['data'];
            $row = ['file_id' => $file['file_id'], 'file_name' => $file['file_name'], 'analysis_status' => $file['analysis_status'], 'included' => $file['included'], 'duplicate_kind' => $file['duplicate_kind'], 'error_message' => $file['error_message']];
            if ($d) {
                $display = $d;
                unset($display['items'], $display['taxes']);
                $row += $display;
            }
            $documents[] = $row;
            if (! $file['included']) {
                continue;
            }
            $included++;
            $lines += $d['items_count'];
            $dates[] = $d['issue_date'];
            $group = $issuerGroups[$d['issuer_id']] ?? [
                'id' => $d['issuer_id'], 'name' => $d['issuer_name'], 'document' => $d['issuer_document'], 'trusted' => $d['issuer_trusted'],
                'documents_count' => 0, 'total_value' => null, 'documents_without_total' => 0, 'last_issue_date' => $d['issue_date'],
            ];
            $group['documents_count']++;
            $group['last_issue_date'] = max($group['last_issue_date'], $d['issue_date']);
            $month = $monthly[$d['month']] ?? ['month' => $d['month'], 'documents_count' => 0, 'total_value' => null, 'documents_without_total' => 0];
            $month['documents_count']++;
            if ($d['total_value'] !== null) {
                $total = $total->plus($d['total_value']);
                $knownTotals++;
                $group['total_value'] = $this->plus($group['total_value'], $d['total_value']);
                $month['total_value'] = $this->plus($month['total_value'], $d['total_value']);
            } else {
                $group['documents_without_total']++;
                $month['documents_without_total']++;
            }
            $issuerGroups[$d['issuer_id']] = $group;
            $monthly[$d['month']] = $month;
            foreach ($d['taxes'] as $name => $value) {
                if ($value === null) {
                    $taxes[$name]['documents_without_field']++;
                } else {
                    $taxes[$name]['documents_with_field']++;
                    $taxes[$name]['total'] = $this->plus($taxes[$name]['total'], $value);
                }
            }
            foreach ($d['items'] as $fields) {
                $items[] = new ProductItem(...$fields);
            }
        }
        $issuers = array_values($issuerGroups);
        foreach ($issuers as &$issuer) {
            $issuer['percentage'] = $issuer['total_value'] === null || $total->isZero() ? null : (string) BigDecimal::of($issuer['total_value'])->multipliedBy(100)->dividedBy($total, 2, RoundingMode::HALF_UP);
        }
        unset($issuer);
        $products = [];
        foreach ($this->products->consolidate($items) as $p) {
            $row = get_object_vars($p);
            $row['issuer_id'] = $p->issuer_document;
            $row['issuer_document'] = $issuerGroups[$p->issuer_document]['document'];
            $row['issuer_name'] = $issuerGroups[$p->issuer_document]['name'];
            $products[] = $row;
        }
        $productCount = count($products);
        if ($filters['product_query'] ?? '') {
            $products = array_values(array_filter($products, fn ($p) => str_contains($this->search(($p['code'] ?? '').' '.$p['product']), $this->search($filters['product_query']))));
        }
        ksort($monthly);
        $maxValue = BigDecimal::zero();
        $maxCount = 1;
        foreach ($monthly as $m) {
            if ($m['total_value'] !== null && BigDecimal::of($m['total_value'])->isGreaterThan($maxValue)) {
                $maxValue = BigDecimal::of($m['total_value']);
            } $maxCount = max($maxCount, $m['documents_count']);
        }
        foreach ($monthly as &$m) {
            $m['value_bar'] = $maxValue->isZero() || $m['total_value'] === null ? '0' : (string) BigDecimal::of($m['total_value'])->multipliedBy(100)->dividedBy($maxValue, 4, RoundingMode::HALF_UP);
            $m['count_bar'] = (string) BigDecimal::of($m['documents_count'])->multipliedBy(100)->dividedBy($maxCount, 4, RoundingMode::HALF_UP);
        }
        unset($m);

        return [
            'notice' => self::NOTICE, 'audit' => $audit, 'filters' => $filters,
            'summary' => ['documents_count' => $included, 'total_value' => $knownTotals ? (string) $total : null,
                'documents_with_total' => $knownTotals, 'documents_without_total' => $included - $knownTotals,
                'average_value' => $knownTotals ? (string) $total->dividedBy($knownTotals, 2, RoundingMode::HALF_UP) : null,
                'issuers_count' => count(array_filter($issuers, fn ($i) => $i['trusted'])),
                'untrusted_issuer_groups' => count(array_filter($issuers, fn ($i) => ! $i['trusted'])),
                'products_count' => $productCount, 'items_count' => $lines,
                'period_start' => $dates ? min($dates) : null, 'period_end' => $dates ? max($dates) : null],
            'monthly' => array_values($monthly), 'issuers' => $issuers, 'products' => $products,
            'taxes' => $taxes, 'documents' => $documents, 'inconsistencies' => $issues,
        ];
    }

    private function matches(array $file, array $filters): bool
    {
        $d = $file['data'];
        if (($filters['from'] ?? '') && (! $d || $d['issue_date'] < $filters['from'])) {
            return false;
        }
        if (($filters['to'] ?? '') && (! $d || $d['issue_date'] > $filters['to'])) {
            return false;
        }
        if (($filters['issuer'] ?? '') && (! $d || $d['issuer_id'] !== $filters['issuer'])) {
            return false;
        }
        if (($filters['document_query'] ?? '') && ! str_contains($this->search(($d['number'] ?? '').' '.($d['access_key'] ?? '')), $this->search($filters['document_query']))) {
            return false;
        }

        return true;
    }

    private function plus(?string $a, string $b): string
    {
        return (string) BigDecimal::of($a ?? '0')->plus($b);
    }

    private function search(string $value): string
    {
        return mb_strtolower(Str::ascii(trim($value)), 'UTF-8');
    }

    /** Format and check digits only; no registry/ownership/SEFAZ verification. */
    private function trustedDocument(?string $id): bool
    {
        if (! $id || ! preg_match('/\A(?:[0-9]{11}|[0-9]{14})\z/D', $id) || preg_match('/\A([0-9])\1+\z/D', $id)) {
            return false;
        }
        $baseLength = strlen($id) - 2;
        for ($digit = 0; $digit < 2; $digit++) {
            $length = $baseLength + $digit;
            $sum = 0;
            for ($i = 0; $i < $length; $i++) {
                $weight = strlen($id) === 11 ? $length + 1 - $i : (($length - 1 - $i) % 8) + 2;
                $sum += (int) $id[$i] * $weight;
            }
            $expected = $sum % 11 < 2 ? 0 : 11 - $sum % 11;
            if ((int) $id[$length] !== $expected) {
                return false;
            }
        }

        return true;
    }
}
