<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Fiscal\Nfe\NfeParser;
use App\Domain\Tools\XmlToExcel\XmlToExcelGenerator;
use DOMDocument;
use DOMXPath;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use XMLReader;

class BenchmarkXmlToExcel extends Command
{
    protected $signature = 'feitoo:benchmark-xml-to-excel {--documents=10} {--items=20} {--keep : Keep the synthetic workbook on the private disk}';

    protected $description = 'Measure synthetic NF-e parsing and XLSX generation locally; never processes user files';

    public function handle(NfeParser $parser, XmlToExcelGenerator $generator): int
    {
        if (! app()->environment('local', 'testing')) {
            $this->error('Benchmark available only in local/testing environments.');

            return self::FAILURE;
        }
        $documents = filter_var($this->option('documents'), FILTER_VALIDATE_INT);
        $items = filter_var($this->option('items'), FILTER_VALIDATE_INT);
        if ($documents === false || $documents < 1 || $documents > 500 || $items === false || $items < 1 || $items > 990) {
            $this->error('Use 1..500 documents and 1..990 items per document.');

            return self::FAILURE;
        }
        $template = $this->template($items);
        $xmlNodes = 0;
        $reader = new XMLReader;
        $reader->XML($template, null, LIBXML_NONET);
        while ($reader->read()) {
            $xmlNodes++;
        }
        $reader->close();
        $temporary = tempnam(sys_get_temp_dir(), 'feitoo-benchmark-');
        if ($temporary === false) {
            throw new RuntimeException('Benchmark temporary file unavailable.');
        }
        try {
            memory_reset_peak_usage();
            $baseline = memory_get_usage(true);
            $parsing = 0.0;
            for ($index = 1; $index <= $documents; $index++) {
                $xml = $this->document($template, $index);
                $start = hrtime(true);
                $parsed = $parser->parse($xml);
                $parsing += (hrtime(true) - $start) / 1e9;
                unset($parsed, $xml);
            }
            $reparsing = 0.0;
            $source = (function () use ($documents, $template, $parser, &$reparsing) {
                for ($index = 1; $index <= $documents; $index++) {
                    $xml = $this->document($template, $index);
                    $start = hrtime(true);
                    $document = $parser->parse($xml);
                    $reparsing += (hrtime(true) - $start) / 1e9;
                    unset($xml);
                    yield $document;
                    unset($document);
                }
            })();
            $start = hrtime(true);
            $generator->generate($source, $temporary);
            $generation = (hrtime(true) - $start) / 1e9;
            $metrics = [
                'documents' => $documents, 'items_per_document' => $items, 'items' => $documents * $items,
                'synthetic_xml_size_bytes' => strlen($template), 'synthetic_xml_nodes' => $xmlNodes,
                'parsing_seconds' => round($parsing, 4), 'reparsing_seconds' => round($reparsing, 4),
                'generation_seconds' => round($generation, 4),
                'xlsx_seconds_excluding_reparsing' => round($generation - $reparsing, 4),
                'estimated_two_pass_seconds' => round($parsing + $generation, 4),
                'baseline_memory_mb' => round($baseline / 1048576, 2),
                'peak_memory_mb' => round(memory_get_peak_usage(true) / 1048576, 2),
                'file_size_bytes' => filesize($temporary), 'php_memory_limit' => ini_get('memory_limit'),
            ];
            if ($this->option('keep')) {
                $disk = Storage::disk(config('feitoo.processing.disk'));
                $path = 'benchmarks/synthetic-'.Str::uuid().'.xlsx';
                $stream = fopen($temporary, 'rb');
                try {
                    if (! $disk->put($path, $stream, ['visibility' => 'private'])) {
                        throw new RuntimeException('Synthetic workbook storage failed.');
                    }
                } finally {
                    fclose($stream);
                }
                $metrics['synthetic_output'] = $path;
            }
            $this->line(json_encode($metrics, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } catch (Throwable $exception) {
            $this->line(json_encode([
                'documents' => $documents, 'items_per_document' => $items, 'items' => $documents * $items,
                'synthetic_xml_size_bytes' => strlen($template), 'synthetic_xml_nodes' => $xmlNodes,
                'failed' => true, 'exception_type' => $exception::class,
                'peak_memory_mb' => round(memory_get_peak_usage(true) / 1048576, 2),
            ], JSON_THROW_ON_ERROR));
            $this->error('Synthetic benchmark failed. XML safety limits remain enforced; no content was logged.');

            return self::FAILURE;
        } finally {
            unlink($temporary);
        }

        return self::SUCCESS;
    }

    public function template(int $items): string
    {
        // This development command deliberately uses only the checked-in synthetic fixture.
        $xml = file_get_contents(base_path('tests/Fixtures/Fiscal/nfe-proc.xml'));
        $tree = new DOMDocument;
        $tree->loadXML($xml, LIBXML_NONET);
        $xpath = new DOMXPath($tree);
        $xpath->registerNamespace('n', 'http://www.portalfiscal.inf.br/nfe');
        $details = iterator_to_array($xpath->query('//n:det'));
        $template = $details[0];
        $parent = $template->parentNode;
        foreach ($details as $detail) {
            $parent->removeChild($detail);
        }
        for ($index = 1; $index <= $items; $index++) {
            $detail = $template->cloneNode(true);
            $detail->setAttribute('nItem', (string) $index);
            $xpath->query('.//n:cProd', $detail)->item(0)->nodeValue = 'SYNTHETIC-'.str_pad((string) $index, 4, '0', STR_PAD_LEFT);
            $parent->appendChild($detail);
        }

        return $tree->saveXML();
    }

    public function document(string $template, int $index): string
    {
        $key = substr('35261000000000000100550010000001231000001234', 0, 35).str_pad((string) $index, 9, '0', STR_PAD_LEFT);

        return str_replace(['35261000000000000100550010000001231000001234', '<nNF>123</nNF>'],
            [$key, '<nNF>'.$index.'</nNF>'], $template);
    }
}
