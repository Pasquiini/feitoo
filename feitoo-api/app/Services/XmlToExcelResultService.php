<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Fiscal\Nfe\NfeParser;
use App\Domain\Tools\XmlToExcel\XmlToExcelGenerator;
use App\Enums\ProcessingFileKind;
use App\Enums\ProcessingFileStatus;
use App\Enums\ProcessingTool;
use App\Models\Processing;
use Generator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class XmlToExcelResultService
{
    public function __construct(private readonly XmlToExcelGenerator $generator, private readonly NfeParser $parser,
        private readonly PrivateProcessingFileReader $reader) {}

    // Called under the Processing transaction/lock: deletion and duplicate jobs wait.
    public function generate(Processing $processing): void
    {
        if ($processing->output()->exists()) {
            return;
        }
        $temporary = tempnam(sys_get_temp_dir(), 'feitoo-xlsx-');
        if ($temporary === false) {
            throw new RuntimeException('Workbook temporary storage unavailable.');
        }
        $disk = Storage::disk(config('feitoo.processing.disk'));
        $uuid = (string) Str::uuid();
        $path = 'processings/'.$processing->uuid.'/output/'.$uuid.'.xlsx';
        try {
            if ($processing->tool === ProcessingTool::ProductsFromXml) {
                app(ProductsFromXmlResultService::class)->generate($processing, $temporary);
            } else {
                $this->generator->generate($this->documents($processing), $temporary);
            }
            $stream = fopen($temporary, 'rb');
            if (! is_resource($stream)) {
                throw new RuntimeException('Workbook unavailable.');
            }
            try {
                if (! $disk->put($path, $stream, ['visibility' => 'private'])) {
                    throw new RuntimeException('Workbook storage failed.');
                }
            } finally {
                fclose($stream);
            }
            $processing->files()->create([
                'uuid' => $uuid, 'kind' => ProcessingFileKind::Output, 'status' => ProcessingFileStatus::Completed,
                'original_name' => 'feitoo-'.ltrim($processing->tool->path(), '/').'-'.now()->format('Y-m-d').'.xlsx', 'stored_name' => $uuid.'.xlsx',
                'storage_disk' => config('feitoo.processing.disk'), 'storage_path' => $path,
                'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'extension' => 'xlsx',
                'size' => filesize($temporary), 'checksum' => hash_file('sha256', $temporary),
                'expires_at' => $processing->expires_at, 'processed_at' => now(),
            ]);
        } catch (Throwable $exception) {
            try {
                $disk->delete($path);
            } catch (Throwable) {
                Log::warning('Workbook cleanup failed.', ['processing_uuid' => $processing->uuid]);
            }
            throw $exception;
        } finally {
            unlink($temporary);
        }
    }

    private function documents(Processing $processing): Generator
    {
        foreach ($processing->files()->where('kind', ProcessingFileKind::Input->value)
            ->where('status', ProcessingFileStatus::Completed->value)->whereNull('removed_at')->lazyById(100) as $file) {
            yield $this->parser->parse($this->reader->read($file));
        }
    }
}
