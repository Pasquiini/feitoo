<?php

declare(strict_types=1);

namespace App\Services\Quotations;

final readonly class QuotationPdf
{
    public function __construct(public string $bytes, public int $pages, public string $sha256) {}
}
