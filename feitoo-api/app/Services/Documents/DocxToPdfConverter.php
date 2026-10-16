<?php

declare(strict_types=1);

namespace App\Services\Documents;

interface DocxToPdfConverter
{
    // Paths are system-generated private paths, never original names. Caller owns cleanup.
    public function convert(string $input, string $workspace): string;
}
