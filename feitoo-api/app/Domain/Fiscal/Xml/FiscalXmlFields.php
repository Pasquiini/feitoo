<?php

declare(strict_types=1);

namespace App\Domain\Fiscal\Xml;

use App\Domain\Fiscal\Exceptions\InvalidFiscalXml;
use App\Domain\Fiscal\Exceptions\MissingRequiredFiscalField;
use DateTimeImmutable;
use DateTimeZone;
use DOMElement;

/** Internal navigation: direct children only, exact namespace, no descendant fallback. */
final class FiscalXmlFields
{
    /** @return list<DOMElement> */
    public function children(DOMElement $parent, string $name): array
    {
        $found = [];
        foreach ($parent->childNodes as $node) {
            if ($node instanceof DOMElement && $node->localName === $name) {
                if ($node->namespaceURI !== FiscalXmlDetector::NFE_NAMESPACE) {
                    throw new InvalidFiscalXml;
                }
                $found[] = $node;
            }
        }

        return $found;
    }

    public function child(DOMElement $parent, string $name, bool $required = false): ?DOMElement
    {
        $children = $this->children($parent, $name);
        if (count($children) > 1) {
            throw new InvalidFiscalXml;
        }
        if ($required && $children === []) {
            throw new MissingRequiredFiscalField($parent->localName.'/'.$name);
        }

        return $children[0] ?? null;
    }

    public function text(DOMElement $parent, string $name, bool $required = false): ?string
    {
        $node = $this->child($parent, $name, $required);
        if ($node) {
            foreach ($node->childNodes as $child) {
                if ($child instanceof DOMElement) {
                    throw new InvalidFiscalXml;
                }
            }
        }
        $text = $node ? trim($node->textContent) : '';
        if ($text === '' && $required) {
            throw new MissingRequiredFiscalField($parent->localName.'/'.$name);
        }

        return $text === '' ? null : $text;
    }

    public function attribute(DOMElement $node, string $name): string
    {
        $value = trim($node->getAttribute($name));
        if ($value === '') {
            throw new MissingRequiredFiscalField($node->localName.'/@'.$name);
        }

        return $value;
    }

    public function decimal(DOMElement $parent, string $name, bool $required = false): ?string
    {
        $text = $this->text($parent, $name, $required);
        if ($text === null) {
            return null;
        }
        if (strlen($text) > 128 || ! preg_match('/\A[0-9]+(?:\.[0-9]+)?\z/D', $text)) {
            throw new InvalidFiscalXml;
        }
        $parts = explode('.', $text, 2);
        $integer = ltrim($parts[0], '0');

        return ($integer === '' ? '0' : $integer).(isset($parts[1]) ? '.'.$parts[1] : '');
    }

    public function digits(string $value): string
    {
        if (strlen($value) > 44 || ! preg_match('/\A[0-9]+\z/D', $value)) {
            throw new InvalidFiscalXml;
        }

        return $value;
    }

    public function accessKey(string $value): string
    {
        if (strlen($value) !== 44) {
            throw new InvalidFiscalXml;
        }

        return $this->digits($value);
    }

    public function date(string $value, bool $hasTime): DateTimeImmutable
    {
        $pattern = $hasTime ? '/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:[+-]\d{2}:\d{2}|Z)\z/D' : '/\A\d{4}-\d{2}-\d{2}\z/D';
        if (! preg_match($pattern, $value)) {
            throw new InvalidFiscalXml;
        }
        if ($hasTime && preg_match('/([+-])(\d{2}):(\d{2})\z/', $value, $offset)
            && ((int) $offset[2] > 14 || (int) $offset[3] > 59 || ((int) $offset[2] === 14 && $offset[3] !== '00'))) {
            throw new InvalidFiscalXml;
        }
        $date = DateTimeImmutable::createFromFormat($hasTime ? '!Y-m-d\TH:i:sP' : '!Y-m-d', $value, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] || $errors['error_count']))) {
            throw new InvalidFiscalXml;
        }

        return $date;
    }
}
