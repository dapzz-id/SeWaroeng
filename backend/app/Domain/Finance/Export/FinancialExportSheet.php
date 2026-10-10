<?php

namespace App\Domain\Finance\Export;

class FinancialExportSheet
{
    /**
     * @param  array<int, FinancialExportSection>  $sections
     */
    public function __construct(
        public readonly string $name,
        public readonly array $sections
    ) {}
}
