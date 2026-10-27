<?php

declare(strict_types=1);

namespace App\Domain\Quotations;

final class QuotationLimits
{
    public const MAX_ITEMS = 100;

    public const MAX_DESCRIPTION = 1000;

    public const MAX_NOTES = 4000;

    public const MAX_PAYLOAD_BYTES = 262144;

    public const MAX_QUANTITY = '1000000';

    public const MAX_UNIT_PRICE_CENTS = 1000000000;

    public const MAX_TOTAL_CENTS = 100000000000;

    public const MAX_LOGO_BYTES = 2097152;

    public const MAX_LOGO_PIXELS = 4000000;

    public const MAX_LOGO_SIDE = 1200;

    public const MAX_PDF_BYTES = 10485760;

    public const MAX_PAGES = 20;
}
