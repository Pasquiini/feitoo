<?php

declare(strict_types=1);

namespace App\Domain\Fiscal\Nfe;

use App\Domain\Fiscal\DTO\FiscalAddress;
use App\Domain\Fiscal\DTO\FiscalDocumentType;
use App\Domain\Fiscal\DTO\FiscalIdentification;
use App\Domain\Fiscal\DTO\FiscalParty;
use App\Domain\Fiscal\DTO\FiscalPayment;
use App\Domain\Fiscal\DTO\FiscalProtocol;
use App\Domain\Fiscal\DTO\FiscalTotals;
use App\Domain\Fiscal\DTO\ParsedFiscalDocument;
use App\Domain\Fiscal\DTO\ParsedFiscalItem;
use App\Domain\Fiscal\Exceptions\InvalidFiscalXml;
use App\Domain\Fiscal\Exceptions\MissingRequiredFiscalField;
use App\Domain\Fiscal\Exceptions\UnsupportedFiscalDocument;
use App\Domain\Fiscal\Xml\FiscalXmlDetector;
use App\Domain\Fiscal\Xml\FiscalXmlEnvelope;
use App\Domain\Fiscal\Xml\FiscalXmlFields;
use App\Support\Xml\InvalidXml;
use App\Support\Xml\SecureXml;
use DOMElement;

final class NfeParser
{
    public function __construct(
        private readonly SecureXml $xml = new SecureXml,
        private readonly FiscalXmlDetector $detector = new FiscalXmlDetector,
        private readonly FiscalXmlFields $fields = new FiscalXmlFields,
        private readonly NfeTaxParser $taxes = new NfeTaxParser,
    ) {}

    public function parse(string $xml, bool $productAnalysis = false): ParsedFiscalDocument
    {
        try {
            $tree = $this->xml->load($xml);
        } catch (InvalidXml) {
            throw new InvalidFiscalXml;
        }
        $envelope = $this->detector->inspect($tree);
        $root = $tree->documentElement;
        $nfe = $envelope === FiscalXmlEnvelope::Nfe ? $root : $this->fields->child($root, 'NFe', true);
        $info = $this->fields->child($nfe, 'infNFe', true);
        $id = $this->fields->attribute($info, 'Id');
        // Remove exactly the known prefix; never strip arbitrary characters.
        $key = $this->fields->accessKey(str_starts_with($id, 'NFe') ? substr($id, 3) : $id);
        $version = $this->fields->attribute($info, 'versao');
        if (! preg_match('/\A[0-9]+\.[0-9]+\z/D', $version)
            || ($envelope === FiscalXmlEnvelope::ProcessedNfe && $root->hasAttribute('versao') && $root->getAttribute('versao') !== $version)) {
            throw new InvalidFiscalXml;
        }
        $identification = $this->identification($this->fields->child($info, 'ide', true));
        $issuer = $this->party($this->fields->child($info, 'emit', true), 'enderEmit');
        if ($issuer->name === null || ($issuer->cnpj === null && $issuer->cpf === null)) {
            throw new MissingRequiredFiscalField('emit/identificação');
        }
        $recipientNode = $this->fields->child($info, 'dest');
        $items = [];
        $seen = [];
        foreach ($this->fields->children($info, 'det') as $detail) {
            $item = $this->item($detail, $productAnalysis);
            if (isset($seen[$item->number])) {
                throw new InvalidFiscalXml;
            }
            $seen[$item->number] = true;
            $items[] = $item;
        }
        if ($items === []) {
            throw new MissingRequiredFiscalField('infNFe/det');
        }
        $totalNode = $this->fields->child($info, 'total');
        $icmsTotals = $totalNode ? $this->fields->child($totalNode, 'ICMSTot') : null;
        $protocolNode = $envelope === FiscalXmlEnvelope::ProcessedNfe ? $this->fields->child($root, 'protNFe') : null;
        $protocol = $protocolNode ? $this->protocol($this->fields->child($protocolNode, 'infProt', true), $key) : null;

        return new ParsedFiscalDocument(
            FiscalDocumentType::Nfe, $identification->model, $version, $key,
            $identification->number, $identification->series, $identification->issuedAt,
            $identification->operationNature, $identification, $issuer,
            $recipientNode ? $this->party($recipientNode, 'enderDest') : null,
            $items, $icmsTotals ? $this->totals($icmsTotals) : null, $protocol, $this->payments($info),
        );
    }

    private function identification(DOMElement $node): FiscalIdentification
    {
        $f = $this->fields;
        $model = $f->text($node, 'mod', true);
        if ($model !== '55') {
            throw new UnsupportedFiscalDocument;
        }
        $issuedTime = $f->text($node, 'dhEmi');
        $issuedDate = $issuedTime ?? $f->text($node, 'dEmi', true);
        $departedTime = $f->text($node, 'dhSaiEnt');
        $departedDate = $departedTime ?? $f->text($node, 'dSaiEnt');

        return new FiscalIdentification(
            $f->text($node, 'cUF'), $f->text($node, 'cNF'), $f->text($node, 'natOp'), $model,
            $f->digits($f->text($node, 'serie', true)), $f->digits($f->text($node, 'nNF', true)),
            $f->date($issuedDate, $issuedTime !== null), $issuedTime !== null,
            $departedDate !== null ? $f->date($departedDate, $departedTime !== null) : null,
            $departedDate !== null ? $departedTime !== null : null,
            $f->text($node, 'tpNF'), $f->text($node, 'idDest'), $f->text($node, 'cMunFG'),
            $f->text($node, 'tpImp'), $f->text($node, 'tpEmis'), $f->text($node, 'cDV'),
            $f->text($node, 'tpAmb'), $f->text($node, 'finNFe'), $f->text($node, 'indFinal'),
            $f->text($node, 'indPres'), $f->text($node, 'procEmi'), $f->text($node, 'verProc'),
        );
    }

    private function party(DOMElement $node, string $addressTag): FiscalParty
    {
        $f = $this->fields;
        $address = $f->child($node, $addressTag);

        return new FiscalParty(
            $f->text($node, 'CNPJ'), $f->text($node, 'CPF'), $f->text($node, 'xNome'),
            $f->text($node, 'xFant'), $f->text($node, 'IE'), $f->text($node, 'CRT'),
            $f->text($node, 'indIEDest'), $f->text($node, 'email'),
            $address ? new FiscalAddress(
                $f->text($address, 'xLgr'), $f->text($address, 'nro'), $f->text($address, 'xCpl'),
                $f->text($address, 'xBairro'), $f->text($address, 'cMun'), $f->text($address, 'xMun'),
                $f->text($address, 'UF'), $f->text($address, 'CEP'), $f->text($address, 'cPais'),
                $f->text($address, 'xPais'), $f->text($address, 'fone'),
            ) : null,
        );
    }

    private function item(DOMElement $detail, bool $productAnalysis): ParsedFiscalItem
    {
        $f = $this->fields;
        $number = $f->attribute($detail, 'nItem');
        if (! preg_match('/\A[1-9][0-9]{0,2}\z/D', $number) || (int) $number > 990) {
            throw new InvalidFiscalXml;
        }
        $product = $f->child($detail, 'prod', true);
        $tax = $f->child($detail, 'imposto');

        return new ParsedFiscalItem(
            (int) $number, $f->text($product, 'cProd', ! $productAnalysis) ?? '', $f->text($product, 'xProd', true),
            $f->text($product, 'NCM', true), $f->text($product, 'CFOP', true), $f->text($product, 'uCom', true),
            $f->decimal($product, 'qCom', true), $f->decimal($product, 'vUnCom', ! $productAnalysis), $f->decimal($product, 'vProd', true),
            $f->text($product, 'cEAN'), $f->text($product, 'CEST'), $f->text($product, 'cEANTrib'),
            $f->text($product, 'uTrib'), $f->decimal($product, 'qTrib'), $f->decimal($product, 'vUnTrib'),
            $f->text($product, 'indTot'), $f->text($product, 'xPed'), $f->text($product, 'nItemPed'),
            $tax ? $f->decimal($tax, 'vTotTrib') : null, $this->taxes->parse($tax),
        );
    }

    private function totals(DOMElement $node): FiscalTotals
    {
        $f = $this->fields;

        return new FiscalTotals(
            $f->decimal($node, 'vBC'), $f->decimal($node, 'vICMS'), $f->decimal($node, 'vICMSDeson'),
            $f->decimal($node, 'vFCP'), $f->decimal($node, 'vBCST'), $f->decimal($node, 'vST'),
            $f->decimal($node, 'vFCPST'), $f->decimal($node, 'vProd'), $f->decimal($node, 'vFrete'),
            $f->decimal($node, 'vSeg'), $f->decimal($node, 'vDesc'), $f->decimal($node, 'vII'),
            $f->decimal($node, 'vIPI'), $f->decimal($node, 'vIPIDevol'), $f->decimal($node, 'vPIS'),
            $f->decimal($node, 'vCOFINS'), $f->decimal($node, 'vOutro'), $f->decimal($node, 'vNF'), $f->decimal($node, 'vTotTrib'),
        );
    }

    private function protocol(DOMElement $node, string $key): FiscalProtocol
    {
        $f = $this->fields;
        $protocolKey = $f->accessKey($f->text($node, 'chNFe', true));
        if ($protocolKey !== $key) {
            throw new InvalidFiscalXml;
        }
        $date = $f->text($node, 'dhRecbto');

        return new FiscalProtocol(
            $f->text($node, 'tpAmb'), $f->text($node, 'verAplic'), $protocolKey,
            $date ? $f->date($date, true) : null, $f->text($node, 'nProt'), $f->text($node, 'digVal'),
            $f->text($node, 'cStat'), $f->text($node, 'xMotivo'),
        );
    }

    /** @return list<FiscalPayment> */
    private function payments(DOMElement $info): array
    {
        $f = $this->fields;
        $payments = [];
        // Earlier layouts place tPag/vPag directly in pag; newer ones use detPag.
        foreach ($f->children($info, 'pag') as $group) {
            $details = $f->children($group, 'detPag');
            if ($details === [] && ($f->child($group, 'tPag') || $f->child($group, 'vPag'))) {
                $details = [$group];
            }
            foreach ($details as $detail) {
                $payments[] = new FiscalPayment($f->text($detail, 'tPag'), $f->decimal($detail, 'vPag'));
            }
        }

        return $payments;
    }
}
