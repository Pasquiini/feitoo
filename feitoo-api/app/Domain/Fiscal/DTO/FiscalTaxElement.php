<?php

declare(strict_types=1);

namespace App\Domain\Fiscal\DTO;

final readonly class FiscalTaxElement
{
    /** @param list<FiscalTaxElement> $children
     * @param  array<string, string>  $attributes
     */
    public function __construct(
        public string $name,
        public ?string $value,
        public array $children,
        public array $attributes,
    ) {}
}
