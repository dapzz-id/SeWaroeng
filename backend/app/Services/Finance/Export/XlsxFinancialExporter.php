<?php

namespace App\Services\Finance\Export;

use App\Domain\Finance\Export\FinancialExportData;
use OpenSpout\Common\Entity\Cell\EmptyCell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

class XlsxFinancialExporter
{
    /**
     * Export normalized financial data directly to a temporary XLSX file.
     *
     * @return string Path to generated temporary file
     */
    public function exportToFile(FinancialExportData $exportData, ?string $destinationPath = null): string
    {
        $filePath = $destinationPath ?: (tempnam(sys_get_temp_dir(), 'sewaroeng_export_').'.xlsx');

        $writer = new Writer;
        $writer->openToFile($filePath);

        $titleStyle = (new Style)->withFontBold(true)->withFontSize(14)->withFontColor('1A365D');
        $subtitleStyle = (new Style)->withFontBold(true)->withFontSize(11)->withFontColor('2B6CB0');
        $metaValStyle = (new Style)->withFontSize(10)->withFontColor('2D3748');
        $sectionTitleStyle = (new Style)->withFontBold(true)->withFontSize(12)->withFontColor('2D3748');
        $headerStyle = (new Style)->withFontBold(true)->withFontSize(10)->withBackgroundColor('2B6CB0')->withFontColor('FFFFFF');
        $rowStyle = (new Style)->withFontSize(10);
        $totalStyle = (new Style)->withFontBold(true)->withFontSize(10)->withBackgroundColor('E2E8F0');

        $isFirstSheet = true;

        foreach ($exportData->sheets as $sheetIndex => $sheet) {
            $safeSheetName = preg_replace('/[\\\\\/*\?\[\]:]/', '', $sheet->name);
            $sheetTitle = mb_substr($safeSheetName ?: 'Sheet', 0, 31);

            if ($isFirstSheet) {
                $writer->getCurrentSheet()->setName($sheetTitle);
                $isFirstSheet = false;
            } else {
                $writer->addNewSheetAndMakeItCurrent();
                $writer->getCurrentSheet()->setName($sheetTitle);
            }

            // Top Header block on every sheet
            $writer->addRow($this->createRow(['LAPORAN KEUANGAN SEWAROENG'], $titleStyle));
            $writer->addRow($this->createRow([$exportData->title], $subtitleStyle));
            $writer->addRow($this->createRow(['']));

            $writer->addRow($this->createRow(['Entitas / Warung:', $exportData->tenantName], $metaValStyle));
            $writer->addRow($this->createRow(['Cakupan Outlet:', $exportData->outletName], $metaValStyle));
            $writer->addRow($this->createRow(['Periode Pelaporan:', $exportData->period], $metaValStyle));
            $writer->addRow($this->createRow(['Waktu Digenerate:', $exportData->generatedAt], $metaValStyle));
            $writer->addRow($this->createRow(['']));

            // KPI Cards on first sheet
            if ($sheetIndex === 0 && ! empty($exportData->summaryCards)) {
                $writer->addRow($this->createRow(['RINGKASAN METRIK UTAMA'], $sectionTitleStyle));
                foreach ($exportData->summaryCards as $card) {
                    $writer->addRow($this->createRow([$card['label'], $card['value']], $metaValStyle));
                }
                $writer->addRow($this->createRow(['']));
            }

            // Sections
            foreach ($sheet->sections as $section) {
                if ($section->title) {
                    $writer->addRow($this->createRow([$section->title], $sectionTitleStyle));
                }

                // Header Row
                $writer->addRow($this->createRow($section->headers, $headerStyle));

                // Data Rows
                foreach ($section->rows as $row) {
                    $writer->addRow($this->createRow($row, $rowStyle));
                }

                // Totals Row
                if ($section->totals !== null) {
                    $writer->addRow($this->createRow($section->totals, $totalStyle));
                }

                $writer->addRow($this->createRow(['']));
            }
        }

        $writer->close();

        return $filePath;
    }

    /**
     * Create an OpenSpout Row ensuring untrusted string values are written strictly
     * as StringCell (never FormulaCell) and numbers are preserved as NumericCell.
     *
     * @param  array<int, mixed>  $values
     */
    protected function createRow(array $values, ?Style $style = null): Row
    {
        $cells = [];
        foreach ($values as $val) {
            if ($val === null || $val === '') {
                $cells[] = new EmptyCell($val, $style);
            } elseif (is_int($val) || is_float($val)) {
                $cells[] = new NumericCell($val, $style);
            } else {
                $cells[] = new StringCell((string) $val, $style);
            }
        }

        return new Row($cells);
    }

    /**
     * Export normalized financial data and return raw file contents.
     */
    public function exportToString(FinancialExportData $exportData): string
    {
        $filePath = $this->exportToFile($exportData);
        $content = (string) file_get_contents($filePath);
        @unlink($filePath);

        return $content;
    }
}
