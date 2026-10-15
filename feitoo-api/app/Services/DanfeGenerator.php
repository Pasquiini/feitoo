<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Fiscal\DTO\ParsedFiscalDocument;
use App\Domain\Fiscal\Exceptions\InvalidFiscalXml;
use App\Support\Xml\SecureXml;
use NFePHP\DA\NFe\Danfe;
use RuntimeException;

class DanfeGenerator
{
    public const NOTICE = 'Status atual na SEFAZ não consultado. Protocolo informado no XML; assinatura digital não verificada.';

    public function validate(string $xml, ParsedFiscalDocument $document): void
    {
        $p = $document->protocol;
        $i = $document->identification;
        // Refuse drafts/rejections instead of letting the renderer imply authorization.
        if (! $p || ! in_array($p->statusCode, ['100', '150'], true)
            || ! preg_match('/\A[0-9]{15}\z/', $p->number ?? '') || ! $p->receivedAt
            || ! $p->digest || $p->environment !== $i->environment
            || ! in_array($i->environment, ['1', '2'], true)) {
            throw new DanfeProtocolException;
        }
        $key = $document->accessKey;
        $sum = 0;
        for ($n = 42, $weight = 2; $n >= 0; $n--, $weight = $weight === 9 ? 2 : $weight + 1) {
            $sum += (int) $key[$n] * $weight;
        }
        $digit = 11 - $sum % 11;
        $digit = $digit >= 10 ? 0 : $digit;
        $issuer = $document->issuer->cnpj ?? str_pad($document->issuer->cpf ?? '', 14, '0', STR_PAD_LEFT);
        if ((int) $key[43] !== $digit || $i->checkDigit !== $key[43]
            || substr($key, 0, 2) !== $i->stateCode || substr($key, 2, 4) !== $i->issuedAt->format('ym')
            || substr($key, 6, 14) !== $issuer || substr($key, 20, 2) !== '55'
            || (int) substr($key, 22, 3) !== (int) $document->series
            || (int) substr($key, 25, 9) !== (int) $document->number
            || substr($key, 34, 1) !== $i->emissionType || substr($key, 35, 8) !== $i->numericCode
            || $p->receivedAt < $i->issuedAt || $document->totals?->vNF === null || ! $document->recipient) {
            throw new InvalidFiscalXml;
        }
        $tree = app(SecureXml::class)->load($xml);
        $xpath = new \DOMXPath($tree);
        $xpath->registerNamespace('n', 'http://www.portalfiscal.inf.br/nfe');
        $xpath->registerNamespace('ds', 'http://www.w3.org/2000/09/xmldsig#');
        // The library uses tag-name lookups. Reject wrapping/foreign namespace lookalikes
        // so the validated document is exactly the one subsequently rendered.
        foreach (['NFe', 'infNFe', 'ide', 'emit', 'dest', 'total', 'ICMSTot', 'transp', 'protNFe', 'infProt'] as $tag) {
            if ($tree->getElementsByTagName($tag)->length !== 1) {
                throw new InvalidFiscalXml;
            }
        }
        if ($xpath->evaluate('count(/n:nfeProc/n:NFe/n:infNFe/n:transp)') !== 1.0
            || $xpath->evaluate('count(/n:nfeProc/*)') !== 2.0
            || $xpath->evaluate('count(//*[namespace-uri() != "http://www.portalfiscal.inf.br/nfe" and namespace-uri() != "http://www.w3.org/2000/09/xmldsig#"])') !== 0.0) {
            throw new InvalidFiscalXml;
        }
        $digest = $xpath->evaluate('string(/n:nfeProc/n:NFe/ds:Signature/ds:SignedInfo/ds:Reference[@URI="#NFe'.$key.'"]/ds:DigestValue)');
        if ($digest === '' || ! hash_equals($p->digest, trim($digest))) {
            throw new DanfeProtocolException;
        }
    }

    public function render(string $xml): string
    {
        app(SecureXml::class)->validate($xml);
        // Keep the network/entity guard active during third-party parsing as well,
        // including any embedded additional-information XML the library may parse.
        $loader = libxml_get_external_entity_loader();
        $errors = libxml_use_internal_errors(true);
        libxml_set_external_entity_loader(static fn () => null);
        libxml_clear_errors();
        try {
            $danfe = new Danfe($xml);
            $danfe->creditsIntegratorFooter(self::NOTICE, false);
            $pdf = $danfe->render();
        } finally {
            libxml_clear_errors();
            libxml_set_external_entity_loader($loader);
            libxml_use_internal_errors($errors);
        }
        if (! str_starts_with($pdf, '%PDF-')) {
            throw new RuntimeException('DANFE renderer returned invalid output.');
        }

        return $pdf;
    }
}
