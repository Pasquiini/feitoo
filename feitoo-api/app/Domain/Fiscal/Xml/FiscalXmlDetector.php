<?php

declare(strict_types=1);

namespace App\Domain\Fiscal\Xml;

use App\Domain\Fiscal\Exceptions\InvalidFiscalXml;
use App\Domain\Fiscal\Exceptions\UnsupportedFiscalDocument;
use App\Support\Xml\InvalidXml;
use App\Support\Xml\SecureXml;
use DOMDocument;

final class FiscalXmlDetector
{
    public const NFE_NAMESPACE = 'http://www.portalfiscal.inf.br/nfe';

    public function detect(string $xml): FiscalXmlEnvelope
    {
        try {
            return $this->inspect((new SecureXml)->load($xml));
        } catch (InvalidXml) {
            throw new InvalidFiscalXml;
        }
    }

    /** Internal DOM entry point, also used by NfeParser to avoid loading twice. */
    public function inspect(DOMDocument $document): FiscalXmlEnvelope
    {
        $root = $document->documentElement;
        if (! $root || $root->namespaceURI !== self::NFE_NAMESPACE) {
            throw new UnsupportedFiscalDocument;
        }

        return FiscalXmlEnvelope::tryFrom($root->localName) ?? throw new UnsupportedFiscalDocument;
    }
}
