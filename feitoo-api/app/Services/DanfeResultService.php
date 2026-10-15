<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ProcessingFileKind;
use App\Enums\ProcessingFileStatus;
use App\Models\Processing;
use App\Models\ProcessingFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

class DanfeResultService
{
    public function savePdf(ProcessingFile $input, string $key, string $pdf): void
    {
        $this->store($input->processing, $input->uuid.'.pdf', 'DANFE-'.$key.'.pdf', 'application/pdf', $pdf);
    }

    public function generate(Processing $processing): void
    {
        if ($processing->output()->exists()) {
            return;
        }
        // Keep intermediate archives private and addressable by the existing TTL purge
        // even if a worker is killed before finally executes.
        $local = Storage::disk('local');
        $directory = 'danfe-temporary/'.$processing->uuid;
        if (! $local->makeDirectory($directory)) {
            throw new RuntimeException('ZIP temporary storage unavailable.');
        }
        $temporary = $local->path($directory.'/archive.zip');
        try {
            $zip = new ZipArchive;
            if ($zip->open($temporary, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('ZIP unavailable.');
            }
            try {
                foreach ($processing->files()->where('kind', 'output')->where('extension', 'pdf')->whereNull('removed_at')->cursor() as $file) {
                    if (! $zip->addFromString($file->original_name, Storage::disk($file->storage_disk)->get($file->storage_path))) {
                        throw new RuntimeException('ZIP entry failed.');
                    }
                }
                if (! $zip->addFromString('LEIA-ME.txt', DanfeGenerator::NOTICE)) {
                    throw new RuntimeException('ZIP notice failed.');
                }
            } finally {
                if (! $zip->close()) {
                    throw new RuntimeException('ZIP finalization failed.');
                }
            }
            $contents = file_get_contents($temporary);
            if ($contents === false) {
                throw new RuntimeException('ZIP read failed.');
            }
            $this->store($processing, 'danfes.zip', 'feitoo-danfes.zip', 'application/zip', $contents);
        } finally {
            $local->deleteDirectory($directory);
        }
    }

    private function store(Processing $processing, string $name, string $label, string $mime, string $contents): void
    {
        $disk = config('feitoo.processing.disk');
        $path = 'processings/'.$processing->uuid.'/output/'.$name;
        if (! Storage::disk($disk)->put($path, $contents, ['visibility' => 'private'])) {
            throw new RuntimeException('DANFE storage failed.');
        }
        $processing->files()->updateOrCreate(['stored_name' => $name, 'kind' => ProcessingFileKind::Output], [
            'uuid' => (string) Str::uuid(), 'status' => ProcessingFileStatus::Completed,
            'original_name' => $label, 'storage_disk' => $disk, 'storage_path' => $path,
            'mime_type' => $mime, 'extension' => pathinfo($name, PATHINFO_EXTENSION),
            'size' => strlen($contents), 'checksum' => hash('sha256', $contents),
            'expires_at' => $processing->expires_at, 'processed_at' => now(),
        ]);
    }
}
