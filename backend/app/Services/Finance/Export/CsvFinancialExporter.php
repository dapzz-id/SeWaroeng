<?php

namespace App\Services\Finance\Export;

use App\Domain\Finance\Export\FinancialExportData;
use App\Domain\Finance\Export\FinancialExportNormalizer;

class CsvFinancialExporter
{
    /**
     * Export normalized financial data to CSV string.
     */
    public function exportToString(FinancialExportData $exportData): string
    {
        $handle = fopen('php://temp', 'r+');
        $this->exportToStream($exportData, $handle);
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * Export normalized financial data to given stream resource.
     *
     * @param  resource  $stream
     */
    public function exportToStream(FinancialExportData $exportData, $stream): void
    {
        // UTF-8 Byte Order Mark for Excel compatibility
        fwrite($stream, "\xEF\xBB\xBF");

        // Document Metadata
        fputcsv($stream, [$this->sanitize('LAPORAN KEUANGAN SEWAROENG')]);
        fputcsv($stream, [$this->sanitize('Judul Laporan'), $this->sanitize($exportData->title)]);
        fputcsv($stream, [$this->sanitize('Nama Entitas / Warung'), $this->sanitize($exportData->tenantName)]);
        fputcsv($stream, [$this->sanitize('Cakupan Outlet'), $this->sanitize($exportData->outletName)]);
        fputcsv($stream, [$this->sanitize('Periode'), $this->sanitize($exportData->period)]);
        fputcsv($stream, [$this->sanitize('Waktu Dibuat'), $this->sanitize($exportData->generatedAt)]);
        fputcsv($stream, []);

        // Summary Cards KPI Block
        if (! empty($exportData->summaryCards)) {
            fputcsv($stream, [$this->sanitize('RINGKASAN METRIK')]);
            foreach ($exportData->summaryCards as $card) {
                fputcsv($stream, [
                    $this->sanitize($card['label']),
                    $this->sanitize($card['value']),
                ]);
            }
            fputcsv($stream, []);
        }

        // Sheets & Sections
        foreach ($exportData->sheets as $sheet) {
            fputcsv($stream, [$this->sanitize('=== LEMBAR: '.mb_strtoupper($sheet->name).' ===')]);

            foreach ($sheet->sections as $section) {
                if ($section->title) {
                    fputcsv($stream, [$this->sanitize('['.$section->title.']')]);
                }

                // Header Row
                fputcsv($stream, array_map([$this, 'sanitize'], $section->headers));

                // Data Rows
                foreach ($section->rows as $row) {
                    fputcsv($stream, array_map(function ($val) {
                        return $this->sanitize($val ?? '');
                    }, $row));
                }

                // Totals Row
                if ($section->totals !== null) {
                    fputcsv($stream, array_map(function ($val) {
                        return $this->sanitize($val ?? '');
                    }, $section->totals));
                }

                // Section separator
                fputcsv($stream, []);
            }
        }
    }

    /**
     * Sanitize cell value against CSV formula injection.
     */
    public function sanitize(mixed $val): mixed
    {
        return FinancialExportNormalizer::sanitizeCellValue($val);
    }
}
