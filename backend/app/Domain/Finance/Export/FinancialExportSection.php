<?php

namespace App\Domain\Finance\Export;

class FinancialExportSection
{
    /**
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, mixed>>  $rows
     * @param  array<int, mixed>|null  $totals
     */
    public function __construct(
        public readonly ?string $title,
        public readonly array $headers,
        public readonly array $rows,
        public readonly ?array $totals = null
    ) {}
}
