<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Str;

class OrganizerNames
{
    public function segment(?string $value, string $fallback = 'Sem_Identificacao'): string
    {
        $name = preg_replace('/[^A-Za-z0-9_-]+/', '_', Str::ascii($value ?? ''));
        $name = trim(substr($name, 0, 80), '_-');
        if ($name === '' || preg_match('/\A(?:CON|PRN|AUX|NUL|COM[0-9]|LPT[0-9])\z/i', $name)) {
            return $fallback;
        }

        return $name;
    }

    public function folder(array $row, string $criterion): string
    {
        $supplier = $row['issuer_name'] ? $this->segment($row['issuer_name']) : 'Sem_Fornecedor';
        // Stable identity suffix prevents equal/sanitized supplier names from merging different issuers.
        if ($row['issuer_document']) {
            $supplier .= '_'.$row['issuer_document'];
        }
        $month = $row['month'] ?? 'Sem_Data';

        return match ($criterion) {
            'supplier' => $supplier,
            'issuer_document' => $row['issuer_document'] ?? 'Sem_CNPJ_CPF',
            'year_month_supplier' => $month.'/'.$supplier,
            'model' => $row['type'],
            default => $month,
        };
    }

    public function filename(array $row, bool $standardize): string
    {
        return $standardize && $row['access_key']
            ? $row['type'].'_'.$row['access_key'].'.xml'
            : $this->segment(pathinfo(str_replace('\\', '/', $row['original_name']), PATHINFO_FILENAME), 'arquivo').'.xml';
    }

    public function unique(string $folder, string $name, array $rows): string
    {
        $used = [];
        foreach ($rows as $row) {
            if ($row['archive_path'] ?? null) {
                $used[strtolower($row['archive_path'])] = true;
            }
        }
        $base = substr($name, 0, -4);
        for ($n = 1; ; $n++) {
            $candidate = $folder.'/'.$base.($n === 1 ? '' : '__'.$n).'.xml';
            if (! isset($used[strtolower($candidate)])) {
                return $candidate;
            }
        }
    }
}
