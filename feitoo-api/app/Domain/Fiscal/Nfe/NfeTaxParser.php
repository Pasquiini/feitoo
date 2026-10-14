<?php

declare(strict_types=1);

namespace App\Domain\Fiscal\Nfe;

use App\Domain\Fiscal\DTO\FiscalTaxElement;
use App\Domain\Fiscal\DTO\FiscalTaxGroup;
use App\Domain\Fiscal\DTO\FiscalTaxType;
use App\Domain\Fiscal\Exceptions\InvalidFiscalXml;
use App\Domain\Fiscal\Xml\FiscalXmlDetector;
use App\Domain\Fiscal\Xml\FiscalXmlFields;
use DOMElement;

final class NfeTaxParser
{
    /** @return list<FiscalTaxGroup> */
    public function parse(?DOMElement $taxes): array
    {
        if (! $taxes) {
            return [];
        }
        $groups = [];
        $fields = new FiscalXmlFields;
        foreach (FiscalTaxType::cases() as $type) {
            $group = $fields->child($taxes, $type->value);
            if ($group) {
                $groups[] = new FiscalTaxGroup($type, $this->element($group));
            }
        }

        return $groups;
    }

    private function element(DOMElement $element): FiscalTaxElement
    {
        $children = [];
        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement) {
                if ($child->namespaceURI !== FiscalXmlDetector::NFE_NAMESPACE) {
                    throw new InvalidFiscalXml;
                }
                $children[] = $this->element($child);
            }
        }
        $attributes = [];
        foreach ($element->attributes as $attribute) {
            if ($attribute->namespaceURI !== 'http://www.w3.org/2000/xmlns/') {
                $attributes[$attribute->nodeName] = $attribute->value;
            }
        }
        $text = trim($element->textContent);

        return new FiscalTaxElement($element->localName, $children === [] && $text !== '' ? $text : null, $children, $attributes);
    }
}
