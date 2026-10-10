<?php

namespace App\Services\Finance\Export;

use App\Domain\Finance\Export\FinancialExportData;
use Barryvdh\DomPDF\Facade\Pdf;

class PdfFinancialExporter
{
    /**
     * Export normalized financial data to PDF binary string.
     */
    public function exportToString(FinancialExportData $exportData): string
    {
        $pdf = Pdf::loadView('finance.reports.pdf-template', [
            'exportData' => $exportData,
        ]);

        $pdf->setPaper('a4', 'portrait');
        $pdf->setOptions([
            'isHtml5ParserEnabled' => true,
            'isRemoteEnabled' => false,
            'defaultFont' => 'Helvetica',
        ]);

        return $pdf->output();
    }

    /**
     * Export normalized financial data directly to a temporary file.
     */
    public function exportToFile(FinancialExportData $exportData, ?string $destinationPath = null): string
    {
        $filePath = $destinationPath ?: (tempnam(sys_get_temp_dir(), 'sewaroeng_export_').'.pdf');
        file_put_contents($filePath, $this->exportToString($exportData));

        return $filePath;
    }
}
