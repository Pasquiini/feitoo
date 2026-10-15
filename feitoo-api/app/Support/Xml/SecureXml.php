<?php

declare(strict_types=1);

namespace App\Support\Xml;

use DOMDocument;
use XMLReader;

final class SecureXml
{
    public function validate(string $contents, bool $allowMalformed = false): void
    {
        if ($contents === '' || strlen($contents) > config('feitoo.uploads.max_file_size_kb') * 1024
            || preg_match('/<!\s*(?:DOCTYPE|ENTITY)\b/i', str_replace("\0", '', $contents))) {
            throw new InvalidXml;
        }
        $this->guard(function () use ($contents, $allowMalformed): void {
            $reader = new XMLReader;
            try {
                if (! $reader->XML($contents, null, LIBXML_NONET)) {
                    throw new InvalidXml;
                }
                foreach ([XMLReader::LOADDTD, XMLReader::VALIDATE, XMLReader::SUBST_ENTITIES, XMLReader::DEFAULTATTRS] as $property) {
                    $reader->setParserProperty($property, false);
                }
                $nodes = 0;
                $rootSeen = false;
                $deadline = hrtime(true) + config('feitoo.uploads.xml_validation_seconds') * 1_000_000_000;
                while ($reader->read()) {
                    $nodes++;
                    $rootSeen = $rootSeen || $reader->nodeType === XMLReader::ELEMENT;
                    if (in_array($reader->nodeType, [XMLReader::DOC_TYPE, XMLReader::ENTITY, XMLReader::ENTITY_REF], true)
                        || $reader->depth > config('feitoo.uploads.max_xml_depth')
                        || $nodes > config('feitoo.uploads.max_xml_nodes') || hrtime(true) > $deadline) {
                        throw new InvalidXml;
                    }
                }
                if (! $allowMalformed && (! $rootSeen || libxml_get_errors() !== [])) {
                    throw new InvalidXml;
                }
            } finally {
                $reader->close();
            }
        });
    }

    public function load(string $contents): DOMDocument
    {
        // Bounded streaming validation runs before allocating a DOM tree.
        $this->validate($contents);
        $document = new DOMDocument;
        $document->resolveExternals = false;
        $document->substituteEntities = false;
        $document->validateOnParse = false;
        $this->guard(function () use ($document, $contents): void {
            if (! $document->loadXML($contents, LIBXML_NONET) || $document->doctype !== null || libxml_get_errors() !== []) {
                throw new InvalidXml;
            }
        });

        return $document;
    }

    private function guard(\Closure $operation): void
    {
        $previousErrors = libxml_use_internal_errors(true);
        $previousLoader = libxml_get_external_entity_loader();
        libxml_set_external_entity_loader(static fn () => null);
        libxml_clear_errors();
        try {
            $operation();
        } finally {
            // Diagnostics may contain fiscal data or private paths.
            libxml_clear_errors();
            libxml_set_external_entity_loader($previousLoader);
            libxml_use_internal_errors($previousErrors);
        }
    }
}
