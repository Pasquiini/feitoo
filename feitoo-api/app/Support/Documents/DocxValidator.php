<?php

declare(strict_types=1);

namespace App\Support\Documents;

use App\Enums\ProcessingFileError as Error;
use DOMDocument;
use DOMXPath;
use ZipArchive;

final class DocxValidator
{
    public function validate(string $path, string $extension = 'docx', ?string $mime = null): void
    {
        if (strtolower($extension) !== 'docx' || ($mime !== null && ! in_array($mime, [
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/zip', 'application/x-zip', 'application/x-zip-compressed', 'application/octet-stream',
        ], true))) {
            throw new DocumentException(Error::DocxInvalid);
        }
        if (! is_file($path) || filesize($path) === 0) {
            throw new DocumentException(Error::DocxCorrupted);
        }
        if (filesize($path) > config('documents.max_file_size_kb') * 1024) {
            throw new DocumentException(Error::DocxTooLarge);
        }
        $header = fopen($path, 'rb');
        try {
            if (! $header || fread($header, 4) !== "PK\x03\x04") {
                throw new DocumentException(Error::DocxInvalid);
            }
        } finally {
            if (is_resource($header)) {
                fclose($header);
            }
        }
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CHECKCONS | ZipArchive::RDONLY) !== true) {
            throw new DocumentException(Error::DocxCorrupted);
        }
        $deadline = microtime(true) + config('documents.validation_seconds');
        try {
            if ($zip->numFiles < 3 || $zip->numFiles > config('documents.max_entries')) {
                throw new DocumentException(Error::DocxArchiveUnsafe);
            }
            $total = 0;
            $seen = [];
            $components = [];
            // First inspect ALL central-directory entries before reading any expanded bytes.
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if (! $stat) {
                    throw new DocumentException(Error::DocxCorrupted);
                }
                $name = $stat['name'];
                $folded = strtolower($name);
                $opsys = $attributes = 0;
                $zip->getExternalAttributesIndex($i, $opsys, $attributes);
                if (strlen($name) > 240 || preg_match('~[\\\\:\x00-\x1f\x7f]|^/|(?:^|/)\.{1,2}(?:/|$)|//~', $name)
                    || isset($seen[$folded]) || (($attributes >> 16) & 0170000) === 0120000
                    || ($stat['encryption_method'] ?? 0) !== 0
                    || ! in_array($stat['comp_method'], [ZipArchive::CM_STORE, ZipArchive::CM_DEFLATE], true)
                    || preg_match('~vba|activex|embeddings/|\.bin$|\.exe$|\.dll$|\.js$~i', $name)) {
                    throw new DocumentException(Error::DocxArchiveUnsafe);
                }
                $seen[$folded] = true;
                $total += $stat['size'];
                if ($total > config('documents.max_uncompressed_bytes')
                    || $stat['size'] > max(1, $stat['comp_size']) * config('documents.max_ratio')) {
                    throw new DocumentException(Error::DocxArchiveUnsafe);
                }
            }
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $name = $stat['name'];
                if (str_ends_with($name, '/')) {
                    continue;
                }
                $stream = $zip->getStream($name);
                if (! is_resource($stream)) {
                    throw new DocumentException(Error::DocxCorrupted);
                }
                $isXml = str_ends_with(strtolower($name), '.xml') || str_ends_with(strtolower($name), '.rels');
                $xml = '';
                $size = 0;
                $crc = hash_init('crc32b');
                try {
                    while (! feof($stream)) {
                        $chunk = fread($stream, 65536);
                        if ($chunk === false) {
                            throw new DocumentException(Error::DocxCorrupted);
                        }
                        $size += strlen($chunk);
                        if ($size > $stat['size'] || microtime(true) > $deadline || ($isXml && $size > config('documents.max_xml_bytes'))) {
                            throw new DocumentException(Error::DocxArchiveUnsafe);
                        }
                        hash_update($crc, $chunk);
                        if ($isXml) {
                            $xml .= $chunk;
                        }
                    }
                } finally {
                    fclose($stream);
                }
                if ($size !== $stat['size'] || hash_final($crc) !== sprintf('%08x', $stat['crc'])) {
                    throw new DocumentException(Error::DocxCorrupted);
                }
                if ($isXml) {
                    $document = $this->xml($xml);
                    if (microtime(true) > $deadline) {
                        throw new DocumentException(Error::DocxArchiveUnsafe);
                    }
                    $xpath = new DOMXPath($document);
                    // External hyperlinks are inert in PDF. All other external relationships
                    // (images, templates, data, OLE, linked files) are refused before conversion.
                    foreach ($xpath->query('//*[local-name()="Relationship"]') as $relationship) {
                        $target = $relationship->getAttribute('Target');
                        if ($relationship->getAttribute('TargetMode') === 'External') {
                            if (! str_ends_with($relationship->getAttribute('Type'), '/hyperlink')
                                || ! preg_match('~^(?:https?://|mailto:)[^\x00-\x20]+$~i', $target)) {
                                throw new DocumentException(Error::DocxArchiveUnsafe);
                            }
                        } elseif (preg_match('~^[a-z][a-z0-9+.-]*:|^[/\\\\]|\\\\~i', $target)) {
                            throw new DocumentException(Error::DocxArchiveUnsafe);
                        }
                        if ($relationship->getAttribute('TargetMode') !== 'External') {
                            $decoded = rawurldecode(explode('#', $target)[0]);
                            if (preg_match('~^[a-z][a-z0-9+.-]*:|^[/\\\\]|[\\\\\x00-\x1f]~i', $decoded)) {
                                throw new DocumentException(Error::DocxArchiveUnsafe);
                            }
                            $base = str_ends_with($name, '.rels') ? dirname(str_replace('/_rels/', '/', $name)) : '';
                            $parts = $base === '.' || $name === '_rels/.rels' ? [] : explode('/', $base);
                            foreach (explode('/', $decoded) as $segment) {
                                if ($segment === '..') {
                                    if (count($parts) === 0) {
                                        throw new DocumentException(Error::DocxArchiveUnsafe);
                                    }
                                    array_pop($parts);
                                } elseif ($segment !== '' && $segment !== '.') {
                                    $parts[] = $segment;
                                }
                            }
                        }
                    }
                    foreach ($xpath->query('//@*[local-name()="ContentType"]') as $type) {
                        if (preg_match('~macroEnabled|vba|oleObject|activeX~i', $type->nodeValue)) {
                            throw new DocumentException(Error::DocxArchiveUnsafe);
                        }
                    }
                    if ($xpath->query('//*[local-name()="fldSimple" or local-name()="instrText" or local-name()="altChunk" or local-name()="object"]')->length > 0) {
                        throw new DocumentException(Error::DocxArchiveUnsafe);
                    }
                    if (in_array($name, ['[Content_Types].xml', '_rels/.rels', 'word/document.xml'], true)) {
                        $components[$name] = $document;
                    }
                }
            }
            if (count($components) !== 3
                || $components['[Content_Types].xml']->documentElement?->namespaceURI !== 'http://schemas.openxmlformats.org/package/2006/content-types'
                || $components['_rels/.rels']->documentElement?->namespaceURI !== 'http://schemas.openxmlformats.org/package/2006/relationships'
                || $components['word/document.xml']->documentElement?->localName !== 'document'
                || ! in_array($components['word/document.xml']->documentElement?->namespaceURI, [
                    'http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'http://purl.oclc.org/ooxml/wordprocessingml/main',
                ], true)) {
                throw new DocumentException(Error::DocxInvalid);
            }
            $types = new DOMXPath($components['[Content_Types].xml']);
            $rels = new DOMXPath($components['_rels/.rels']);
            if ($types->query('//*[local-name()="Override" and @PartName="/word/document.xml" and @ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"]')->length !== 1
                || $rels->query('//*[local-name()="Relationship" and @Target="word/document.xml" and not(@TargetMode="External") and (substring(@Type, string-length(@Type) - 14)="/officeDocument")]')->length !== 1) {
                throw new DocumentException(Error::DocxInvalid);
            }
        } finally {
            $zip->close();
        }
    }

    private function xml(string $xml): DOMDocument
    {
        if (str_contains($xml, "\0") || preg_match('/<!DOCTYPE|<!ENTITY/i', $xml)) {
            throw new DocumentException(Error::DocxArchiveUnsafe);
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument;
            if (! $document->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT)) {
                throw new DocumentException(Error::DocxCorrupted);
            }

            return $document;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
