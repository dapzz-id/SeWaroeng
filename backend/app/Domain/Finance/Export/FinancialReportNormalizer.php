<?php

namespace App\Domain\Finance\Export;

use App\Models\JournalEntry;
use Carbon\Carbon;

class FinancialReportNormalizer
{
    public static function formatRupiah(int $minor): string
    {
        $formatted = number_format(abs($minor), 0, ',', '.');

        return ($minor < 0 ? '-Rp ' : 'Rp ').$formatted;
    }

    /**
     * Determine if a value is a formatted Indonesian Rupiah currency string.
     */
    public static function isValidMoneyString(mixed $val): bool
    {
        if (! is_string($val)) {
            return false;
        }

        $trimmed = trim($val);

        // Matches valid formatted Rupiah: "Rp 1.000.000", "-Rp 1.000.000", "+Rp 1.000.000", "Rp 0", "-Rp 500"
        return (bool) preg_match('/^[-+]?Rp\s\d{1,3}(\.\d{3})*(,\d+)?$/', $trimmed)
            || (bool) preg_match('/^[-+]?Rp\s\d+$/', $trimmed);
    }

    /**
     * Determine if an untrusted text value could trigger spreadsheet formula execution.
     */
    public static function isFormulaCandidate(mixed $value): bool
    {
        if ($value === null || ! is_string($value)) {
            return false;
        }

        // Strictly valid numbers are not formulas
        if (is_numeric($value)) {
            return false;
        }

        // Strictly valid money strings are not formulas
        if (self::isValidMoneyString($value)) {
            return false;
        }

        // Checks if trimmed string starts with =, +, -, @, or full-width Unicode variants: ＝, ＋, －, ＠
        // Or if string starts with control characters or whitespace followed by formula triggers
        return (bool) preg_match('/^[\s\x00-\x1f]*[=+@\-\x{FF1D}\x{FF0B}\x{FF0D}\x{FF20}]/u', $value)
            || (bool) preg_match('/^[\t\r\n\x00-\x08\x0B\x0C\x0E-\x1F]/u', $value);
    }

    /**
     * Sanitize a cell value for CSV export to mitigate formula injection.
     *
     * Prepend a single quote (') if the value is untrusted text starting with
     * formula characters (=, +, -, @) or control chars / whitespace followed by them.
     */
    public static function sanitizeCellValue(mixed $value): mixed
    {
        if (self::isFormulaCandidate($value)) {
            return "'".$value;
        }

        return $value;
    }

    /**
     * Alias for sanitizeCellValue for CSV contexts.
     */
    public static function sanitizeCsvValue(mixed $value): mixed
    {
        return self::sanitizeCellValue($value);
    }

    /**
     * Normalize Profit & Loss report data.
     *
     * @param  array<string, mixed>  $data
     */
    public static function normalizeProfitAndLoss(
        array $data,
        string $tenantName,
        string $outletName,
        string $generatedAt
    ): FinancialExportData {
        $rev = (int) ($data['revenue_minor'] ?? 0);
        $cogs = (int) ($data['cogs_minor'] ?? 0);
        $gross = (int) ($data['gross_profit_minor'] ?? 0);
        $grossPct = $data['gross_margin_pct'] ?? 0.0;
        $exp = (int) ($data['expense_minor'] ?? 0);
        $net = (int) ($data['net_profit_minor'] ?? 0);
        $netPct = $data['net_margin_pct'] ?? 0.0;

        $periodText = ($data['period']['start_date'] ?? '').' s/d '.($data['period']['end_date'] ?? '');

        $summaryCards = [
            ['label' => 'Pendapatan Bersih', 'value' => self::formatRupiah($rev), 'raw_minor' => $rev],
            ['label' => 'Beban Pokok Penjualan (HPP)', 'value' => self::formatRupiah($cogs), 'raw_minor' => $cogs],
            ['label' => 'Laba Kotor', 'value' => self::formatRupiah($gross), 'raw_minor' => $gross],
            ['label' => 'Margin Laba Kotor', 'value' => "{$grossPct}%", 'raw_minor' => null],
            ['label' => 'Beban Operasional', 'value' => self::formatRupiah($exp), 'raw_minor' => $exp],
            ['label' => 'Laba Bersih', 'value' => self::formatRupiah($net), 'raw_minor' => $net],
            ['label' => 'Margin Laba Bersih', 'value' => "{$netPct}%", 'raw_minor' => null],
        ];

        $grossRev = (int) ($data['breakdown']['gross_revenue_minor'] ?? $rev);
        $salesRet = (int) ($data['breakdown']['sales_return_minor'] ?? 0);

        $summaryRows = [
            ['Pendapatan Bruto (Gross Revenue)', $grossRev, self::formatRupiah($grossRev), 'Pendapatan kotor sebelum retur'],
            ['Retur Penjualan (Sales Return)', -$salesRet, self::formatRupiah(-$salesRet), 'Kontra pendapatan'],
            ['Pendapatan Bersih (Net Revenue)', $rev, self::formatRupiah($rev), 'Dasar perhitungan margin'],
            ['Beban Pokok Penjualan (COGS / HPP)', $cogs, self::formatRupiah($cogs), 'Beban pokok persediaan terpakai'],
            ['Laba Kotor (Gross Profit)', $gross, self::formatRupiah($gross), "Margin: {$grossPct}%"],
            ['Beban Operasional (Operating Expense)', $exp, self::formatRupiah($exp), 'Biaya operasional warung'],
            ['Laba Bersih (Net Profit)', $net, self::formatRupiah($net), "Margin: {$netPct}%"],
        ];

        $summarySection = new FinancialExportSection(
            title: 'Ikhtisar Laba Rugi',
            headers: ['Komponen Laporan', 'Nilai Minor', 'Nilai (Rp)', 'Keterangan'],
            rows: $summaryRows
        );

        $sheets = [
            new FinancialExportSheet('Ringkasan', [$summarySection]),
        ];

        // Sheet 2: Rincian Kategori Beban
        $categories = $data['breakdown']['categories'] ?? [];
        if (! empty($categories)) {
            $catRows = [];
            foreach ($categories as $cat => $amount) {
                $amtInt = (int) $amount;
                $catRows[] = [$cat, $amtInt, self::formatRupiah($amtInt)];
            }
            $catSection = new FinancialExportSection(
                title: 'Rincian Beban Berdasarkan Kategori',
                headers: ['Kategori Beban', 'Nilai Minor', 'Nilai (Rp)'],
                rows: $catRows,
                totals: ['Total Beban Operasional', $exp, self::formatRupiah($exp)]
            );
            $sheets[] = new FinancialExportSheet('Detail Beban', [$catSection]);
        }

        return new FinancialExportData(
            reportType: 'profit_and_loss',
            title: 'Laporan Laba Rugi (Profit & Loss)',
            tenantName: $tenantName,
            outletName: $outletName,
            period: $periodText,
            generatedAt: $generatedAt,
            summaryCards: $summaryCards,
            sheets: $sheets
        );
    }

    /**
     * Normalize Balance Sheet report data.
     *
     * @param  array<string, mixed>  $data
     */
    public static function normalizeBalanceSheet(
        array $data,
        string $tenantName,
        string $outletName,
        string $generatedAt
    ): FinancialExportData {
        $totAssets = (int) ($data['assets']['total_assets_minor'] ?? 0);
        $totLiab = (int) ($data['liabilities']['total_liabilities_minor'] ?? 0);
        $totEquity = (int) ($data['equity']['total_equity_minor'] ?? 0);
        $totLiabEq = (int) ($data['total_liabilities_and_equity_minor'] ?? 0);
        $isBalanced = (bool) ($data['is_balanced'] ?? false);
        $discrepancy = (int) ($data['discrepancy_minor'] ?? 0);

        $periodText = 'Per Tanggal '.($data['as_of_date'] ?? '');

        $summaryCards = [
            ['label' => 'Total Aset', 'value' => self::formatRupiah($totAssets), 'raw_minor' => $totAssets],
            ['label' => 'Total Kewajiban', 'value' => self::formatRupiah($totLiab), 'raw_minor' => $totLiab],
            ['label' => 'Total Ekuitas', 'value' => self::formatRupiah($totEquity), 'raw_minor' => $totEquity],
            ['label' => 'Total Kewajiban & Ekuitas', 'value' => self::formatRupiah($totLiabEq), 'raw_minor' => $totLiabEq],
            ['label' => 'Status Neraca', 'value' => $isBalanced ? 'SEIMBANG (BALANCED)' : 'SELISIH', 'raw_minor' => $discrepancy],
        ];

        // Assets section
        $cash = (int) ($data['assets']['cash_minor'] ?? 0);
        $clearing = (int) ($data['assets']['clearing_minor'] ?? 0);
        $inventory = (int) ($data['assets']['inventory_minor'] ?? 0);

        $assetSection = new FinancialExportSection(
            title: 'ASET (ASSETS)',
            headers: ['Akun Aset', 'Nilai Minor', 'Nilai (Rp)'],
            rows: [
                ['Kas Fisik (Cash)', $cash, self::formatRupiah($cash)],
                ['Kliring Bank / Pembayaran Digital', $clearing, self::formatRupiah($clearing)],
                ['Persediaan Barang (Inventory)', $inventory, self::formatRupiah($inventory)],
            ],
            totals: ['Total Aset', $totAssets, self::formatRupiah($totAssets)]
        );

        // Liabilities section
        $tax = (int) ($data['liabilities']['tax_payable_minor'] ?? 0);
        $liabSection = new FinancialExportSection(
            title: 'KEWAJIBAN (LIABILITIES)',
            headers: ['Akun Kewajiban', 'Nilai Minor', 'Nilai (Rp)'],
            rows: [
                ['Utang Pajak (Tax Payable)', $tax, self::formatRupiah($tax)],
            ],
            totals: ['Total Kewajiban', $totLiab, self::formatRupiah($totLiab)]
        );

        // Equity section
        $ownerEq = (int) ($data['equity']['owner_equity_minor'] ?? 0);
        $retained = (int) ($data['equity']['retained_earnings_minor'] ?? 0);
        $equitySection = new FinancialExportSection(
            title: 'EKUITAS (EQUITY)',
            headers: ['Akun Ekuitas', 'Nilai Minor', 'Nilai (Rp)'],
            rows: [
                ['Modal Pemilik (Owner Equity)', $ownerEq, self::formatRupiah($ownerEq)],
                ['Laba Ditahan / Akumulasi Laba Bersih', $retained, self::formatRupiah($retained)],
            ],
            totals: ['Total Ekuitas', $totEquity, self::formatRupiah($totEquity)]
        );

        // Balance Check section
        $checkSection = new FinancialExportSection(
            title: 'KESEIMBANGAN NERACA',
            headers: ['Parameter', 'Nilai Minor', 'Nilai (Rp)'],
            rows: [
                ['Total Kewajiban + Total Ekuitas', $totLiabEq, self::formatRupiah($totLiabEq)],
                ['Selisih (Aset - [Kewajiban + Ekuitas])', $discrepancy, self::formatRupiah($discrepancy)],
            ]
        );

        return new FinancialExportData(
            reportType: 'balance_sheet',
            title: 'Laporan Neraca Keuangan (Balance Sheet)',
            tenantName: $tenantName,
            outletName: $outletName,
            period: $periodText,
            generatedAt: $generatedAt,
            summaryCards: $summaryCards,
            sheets: [
                new FinancialExportSheet('Neraca', [$assetSection, $liabSection, $equitySection, $checkSection]),
            ]
        );
    }

    /**
     * Normalize Trial Balance report data.
     *
     * @param  array<string, mixed>  $data
     */
    public static function normalizeTrialBalance(
        array $data,
        string $tenantName,
        string $outletName,
        string $generatedAt
    ): FinancialExportData {
        $totDeb = (int) ($data['total_debit_minor'] ?? 0);
        $totCre = (int) ($data['total_credit_minor'] ?? 0);
        $isBalanced = (bool) ($data['is_balanced'] ?? false);
        $discrepancy = (int) ($data['discrepancy_minor'] ?? 0);

        $periodText = 'Per Tanggal '.($data['as_of_date'] ?? '');

        $summaryCards = [
            ['label' => 'Total Debit', 'value' => self::formatRupiah($totDeb), 'raw_minor' => $totDeb],
            ['label' => 'Total Kredit', 'value' => self::formatRupiah($totCre), 'raw_minor' => $totCre],
            ['label' => 'Status Keseimbangan', 'value' => $isBalanced ? 'SEIMBANG (BALANCED)' : 'SELISIH', 'raw_minor' => $discrepancy],
        ];

        $accountRows = [];
        foreach ($data['accounts'] ?? [] as $acc) {
            $accountRows[] = [
                $acc['account_code'] ?? '',
                $acc['account_name'] ?? '',
                $acc['account_type'] ?? '',
                (int) ($acc['total_debit_minor'] ?? 0),
                self::formatRupiah((int) ($acc['total_debit_minor'] ?? 0)),
                (int) ($acc['total_credit_minor'] ?? 0),
                self::formatRupiah((int) ($acc['total_credit_minor'] ?? 0)),
                (int) ($acc['ending_debit_minor'] ?? 0),
                self::formatRupiah((int) ($acc['ending_debit_minor'] ?? 0)),
                (int) ($acc['ending_credit_minor'] ?? 0),
                self::formatRupiah((int) ($acc['ending_credit_minor'] ?? 0)),
            ];
        }

        $section = new FinancialExportSection(
            title: 'Daftar Saldo Akun (Chart of Accounts)',
            headers: [
                'Kode Akun',
                'Nama Akun',
                'Tipe Akun',
                'Mutasi Debit Minor',
                'Mutasi Debit (Rp)',
                'Mutasi Kredit Minor',
                'Mutasi Kredit (Rp)',
                'Saldo Akhir Debit Minor',
                'Saldo Akhir Debit (Rp)',
                'Saldo Akhir Kredit Minor',
                'Saldo Akhir Kredit (Rp)',
            ],
            rows: $accountRows,
            totals: [
                'TOTAL',
                '',
                '',
                $totDeb,
                self::formatRupiah($totDeb),
                $totCre,
                self::formatRupiah($totCre),
                $totDeb,
                self::formatRupiah($totDeb),
                $totCre,
                self::formatRupiah($totCre),
            ]
        );

        return new FinancialExportData(
            reportType: 'trial_balance',
            title: 'Neraca Saldo (Trial Balance)',
            tenantName: $tenantName,
            outletName: $outletName,
            period: $periodText,
            generatedAt: $generatedAt,
            summaryCards: $summaryCards,
            sheets: [
                new FinancialExportSheet('Neraca Saldo', [$section]),
            ]
        );
    }

    /**
     * Normalize Account Ledger report data.
     *
     * @param  array<string, mixed>  $data
     */
    public static function normalizeAccountLedger(
        array $data,
        string $tenantName,
        string $outletName,
        string $generatedAt
    ): FinancialExportData {
        $accCode = $data['account']['code'] ?? '';
        $accName = $data['account']['name'] ?? '';
        $opening = (int) ($data['opening_balance_minor'] ?? 0);
        $totDeb = (int) ($data['total_debit_minor'] ?? 0);
        $totCre = (int) ($data['total_credit_minor'] ?? 0);
        $closing = (int) ($data['closing_balance_minor'] ?? 0);

        $periodText = ($data['period']['start_date'] ?? '').' s/d '.($data['period']['end_date'] ?? '');

        $summaryCards = [
            ['label' => 'Akun', 'value' => "{$accCode} - {$accName}", 'raw_minor' => null],
            ['label' => 'Saldo Awal', 'value' => self::formatRupiah($opening), 'raw_minor' => $opening],
            ['label' => 'Total Debit Periode', 'value' => self::formatRupiah($totDeb), 'raw_minor' => $totDeb],
            ['label' => 'Total Kredit Periode', 'value' => self::formatRupiah($totCre), 'raw_minor' => $totCre],
            ['label' => 'Saldo Akhir', 'value' => self::formatRupiah($closing), 'raw_minor' => $closing],
        ];

        $lineRows = [];
        foreach ($data['lines'] ?? [] as $l) {
            $deb = (int) ($l['debit_minor'] ?? 0);
            $cre = (int) ($l['credit_minor'] ?? 0);
            $bal = (int) ($l['running_balance_minor'] ?? 0);

            $lineRows[] = [
                $l['entry_date'] ?? '',
                $l['entry_number'] ?? '',
                $l['source_type'] ?? '',
                $l['description'] ?? '',
                $deb,
                self::formatRupiah($deb),
                $cre,
                self::formatRupiah($cre),
                $bal,
                self::formatRupiah($bal),
            ];
        }

        $section = new FinancialExportSection(
            title: "Mutasi Buku Besar: {$accCode} - {$accName}",
            headers: [
                'Tanggal',
                'No. Jurnal',
                'Sumber',
                'Keterangan',
                'Debit Minor',
                'Debit (Rp)',
                'Kredit Minor',
                'Kredit (Rp)',
                'Saldo Berjalan Minor',
                'Saldo Berjalan (Rp)',
            ],
            rows: $lineRows,
            totals: [
                'TOTAL MUTASI',
                '',
                '',
                '',
                $totDeb,
                self::formatRupiah($totDeb),
                $totCre,
                self::formatRupiah($totCre),
                $closing,
                self::formatRupiah($closing),
            ]
        );

        return new FinancialExportData(
            reportType: 'account_ledger',
            title: "Buku Besar Akun ({$accCode} - {$accName})",
            tenantName: $tenantName,
            outletName: $outletName,
            period: $periodText,
            generatedAt: $generatedAt,
            summaryCards: $summaryCards,
            sheets: [
                new FinancialExportSheet('Buku Besar', [$section]),
            ]
        );
    }

    /**
     * Normalize Cash Flow report data.
     *
     * @param  array<string, mixed>  $data
     */
    public static function normalizeCashFlow(
        array $data,
        string $tenantName,
        string $outletName,
        string $generatedAt
    ): FinancialExportData {
        $open = (int) ($data['opening_cash_minor'] ?? 0);
        $in = (int) ($data['cash_in_minor'] ?? 0);
        $out = (int) ($data['cash_out_minor'] ?? 0);
        $net = (int) ($data['net_cashflow_minor'] ?? 0);
        $close = (int) ($data['closing_cash_minor'] ?? 0);

        $periodText = ($data['period']['start_date'] ?? '').' s/d '.($data['period']['end_date'] ?? '');

        $summaryCards = [
            ['label' => 'Kas Awal', 'value' => self::formatRupiah($open), 'raw_minor' => $open],
            ['label' => 'Total Kas Masuk', 'value' => self::formatRupiah($in), 'raw_minor' => $in],
            ['label' => 'Total Kas Keluar', 'value' => self::formatRupiah($out), 'raw_minor' => $out],
            ['label' => 'Arus Kas Bersih', 'value' => self::formatRupiah($net), 'raw_minor' => $net],
            ['label' => 'Kas Akhir', 'value' => self::formatRupiah($close), 'raw_minor' => $close],
        ];

        $summarySection = new FinancialExportSection(
            title: 'Ringkasan Pergerakan Kas',
            headers: ['Komponen Arus Kas', 'Nilai Minor', 'Nilai (Rp)'],
            rows: [
                ['Saldo Kas Awal', $open, self::formatRupiah($open)],
                ['Total Kas Masuk (Inflow)', $in, self::formatRupiah($in)],
                ['Total Kas Keluar (Outflow)', $out, self::formatRupiah($out)],
                ['Arus Kas Bersih (Net Cash Flow)', $net, self::formatRupiah($net)],
                ['Saldo Kas Akhir', $close, self::formatRupiah($close)],
            ]
        );

        $breakdown = $data['breakdown'] ?? [];
        $sales = (int) ($breakdown['cash_sales_minor'] ?? 0);
        $otherIn = (int) ($breakdown['other_cash_in_minor'] ?? 0);
        $exp = (int) ($breakdown['cash_expenses_minor'] ?? 0);
        $refund = (int) ($breakdown['cash_refunds_minor'] ?? 0);
        $otherOut = (int) ($breakdown['other_cash_out_minor'] ?? 0);

        $detailSection = new FinancialExportSection(
            title: 'Rincian Sumber Arus Kas',
            headers: ['Aktivitas Kas', 'Nilai Minor', 'Nilai (Rp)'],
            rows: [
                ['Penerimaan Penjualan Tunai', $sales, self::formatRupiah($sales)],
                ['Penerimaan Kas Lainnya', $otherIn, self::formatRupiah($otherIn)],
                ['Pengeluaran Beban Kas', $exp, self::formatRupiah($exp)],
                ['Pengembalian Kas (Refund)', $refund, self::formatRupiah($refund)],
                ['Pengeluaran Kas Lainnya', $otherOut, self::formatRupiah($otherOut)],
            ]
        );

        return new FinancialExportData(
            reportType: 'cash_flow',
            title: 'Laporan Arus Kas (Cash Flow)',
            tenantName: $tenantName,
            outletName: $outletName,
            period: $periodText,
            generatedAt: $generatedAt,
            summaryCards: $summaryCards,
            sheets: [
                new FinancialExportSheet('Arus Kas', [$summarySection, $detailSection]),
            ]
        );
    }

    /**
     * Normalize Payment Clearing & Reconciliation summary report data.
     *
     * @param  array<string, mixed>  $data
     */
    public static function normalizeClearingSummary(
        array $data,
        string $tenantName,
        string $outletName,
        string $generatedAt
    ): FinancialExportData {
        $ledger = $data['clearing_ledger'] ?? [];
        $deb = (int) ($ledger['debit_minor'] ?? 0);
        $cre = (int) ($ledger['credit_minor'] ?? 0);
        $net = (int) ($ledger['net_movement_minor'] ?? 0);

        $recon = $data['reconciliation_verification'] ?? [];
        $matchedCnt = (int) ($recon['matched_count'] ?? 0);
        $matchedAmt = (int) ($recon['matched_amount_minor'] ?? 0);
        $mismatchedCnt = (int) ($recon['mismatched_count'] ?? 0);
        $mismatchedAmt = (int) ($recon['mismatched_amount_minor'] ?? 0);
        $unresCnt = (int) ($recon['unresolved_count'] ?? 0);
        $unresAmt = (int) ($recon['unresolved_amount_minor'] ?? 0);
        $settledCnt = (int) ($recon['settled_count'] ?? 0);
        $settledAmt = (int) ($recon['settled_amount_minor'] ?? 0);
        $pendingCnt = (int) ($recon['pending_settlement_count'] ?? 0);
        $pendingAmt = (int) ($recon['pending_settlement_amount_minor'] ?? 0);

        $periodText = ($data['period']['start_date'] ?? '').' s/d '.($data['period']['end_date'] ?? '');

        $summaryCards = [
            ['label' => 'Total Kliring Masuk', 'value' => self::formatRupiah($deb), 'raw_minor' => $deb],
            ['label' => 'Total Kliring Keluar', 'value' => self::formatRupiah($cre), 'raw_minor' => $cre],
            ['label' => 'Pergerakan Bersih', 'value' => self::formatRupiah($net), 'raw_minor' => $net],
            ['label' => 'Trx Cocok (Matched)', 'value' => "{$matchedCnt} trx (".self::formatRupiah($matchedAmt).')', 'raw_minor' => $matchedAmt],
            ['label' => 'Trx Butuh Ditinjau', 'value' => "{$unresCnt} trx (".self::formatRupiah($unresAmt).')', 'raw_minor' => $unresAmt],
        ];

        $ledgerSection = new FinancialExportSection(
            title: 'Mutasi Akun Kliring (1120)',
            headers: ['Komponen Kliring', 'Nilai Minor', 'Nilai (Rp)'],
            rows: [
                ['Total Kliring Masuk (Debit)', $deb, self::formatRupiah($deb)],
                ['Total Kliring Keluar (Kredit)', $cre, self::formatRupiah($cre)],
                ['Pergerakan Bersih Kliring', $net, self::formatRupiah($net)],
            ]
        );

        $reconSection = new FinancialExportSection(
            title: 'Status Verifikasi & Pencairan Pembayaran Digital',
            headers: ['Status', 'Jumlah Trx', 'Nilai Minor', 'Nilai (Rp)'],
            rows: [
                ['Cocok (Matched)', $matchedCnt, $matchedAmt, self::formatRupiah($matchedAmt)],
                ['Selisih (Mismatched)', $mismatchedCnt, $mismatchedAmt, self::formatRupiah($mismatchedAmt)],
                ['Belum Terselesaikan (Needs Review / Unmatched)', $unresCnt, $unresAmt, self::formatRupiah($unresAmt)],
                ['Sudah Dicairkan (Settled)', $settledCnt, $settledAmt, self::formatRupiah($settledAmt)],
                ['Menunggu Pencairan (Pending Settlement)', $pendingCnt, $pendingAmt, self::formatRupiah($pendingAmt)],
            ]
        );

        return new FinancialExportData(
            reportType: 'clearing_summary',
            title: 'Laporan Kliring & Rekonsiliasi Pembayaran',
            tenantName: $tenantName,
            outletName: $outletName,
            period: $periodText,
            generatedAt: $generatedAt,
            summaryCards: $summaryCards,
            sheets: [
                new FinancialExportSheet('Kliring & Rekonsiliasi', [$ledgerSection, $reconSection]),
            ]
        );
    }

    /**
     * Normalize Journal Entries collection data.
     *
     * @param  iterable<JournalEntry>  $entries
     */
    public static function normalizeJournalEntries(
        iterable $entries,
        string $tenantName,
        string $outletName,
        string $periodText,
        string $generatedAt
    ): FinancialExportData {
        $rows = [];
        $totalDebit = 0;
        $totalCredit = 0;
        $entryCount = 0;

        foreach ($entries as $je) {
            $entryCount++;
            $entryDate = $je->entry_date instanceof Carbon ? $je->entry_date->toDateString() : (string) $je->entry_date;
            $outlet = $je->outlet?->name ?: '-';

            foreach ($je->lines as $line) {
                $deb = (int) $line->debit_minor;
                $cre = (int) $line->credit_minor;
                $totalDebit += $deb;
                $totalCredit += $cre;

                $rows[] = [
                    $entryDate,
                    $je->entry_number,
                    $je->status,
                    $je->source_type,
                    $outlet,
                    $line->account?->code ?: '',
                    $line->account?->name ?: '',
                    $line->description ?: $je->description ?: '',
                    $deb,
                    self::formatRupiah($deb),
                    $cre,
                    self::formatRupiah($cre),
                ];
            }
        }

        $summaryCards = [
            ['label' => 'Total Ayat Jurnal', 'value' => "{$entryCount} entri", 'raw_minor' => null],
            ['label' => 'Total Debit Terposting', 'value' => self::formatRupiah($totalDebit), 'raw_minor' => $totalDebit],
            ['label' => 'Total Kredit Terposting', 'value' => self::formatRupiah($totalCredit), 'raw_minor' => $totalCredit],
            ['label' => 'Keseimbangan Jurnal', 'value' => ($totalDebit === $totalCredit) ? 'SEIMBANG' : 'SELISIH', 'raw_minor' => $totalDebit - $totalCredit],
        ];

        $section = new FinancialExportSection(
            title: 'Daftar Transaksi Jurnal Umum',
            headers: [
                'Tanggal',
                'No. Jurnal',
                'Status',
                'Tipe Sumber',
                'Outlet',
                'Kode Akun',
                'Nama Akun',
                'Keterangan',
                'Debit Minor',
                'Debit (Rp)',
                'Kredit Minor',
                'Kredit (Rp)',
            ],
            rows: $rows,
            totals: [
                'TOTAL',
                '',
                '',
                '',
                '',
                '',
                '',
                '',
                $totalDebit,
                self::formatRupiah($totalDebit),
                $totalCredit,
                self::formatRupiah($totalCredit),
            ]
        );

        return new FinancialExportData(
            reportType: 'journal_entries',
            title: 'Jurnal Transaksi (General Journal)',
            tenantName: $tenantName,
            outletName: $outletName,
            period: $periodText,
            generatedAt: $generatedAt,
            summaryCards: $summaryCards,
            sheets: [
                new FinancialExportSheet('Jurnal', [$section]),
            ]
        );
    }
}
