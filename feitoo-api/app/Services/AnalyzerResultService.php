<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Fiscal\DTO\ParsedFiscalDocument;
use App\Enums\ProcessingFileKind;
use App\Enums\ProcessingFileStatus;
use App\Models\Processing;
use App\Models\ProcessingFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class AnalyzerResultService
{
    public const MAX_BYTES = 16 * 1024 * 1024;

    public const SNAPSHOT_BUDGET = 8 * 1024 * 1024;

    public function __construct(private readonly AnalyzerAggregator $aggregator, private readonly AnalyzerExcelGenerator $excel) {}

    public function snapshot(Processing $processing, ProcessingFile $file, ParsedFiscalDocument $document): bool
    {
        $meta = $processing->metadata ?? [];
        $normalized = $this->aggregator->document($document);
        $overlong = false;
        array_walk_recursive($normalized, function ($value) use (&$overlong): void {
            if (is_string($value) && mb_strlen($value, 'UTF-8') > 2048) {
                $overlong = true;
            }
        });
        if ($overlong) {
            return false;
        }
        $data = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (($meta['analyzer_bytes'] ?? 0) + strlen($data) > self::SNAPSHOT_BUDGET
            || ($meta['analyzer_items'] ?? 0) + count($document->items) > config('feitoo.uploads.max_processing_items')) {
            return false;
        }
        $this->put($processing, 'inputs/'.$file->uuid.'.json', $data);
        $meta['analyzer_bytes'] = ($meta['analyzer_bytes'] ?? 0) + strlen($data);
        $meta['analyzer_items'] = ($meta['analyzer_items'] ?? 0) + count($document->items);
        $processing->update(['metadata' => $meta]);

        return true;
    }

    public function generate(Processing $processing): void
    {
        if ($processing->output()->exists()) {
            return;
        }
        $files = [];
        foreach ($processing->files()->where('kind', 'input')->whereNull('removed_at')->orderBy('id')->cursor() as $file) {
            $data = $file->status === ProcessingFileStatus::Completed ? $this->read($processing, 'inputs/'.$file->uuid.'.json') : null;
            $files[] = ['file_id' => $file->uuid, 'file_name' => $file->original_name, 'checksum' => $file->checksum, 'data' => $data, 'error_message' => $file->error_message];
        }
        $files = $this->aggregator->classify($files);
        $view = $this->aggregator->view($files);
        $dataset = $this->encode($files);
        $this->put($processing, 'data.json', $dataset);
        $this->put($processing, 'default.json', $this->encode($view));
        $path = $this->writeExcel($processing, $view, 'output/analysis.xlsx');
        $disk = Storage::disk(config('feitoo.processing.disk'));
        $processing->files()->create([
            'uuid' => (string) Str::uuid(), 'kind' => ProcessingFileKind::Output, 'status' => ProcessingFileStatus::Completed,
            'original_name' => 'feitoo-analise-xml.xlsx', 'stored_name' => 'analysis.xlsx',
            'storage_disk' => config('feitoo.processing.disk'), 'storage_path' => $path,
            'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'extension' => 'xlsx',
            'size' => $disk->size($path), 'checksum' => hash('sha256', $disk->get($path)), 'expires_at' => $processing->expires_at, 'processed_at' => now(),
        ]);
        $meta = $processing->metadata ?? [];
        $meta['analyzer_summary'] = $view['summary'];
        $meta['analyzer_audit'] = $view['audit'];
        $processing->update(['metadata' => $meta]);
    }

    public function view(Processing $processing, array $filters): array
    {
        $filters = array_filter($filters, fn ($v) => $v !== null && $v !== '');
        if (! $filters) {
            return $this->read($processing, 'default.json');
        }
        $name = 'views/'.hash('sha256', $this->encode($filters));
        $disk = Storage::disk(config('feitoo.processing.disk'));
        if ($disk->exists($this->path($processing, $name.'.json'))) {
            return $this->read($processing, $name.'.json');
        }
        $view = $this->aggregator->view($this->read($processing, 'data.json'), $filters);
        // At most four cached filter scopes, all private and removed by the normal purge.
        $cached = array_values(array_filter($disk->files($this->path($processing, 'views')), fn ($p) => str_ends_with($p, '.json')));
        usort($cached, fn ($a, $b) => $disk->lastModified($a) <=> $disk->lastModified($b) ?: strcmp($a, $b));
        while (count($cached) >= 4) {
            $old = array_shift($cached);
            $disk->delete([$old, substr($old, 0, -5).'.xlsx']);
        }
        $this->put($processing, $name.'.json', $this->encode($view));

        return $view;
    }

    public function export(Processing $processing, array $filters): string
    {
        $view = $this->view($processing, $filters);
        $active = array_filter($filters, fn ($v) => $v !== null && $v !== '');
        if (! $active) {
            return $processing->output()->firstOrFail()->storage_path;
        }
        $name = 'views/'.hash('sha256', $this->encode($active)).'.xlsx';
        $disk = Storage::disk(config('feitoo.processing.disk'));
        $path = $this->path($processing, $name);

        return $disk->exists($path) ? $path : $this->writeExcel($processing, $view, $name);
    }

    private function writeExcel(Processing $processing, array $view, string $name): string
    {
        $local = Storage::disk('local');
        $directory = 'analyzer-temporary/'.$processing->uuid;
        if (! $local->makeDirectory($directory)) {
            throw new RuntimeException('Analyzer staging unavailable.');
        }
        $temporary = $local->path($directory.'/analysis.xlsx');
        $path = str_starts_with($name, 'output/') ? 'processings/'.$processing->uuid.'/'.$name : $this->path($processing, $name);
        $disk = Storage::disk(config('feitoo.processing.disk'));
        try {
            $this->excel->generate($view, $temporary);
            $stream = fopen($temporary, 'rb');
            if (! is_resource($stream)) {
                throw new RuntimeException('Analyzer workbook unavailable.');
            }
            try {
                if (! $disk->put($path, $stream, ['visibility' => 'private'])) {
                    throw new RuntimeException('Analyzer workbook storage failed.');
                }
            } finally {
                fclose($stream);
            }
        } catch (Throwable $exception) {
            $disk->delete($path);
            throw $exception;
        } finally {
            $local->deleteDirectory($directory);
        }

        return $path;
    }

    private function path(Processing $p, string $name): string
    {
        return 'processings/'.$p->uuid.'/analysis/'.$name;
    }

    private function put(Processing $p, string $name, string $json): void
    {
        if (! Storage::disk(config('feitoo.processing.disk'))->put($this->path($p, $name), $json, ['visibility' => 'private'])) {
            throw new RuntimeException('Analyzer data storage failed.');
        }
    }

    private function read(Processing $p, string $name): array
    {
        $stream = Storage::disk(config('feitoo.processing.disk'))->readStream($this->path($p, $name));
        if (! is_resource($stream)) {
            throw new RuntimeException('Analyzer data unavailable.');
        }
        try {
            $json = stream_get_contents($stream, self::MAX_BYTES + 1);
        } finally {
            fclose($stream);
        }
        if ($json === false || strlen($json) > self::MAX_BYTES) {
            throw new RuntimeException('Analyzer data capacity exceeded.');
        }

        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    private function encode(array $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (strlen($json) > self::MAX_BYTES) {
            throw new RuntimeException('Analyzer result capacity exceeded.');
        }

        return $json;
    }
}
