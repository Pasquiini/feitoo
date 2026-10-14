<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ProcessingFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class PrivateProcessingFileReader
{
    public function read(ProcessingFile $file): string
    {
        $limit = config('feitoo.uploads.max_file_size_kb') * 1024;
        $stream = Storage::disk($file->storage_disk)->readStream($file->storage_path);
        if (! is_resource($stream)) {
            throw new RuntimeException('Private file unavailable.');
        }
        try {
            $contents = stream_get_contents($stream, $limit + 1);
        } finally {
            fclose($stream);
        }
        if ($contents === false || strlen($contents) > $limit || strlen($contents) !== $file->size
            || ! hash_equals($file->checksum, hash('sha256', $contents))) {
            throw new RuntimeException('Private file integrity check failed.');
        }

        return $contents;
    }
}
