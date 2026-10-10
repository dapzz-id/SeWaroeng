<?php

namespace App\Domain\Finance\Export;

class FinancialExportData
{
    /**
     * @param  array<int, array{label: string, value: string, raw_minor?: int|null}>  $summaryCards
     * @param  array<int, FinancialExportSheet>  $sheets
     */
    public function __construct(
        public readonly string $reportType,
        public readonly string $title,
        public readonly string $tenantName,
        public readonly string $outletName,
        public readonly string $period,
        public readonly string $generatedAt,
        public readonly array $summaryCards,
        public readonly array $sheets
    ) {}

    /**
     * Helper to get all sections across sheets, or primary sheet sections.
     *
     * @return array<int, FinancialExportSection>
     */
    public function allSections(): array
    {
        $sections = [];
        foreach ($this->sheets as $sheet) {
            foreach ($sheet->sections as $section) {
                $sections[] = $section;
            }
        }

        return $sections;
    }
}
