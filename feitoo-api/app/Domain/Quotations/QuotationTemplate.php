<?php

declare(strict_types=1);

namespace App\Domain\Quotations;

enum QuotationTemplate: string
{
    case Classic = 'classic';
    case Modern = 'modern';
}
