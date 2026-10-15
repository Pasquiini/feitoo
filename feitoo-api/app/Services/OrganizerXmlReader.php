<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Fiscal\Exceptions\InvalidFiscalXml;
use App\Domain\Fiscal\Xml\FiscalXmlDetector;
use App\Domain\Fiscal\Xml\FiscalXmlFields;
use App\Support\Xml\SecureXml;

/** Metadata-only reader. No authorization claim and no invented values for absent fiscal fields. */
class OrganizerXmlReader
{
    public function __construct(private readonly SecureXml $xml, private readonly FiscalXmlFields $fields) {}

    public function read(string $contents): array
    {
        $row = array_fill_keys(['access_key', 'number', 'series', 'issued_at', 'month', 'issuer_document', 'issuer_name', 'recipient_document', 'model', 'total_value'], null);
        $row += ['type' => 'Outros', 'recognized' => false, 'valid' => false, 'notes' => []];
        $tree = $this->xml->load($contents);
        $root = $tree->documentElement;
        if ($root->namespaceURI !== FiscalXmlDetector::NFE_NAMESPACE || ! in_array($root->localName, ['NFe', 'nfeProc'], true)) {
            $row['notes'][] = 'Formato não suportado. Classificado sem extração fiscal.';

            return $row;
        }
        $f = $this->fields;
        $nfe = $root->localName === 'NFe' ? $root : $f->child($root, 'NFe', true);
        $info = $f->child($nfe, 'infNFe', true);
        $ide = $f->child($info, 'ide', true);
        $row['model'] = $f->text($ide, 'mod');
        if ($row['model'] !== null && ! preg_match('/\A[0-9]{2}\z/D', $row['model'])) {
            $row['model'] = null;
        }
        if (! in_array($row['model'], ['55', '65'], true)) {
            $row['notes'][] = 'Modelo não suportado. Apenas NF-e 55 e NFC-e 65 são lidas.';

            return $row;
        }
        $row['recognized'] = $row['valid'] = true;
        $row['type'] = $row['model'] === '55' ? 'NFe' : 'NFCe';
        $emit = $f->child($info, 'emit');
        $dest = $f->child($info, 'dest');
        $row['number'] = $this->digits($f->text($ide, 'nNF'), 9, $row);
        $row['series'] = $this->digits($f->text($ide, 'serie'), 3, $row);
        $row['issuer_name'] = $emit ? mb_substr($f->text($emit, 'xNome') ?? '', 0, 255) ?: null : null;
        $row['issuer_document'] = $emit ? $this->partyDocument($emit, $row) : null;
        $row['recipient_document'] = $dest ? $this->partyDocument($dest, $row) : null;
        $total = $f->child($info, 'total');
        $icms = $total ? $f->child($total, 'ICMSTot') : null;
        if ($icms) {
            try {
                $row['total_value'] = $f->decimal($icms, 'vNF');
            } catch (InvalidFiscalXml) {
                $row['valid'] = false;
                $row['notes'][] = 'Valor total inválido.';
            }
        }
        $date = $f->text($ide, 'dhEmi') ?? $f->text($ide, 'dEmi');
        if ($date !== null) {
            try {
                $parsed = $f->date($date, str_contains($date, 'T'));
                $row['issued_at'] = $date;
                // Use the issue date's own timezone, without shifting month across UTC boundaries.
                $row['month'] = $parsed->format('Y/m');
            } catch (InvalidFiscalXml) {
                $row['valid'] = false;
                $row['notes'][] = 'Data de emissão inválida.';
            }
        }
        $id = $info->getAttribute('Id');
        $key = str_starts_with($id, 'NFe') ? substr($id, 3) : '';
        if ($this->trustedKey($key, $row, $ide)) {
            $row['access_key'] = $key;
        } else {
            $row['notes'][] = 'Chave ausente, inválida ou inconsistente; nome original seguro preservado.';
        }
        foreach (['issued_at' => 'Data de emissão', 'issuer_document' => 'CNPJ/CPF do emitente', 'issuer_name' => 'Nome do emitente', 'number' => 'Número', 'series' => 'Série', 'total_value' => 'Valor total'] as $field => $label) {
            if ($row[$field] === null) {
                $row['notes'][] = $label.' não informado ou não confiável.';
            }
        }

        return $row;
    }

    private function digits(?string $value, int $length, array &$row): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! preg_match('/\A[0-9]{1,'.$length.'}\z/D', $value)) {
            $row['valid'] = false;
            $row['notes'][] = 'Campo numérico inválido.';

            return null;
        }

        return $value;
    }

    private function partyDocument(\DOMElement $party, array &$row): ?string
    {
        $cnpj = $this->fields->text($party, 'CNPJ');
        $cpf = $this->fields->text($party, 'CPF');
        if ($cnpj === null && $cpf === null) {
            return null;
        }
        if (($cnpj !== null && $cpf !== null) || ! preg_match($cnpj !== null ? '/\A[0-9]{14}\z/D' : '/\A[0-9]{11}\z/D', $cnpj ?? $cpf)) {
            $row['valid'] = false;
            $row['notes'][] = 'Identificação do emitente/destinatário inválida.';

            return null;
        }

        return $cnpj ?? $cpf;
    }

    private function trustedKey(string $key, array $row, \DOMElement $ide): bool
    {
        if (! preg_match('/\A[0-9]{44}\z/D', $key) || substr($key, 20, 2) !== $row['model']) {
            return false;
        }
        if (! in_array(substr($key, 0, 2), ['11', '12', '13', '14', '15', '16', '17', '21', '22', '23', '24', '25', '26', '27', '28', '29', '31', '32', '33', '35', '41', '42', '43', '50', '51', '52', '53'], true)
            || (int) substr($key, 4, 2) < 1 || (int) substr($key, 4, 2) > 12 || (int) substr($key, 25, 9) === 0) {
            return false;
        }
        $sum = 0;
        for ($i = 42, $weight = 2; $i >= 0; $i--, $weight = $weight === 9 ? 2 : $weight + 1) {
            $sum += (int) $key[$i] * $weight;
        }
        $dv = 11 - $sum % 11;
        if ((int) $key[43] !== ($dv >= 10 ? 0 : $dv)) {
            return false;
        }
        if ($row['issuer_document'] !== null && substr($key, 6, 14) !== str_pad($row['issuer_document'], 14, '0', STR_PAD_LEFT)) {
            return false;
        }
        if ($row['issued_at'] !== null && substr($key, 2, 4) !== substr($row['issued_at'], 2, 2).substr($row['issued_at'], 5, 2)) {
            return false;
        }
        if ($row['number'] !== null && (int) substr($key, 25, 9) !== (int) $row['number']) {
            return false;
        }
        if ($row['series'] !== null && (int) substr($key, 22, 3) !== (int) $row['series']) {
            return false;
        }
        foreach (['cUF' => [0, 2], 'tpEmis' => [34, 1], 'cNF' => [35, 8], 'cDV' => [43, 1]] as $tag => [$offset, $length]) {
            $value = $this->fields->text($ide, $tag);
            if ($value !== null && substr($key, $offset, $length) !== $value) {
                return false;
            }
        }

        return true;
    }
}
