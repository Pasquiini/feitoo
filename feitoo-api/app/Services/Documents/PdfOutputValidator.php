<?php

declare(strict_types=1);

namespace App\Services\Documents;

use App\Enums\ProcessingFileError;
use App\Support\Documents\DocumentException;

final class PdfOutputValidator
{
    public function validate(string $path): void
    {
        clearstatcache(true, $path);
        if (! is_file($path) || is_link($path) || filesize($path) < 40 || filesize($path) > config('documents.max_pdf_bytes')) {
            throw new DocumentException(ProcessingFileError::PdfOutputInvalid);
        }
        $handle = fopen($path, 'rb');
        if (! $handle) {
            throw new DocumentException(ProcessingFileError::PdfOutputInvalid);
        }
        try {
            $header = fread($handle, 8);
            fseek($handle, max(0, filesize($path) - 2048));
            $tail = stream_get_contents($handle);
            if (! preg_match('/^%PDF-(?:1\.[0-7]|2\.0)/', $header) || ! preg_match('/startxref\s+\d+\s+%%EOF\s*$/s', $tail)) {
                throw new DocumentException(ProcessingFileError::PdfOutputInvalid);
            }
        } finally {
            fclose($handle);
        }
    }
}
