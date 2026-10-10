<?php

namespace App\Services\Finance;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\CashMovement;
use App\Models\Expense;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Payment;
use App\Models\PaymentReconciliation;
use App\Models\PaymentRefund;
use App\Models\Sale;
use App\Models\SaleConsumption;
use App\Models\Tenant;
use Carbon\Carbon;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FinanceService
{
    /**
     * Ensure standard default chart of accounts exists for the given tenant.
     *
     * @return array<string, Account> Keyed by account code
     */
    public function ensureDefaultAccounts(string $tenantId): array
    {
        $defaults = [
            [
                'code' => Account::CODE_CASH,
                'name' => 'Kas',
                'type' => Account::TYPE_ASSET,
                'description' => 'Kas fisik / kas kecil di outlet',
            ],
            [
                'code' => Account::CODE_BANK_CLEARING,
                'name' => 'Kliring Pembayaran / Bank',
                'type' => Account::TYPE_ASSET,
                'description' => 'Penyelesaian pembayaran digital (QRIS, Kartu, Transfer)',
            ],
            [
                'code' => Account::CODE_INVENTORY,
                'name' => 'Persediaan Barang',
                'type' => Account::TYPE_ASSET,
                'description' => 'Nilai persediaan bahan baku dan barang jadi',
            ],
            [
                'code' => Account::CODE_TAX_PAYABLE,
                'name' => 'Utang Pajak',
                'type' => Account::TYPE_LIABILITY,
                'description' => 'Kewajiban pajak penjualan / PB1',
            ],
            [
                'code' => Account::CODE_OWNER_EQUITY,
                'name' => 'Modal Pemilik',
                'type' => Account::TYPE_EQUITY,
                'description' => 'Ekuitas dan modal awal pemilik',
            ],
            [
                'code' => Account::CODE_SALES_REVENUE,
                'name' => 'Pendapatan Penjualan',
                'type' => Account::TYPE_REVENUE,
                'description' => 'Pendapatan bruto penjualan barang dan jasa',
            ],
            [
                'code' => Account::CODE_SALES_RETURN,
                'name' => 'Retur Penjualan',
                'type' => Account::TYPE_REVENUE,
                'description' => 'Kontra-pendapatan dari pembatalan dan pengembalian dana',
            ],
            [
                'code' => Account::CODE_COGS,
                'name' => 'Beban Pokok Pendapatan (HPP)',
                'type' => Account::TYPE_EXPENSE,
                'description' => 'Harga Pokok Penjualan bahan baku terpakai',
            ],
            [
                'code' => Account::CODE_OPERATING_EXPENSE,
                'name' => 'Beban Operasional',
                'type' => Account::TYPE_EXPENSE,
                'description' => 'Beban operasional umum warung/toko',
            ],
        ];

        $accounts = [];
        foreach ($defaults as $def) {
            $account = Account::firstOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'code' => $def['code'],
                ],
                [
                    'name' => $def['name'],
                    'type' => $def['type'],
                    'is_system' => true,
                    'is_active' => true,
                    'description' => $def['description'],
                ]
            );
            $accounts[$def['code']] = $account;
        }

        return $accounts;
    }

    /**
     * Get account by code, ensuring defaults are created.
     */
    public function getAccount(string $tenantId, string $code): Account
    {
        $account = Account::where('tenant_id', $tenantId)->where('code', $code)->first();

        if (! $account) {
            $accounts = $this->ensureDefaultAccounts($tenantId);

            return $accounts[$code];
        }

        return $account;
    }

    /**
     * Post a balanced double-entry journal entry atomically.
     *
     * Invariants enforced:
     *   1. SUM(debit_minor) === SUM(credit_minor)
     *   2. Total debits > 0
     *   3. At least 2 lines
     *   4. Immutable once posted
     *
     * @param  array{
     *     outlet_id?: string|null,
     *     entry_number?: string|null,
     *     entry_date: string,
     *     source_type: string,
     *     source_id?: string|null,
     *     description?: string|null,
     *     posted_by?: string|null
     * }  $entryData
     * @param  array<int, array{
     *     account_id: string,
     *     debit_minor: int,
     *     credit_minor: int,
     *     description?: string|null
     * }>  $linesData
     */
    public function postJournal(string $tenantId, array $entryData, array $linesData): JournalEntry
    {
        if (count($linesData) < 2) {
            throw new DomainException('A double-entry journal must contain at least two lines.');
        }

        $totalDebits = 0;
        $totalCredits = 0;

        foreach ($linesData as $line) {
            $debit = (int) ($line['debit_minor'] ?? 0);
            $credit = (int) ($line['credit_minor'] ?? 0);

            if ($debit < 0 || $credit < 0) {
                throw new DomainException('Journal line amounts must be non-negative integers.');
            }
            if ($debit === 0 && $credit === 0) {
                throw new DomainException('Journal line must have either debit or credit greater than zero.');
            }
            if ($debit > 0 && $credit > 0) {
                throw new DomainException('A single journal line cannot have both debit and credit.');
            }

            $totalDebits += $debit;
            $totalCredits += $credit;
        }

        if ($totalDebits !== $totalCredits || $totalDebits === 0) {
            throw new DomainException("Double-entry invariant violated: debits ({$totalDebits}) must equal credits ({$totalCredits}) and be greater than 0.");
        }

        return DB::transaction(function () use ($tenantId, $entryData, $linesData) {
            $entryDate = Carbon::parse($entryData['entry_date']);
            $entryNumber = $entryData['entry_number'] ?? $this->generateEntryNumber($tenantId, $entryDate);

            // Create JournalEntry header
            $entry = JournalEntry::create([
                'tenant_id' => $tenantId,
                'outlet_id' => $entryData['outlet_id'] ?? null,
                'entry_number' => $entryNumber,
                'entry_date' => $entryDate->toDateString(),
                'status' => JournalEntry::STATUS_POSTED,
                'source_type' => $entryData['source_type'],
                'source_id' => $entryData['source_id'] ?? null,
                'description' => $entryData['description'] ?? null,
                'posted_at' => now(),
                'posted_by' => $entryData['posted_by'] ?? auth()->id(),
                'reversal_of_id' => $entryData['reversal_of_id'] ?? null,
            ]);

            // Create JournalLine records
            foreach ($linesData as $line) {
                JournalLine::create([
                    'tenant_id' => $tenantId,
                    'journal_entry_id' => $entry->id,
                    'account_id' => $line['account_id'],
                    'debit_minor' => (int) ($line['debit_minor'] ?? 0),
                    'credit_minor' => (int) ($line['credit_minor'] ?? 0),
                    'description' => $line['description'] ?? null,
                ]);
            }

            AuditLog::create([
                'tenant_id' => $tenantId,
                'actor_user_id' => $entryData['posted_by'] ?? auth()->id(),
                'action' => 'journal_posted',
                'entity_type' => JournalEntry::class,
                'entity_id' => $entry->id,
                'after' => [
                    'entry_number' => $entry->entry_number,
                    'source_type' => $entry->source_type,
                    'source_id' => $entry->source_id,
                    'total_debit_minor' => $entry->lines->sum('debit_minor'),
                ],
                'created_at' => now(),
            ]);

            return $entry->fresh(['lines.account']);
        });
    }

    /**
     * Reverse a posted journal entry by posting a balanced compensating entry.
     */
    public function reverseJournalEntry(JournalEntry $entry, ?string $userId = null, string $reason = '', ?Carbon $entryDate = null): JournalEntry
    {
        if ($entry->status !== JournalEntry::STATUS_POSTED) {
            throw new DomainException('Only posted journal entries can be reversed.');
        }

        if ($entry->reversal_of_id !== null) {
            throw new DomainException('Cannot reverse a compensating reversal entry.');
        }

        $lines = $entry->lines()->get();
        if ($lines->isEmpty()) {
            throw new DomainException('Cannot reverse journal entry without lines.');
        }

        $compensatingLines = [];
        foreach ($lines as $line) {
            $compensatingLines[] = [
                'account_id' => $line->account_id,
                'debit_minor' => (int) $line->credit_minor,
                'credit_minor' => (int) $line->debit_minor,
                'description' => "Compensating reversal: {$reason}",
            ];
        }

        $effectiveDate = $entryDate ? $entryDate->toDateString() : ($entry->entry_date instanceof Carbon ? $entry->entry_date->toDateString() : (string) $entry->entry_date);

        return DB::transaction(function () use ($entry, $compensatingLines, $userId, $reason, $effectiveDate) {
            $lockedEntry = JournalEntry::where('id', $entry->id)->lockForUpdate()->firstOrFail();

            if ($lockedEntry->status !== JournalEntry::STATUS_POSTED) {
                throw new DomainException('Only posted journal entries can be reversed.');
            }

            if ($lockedEntry->reversal_of_id !== null) {
                throw new DomainException('Cannot reverse a compensating reversal entry.');
            }

            $reversalEntry = $this->postJournal($lockedEntry->tenant_id, [
                'outlet_id' => $lockedEntry->outlet_id,
                'entry_date' => $effectiveDate,
                'source_type' => $lockedEntry->source_type === JournalEntry::SOURCE_EXPENSE ? JournalEntry::SOURCE_EXPENSE_REVERSAL : JournalEntry::SOURCE_REFUND,
                'source_id' => $lockedEntry->source_id,
                'description' => "Pembalikan Jurnal {$lockedEntry->entry_number}: {$reason}",
                'posted_by' => $userId ?? auth()->id(),
                'reversal_of_id' => $lockedEntry->id,
            ], $compensatingLines);

            $lockedEntry->status = JournalEntry::STATUS_REVERSED;
            $lockedEntry->reversed_at = now();
            $lockedEntry->reversed_by = $userId ?? auth()->id();
            $lockedEntry->reversal_of_id = $reversalEntry->id;
            $lockedEntry->save();

            return $reversalEntry;
        });
    }

    /**
     * Automated financial posting for finalized, paid sale.
     *
     * Idempotent: exactly one posted journal entry per sale.
     */
    public function postSale(Sale $sale): ?JournalEntry
    {
        // 1. Idempotency Check: already posted journal?
        $existing = JournalEntry::where('tenant_id', $sale->tenant_id)
            ->where('source_type', JournalEntry::SOURCE_SALE)
            ->where('source_id', $sale->id)
            ->where('status', JournalEntry::STATUS_POSTED)
            ->first();

        if ($existing) {
            return $existing;
        }

        // 2. Authoritative check: Must be finalized and fully paid
        if (! $sale->isFinalized()) {
            return null;
        }

        if ($sale->total_minor > 0 && $sale->getPaidAmountMinor() < $sale->total_minor) {
            // Unsettled/split payment remaining; defer until fully settled
            return null;
        }

        // Ensure default accounts exist
        $accounts = $this->ensureDefaultAccounts($sale->tenant_id);

        $lines = [];

        // 3. Resolve payments:
        // Cash payment debits Cash (1110)
        // Non-cash payment debits Bank Clearing (1120)
        $succeededPayments = $sale->payments()
            ->whereIn('status', [Payment::STATUS_SUCCEEDED, Payment::STATUS_PARTIALLY_REFUNDED, Payment::STATUS_REFUNDED])
            ->get();

        $totalPaymentDebit = 0;
        foreach ($succeededPayments as $payment) {
            $isCash = ($payment->method === Payment::METHOD_CASH);
            $debitAccount = $isCash ? $accounts[Account::CODE_CASH] : $accounts[Account::CODE_BANK_CLEARING];

            $lines[] = [
                'account_id' => $debitAccount->id,
                'debit_minor' => (int) $payment->amount_minor,
                'credit_minor' => 0,
                'description' => "Penerimaan pembayaran {$payment->method} [{$payment->reference_number}]",
            ];
            $totalPaymentDebit += (int) $payment->amount_minor;
        }

        // If no payments found (e.g., zero total promo or awaiting payment), handle accordingly
        if ($totalPaymentDebit === 0) {
            if ($sale->total_minor === 0) {
                // Free sale or 100% discount, no financial journal needed
                return null;
            }

            // Cannot post uncollected sale without accounts receivable (AR deferred to later phase)
            return null;
        }

        // 4. Sales Revenue & Tax:
        $taxMinor = (int) ($sale->tax_minor ?? 0);
        $netRevenueMinor = max(0, (int) $sale->total_minor - $taxMinor);

        if ($taxMinor > 0) {
            $lines[] = [
                'account_id' => $accounts[Account::CODE_TAX_PAYABLE]->id,
                'debit_minor' => 0,
                'credit_minor' => $taxMinor,
                'description' => "Utang Pajak Penjualan {$sale->reference_number}",
            ];
        }

        $lines[] = [
            'account_id' => $accounts[Account::CODE_SALES_REVENUE]->id,
            'debit_minor' => 0,
            'credit_minor' => $netRevenueMinor,
            'description' => "Pendapatan Penjualan {$sale->reference_number}",
        ];

        // 5. Authoritative COGS from SaleConsumption
        $totalCogsMinor = (int) SaleConsumption::where('tenant_id', $sale->tenant_id)
            ->where('sale_id', $sale->id)
            ->sum('total_cost_minor');

        if ($totalCogsMinor > 0) {
            $lines[] = [
                'account_id' => $accounts[Account::CODE_COGS]->id,
                'debit_minor' => $totalCogsMinor,
                'credit_minor' => 0,
                'description' => "Beban Pokok Pendapatan (HPP) {$sale->reference_number}",
            ];
            $lines[] = [
                'account_id' => $accounts[Account::CODE_INVENTORY]->id,
                'debit_minor' => 0,
                'credit_minor' => $totalCogsMinor,
                'description' => "Pengurangan Persediaan Penjualan {$sale->reference_number}",
            ];
        }

        // Entry date from finalized_at or today
        $entryDate = $sale->finalized_at?->toDateString() ?? now()->toDateString();

        try {
            return $this->postJournal($sale->tenant_id, [
                'outlet_id' => $sale->outlet_id,
                'entry_date' => $entryDate,
                'source_type' => JournalEntry::SOURCE_SALE,
                'source_id' => $sale->id,
                'description' => "Jurnal Penjualan {$sale->reference_number}",
                'posted_by' => $sale->cashier_user_id,
            ], $lines);
        } catch (UniqueConstraintViolationException) {
            // Concurrency guard: already posted by parallel process
            return JournalEntry::where('tenant_id', $sale->tenant_id)
                ->where('source_type', JournalEntry::SOURCE_SALE)
                ->where('source_id', $sale->id)
                ->first();
        }
    }

    /**
     * Automated financial posting for completed refund.
     *
     * Idempotent: exactly one posted journal entry per refund.
     */
    public function postRefund(PaymentRefund $refund): ?JournalEntry
    {
        $existing = JournalEntry::where('tenant_id', $refund->tenant_id)
            ->where('source_type', JournalEntry::SOURCE_REFUND)
            ->where('source_id', $refund->id)
            ->where('status', JournalEntry::STATUS_POSTED)
            ->first();

        if ($existing) {
            return $existing;
        }

        if (! $refund->isCompleted()) {
            return null;
        }

        $accounts = $this->ensureDefaultAccounts($refund->tenant_id);

        $payment = $refund->payment;
        $isCash = ($payment && $payment->method === Payment::METHOD_CASH);
        $creditAccount = $isCash ? $accounts[Account::CODE_CASH] : $accounts[Account::CODE_BANK_CLEARING];

        $lines = [
            [
                'account_id' => $accounts[Account::CODE_SALES_RETURN]->id,
                'debit_minor' => (int) $refund->amount_minor,
                'credit_minor' => 0,
                'description' => "Retur Penjualan / Pengembalian dana [{$refund->reference_number}]",
            ],
            [
                'account_id' => $creditAccount->id,
                'debit_minor' => 0,
                'credit_minor' => (int) $refund->amount_minor,
                'description' => "Pengembalian dana {$payment?->method} [{$refund->reference_number}]",
            ],
        ];

        $entryDate = $refund->completed_at?->toDateString() ?? now()->toDateString();
        $outletId = $payment?->sale?->outlet_id;

        try {
            return $this->postJournal($refund->tenant_id, [
                'outlet_id' => $outletId,
                'entry_date' => $entryDate,
                'source_type' => JournalEntry::SOURCE_REFUND,
                'source_id' => $refund->id,
                'description' => "Jurnal Pengembalian Dana {$refund->reference_number}",
                'posted_by' => $refund->created_by,
            ], $lines);
        } catch (UniqueConstraintViolationException) {
            return JournalEntry::where('tenant_id', $refund->tenant_id)
                ->where('source_type', JournalEntry::SOURCE_REFUND)
                ->where('source_id', $refund->id)
                ->first();
        }
    }

    /**
     * Automated financial posting for manual drawer cash movements (cash_in / cash_out).
     *
     * Invariants:
     * - Sale payments and refunds are already posted via postSale and postRefund.
     * - Opening float and closing counts are administrative drawer sessions, not revenue/expense.
     * - Cash In posts Dr Cash (1110), Cr Owner Equity (3100) (drawer float/capital injection, not revenue).
     * - Cash Out posts Dr Owner Equity (3100), Cr Cash (1110) (drawer drawing/withdrawal, not expense).
     */
    public function postCashMovement(CashMovement $movement): ?JournalEntry
    {
        if (! in_array($movement->type, [CashMovement::TYPE_CASH_IN, CashMovement::TYPE_CASH_OUT], true)) {
            return null;
        }

        $existing = JournalEntry::where('tenant_id', $movement->tenant_id)
            ->where('source_type', JournalEntry::SOURCE_CASH_MOVEMENT)
            ->where('source_id', $movement->id)
            ->where('status', JournalEntry::STATUS_POSTED)
            ->first();

        if ($existing) {
            return $existing;
        }

        $accounts = $this->ensureDefaultAccounts($movement->tenant_id);
        $lines = [];

        if ($movement->type === CashMovement::TYPE_CASH_IN) {
            $lines = [
                [
                    'account_id' => $accounts[Account::CODE_CASH]->id,
                    'debit_minor' => (int) $movement->amount_minor,
                    'credit_minor' => 0,
                    'description' => 'Kas Masuk Laci: '.($movement->reason ?? 'Tambahan Kas/Modal'),
                ],
                [
                    'account_id' => $accounts[Account::CODE_OWNER_EQUITY]->id,
                    'debit_minor' => 0,
                    'credit_minor' => (int) $movement->amount_minor,
                    'description' => 'Setoran Kas/Modal Laci Kasir',
                ],
            ];
        } else {
            $lines = [
                [
                    'account_id' => $accounts[Account::CODE_OWNER_EQUITY]->id,
                    'debit_minor' => (int) $movement->amount_minor,
                    'credit_minor' => 0,
                    'description' => 'Penarikan Kas Laci Kasir',
                ],
                [
                    'account_id' => $accounts[Account::CODE_CASH]->id,
                    'debit_minor' => 0,
                    'credit_minor' => (int) $movement->amount_minor,
                    'description' => 'Kas Keluar Laci: '.($movement->reason ?? 'Penarikan Kas/Prive'),
                ],
            ];
        }

        $entryDate = $movement->created_at?->toDateString() ?? now()->toDateString();

        try {
            return $this->postJournal($movement->tenant_id, [
                'outlet_id' => $movement->outlet_id,
                'entry_date' => $entryDate,
                'source_type' => JournalEntry::SOURCE_CASH_MOVEMENT,
                'source_id' => $movement->id,
                'description' => "Jurnal Arus Kas Laci [{$movement->type}]",
                'posted_by' => $movement->performed_by_user_id,
            ], $lines);
        } catch (UniqueConstraintViolationException) {
            return JournalEntry::where('tenant_id', $movement->tenant_id)
                ->where('source_type', JournalEntry::SOURCE_CASH_MOVEMENT)
                ->where('source_id', $movement->id)
                ->first();
        }
    }

    /**
     * Create an operational expense record.
     *
     * @param  array{
     *     outlet_id?: string|null,
     *     account_id?: string|null,
     *     category: string,
     *     amount_minor: int,
     *     expense_date: string,
     *     payment_method: string,
     *     description?: string|null,
     *     reference_number?: string|null,
     *     status?: string|null
     * }  $data
     */
    public function createExpense(string $tenantId, array $data, ?string $userId = null): Expense
    {
        $accounts = $this->ensureDefaultAccounts($tenantId);

        // Resolve account: provided account_id or default Operating Expense (6100)
        $accountId = $data['account_id'] ?? $accounts[Account::CODE_OPERATING_EXPENSE]->id;

        $amountMinor = (int) $data['amount_minor'];
        if ($amountMinor <= 0) {
            throw new DomainException('Expense amount must be strictly greater than zero.');
        }

        $paymentMethod = $data['payment_method'];
        if (! in_array($paymentMethod, [Expense::PAYMENT_METHOD_CASH, Expense::PAYMENT_METHOD_BANK])) {
            throw new DomainException('Payment method must be either cash or bank.');
        }

        $status = $data['status'] ?? Expense::STATUS_POSTED;

        return DB::transaction(function () use ($tenantId, $data, $accountId, $amountMinor, $paymentMethod, $status, $userId) {
            $expense = Expense::create([
                'tenant_id' => $tenantId,
                'outlet_id' => $data['outlet_id'] ?? null,
                'account_id' => $accountId,
                'category' => $data['category'],
                'amount_minor' => $amountMinor,
                'expense_date' => Carbon::parse($data['expense_date'])->toDateString(),
                'payment_method' => $paymentMethod,
                'status' => Expense::STATUS_DRAFT, // Initially draft before posting
                'description' => $data['description'] ?? null,
                'reference_number' => $data['reference_number'] ?? null,
                'recorded_by' => $userId ?? auth()->id(),
            ]);

            AuditLog::create([
                'tenant_id' => $tenantId,
                'actor_user_id' => $userId ?? auth()->id(),
                'action' => 'expense_created',
                'entity_type' => Expense::class,
                'entity_id' => $expense->id,
                'after' => [
                    'amount_minor' => $expense->amount_minor,
                    'category' => $expense->category,
                    'payment_method' => $expense->payment_method,
                ],
                'created_at' => now(),
            ]);

            if ($status === Expense::STATUS_POSTED) {
                return $this->postExpense($expense, $userId);
            }

            return $expense->fresh(['account', 'outlet']);
        });
    }

    /**
     * Atomically post an expense to the double-entry ledger.
     */
    public function postExpense(Expense $expense, ?string $userId = null): Expense
    {
        if ($expense->isPosted()) {
            return $expense;
        }

        if ($expense->isReversed()) {
            throw new DomainException('Cannot post an already reversed expense.');
        }

        $accounts = $this->ensureDefaultAccounts($expense->tenant_id);

        $isCash = ($expense->payment_method === Expense::PAYMENT_METHOD_CASH);
        $creditAccount = $isCash ? $accounts[Account::CODE_CASH] : $accounts[Account::CODE_BANK_CLEARING];

        $lines = [
            [
                'account_id' => $expense->account_id,
                'debit_minor' => (int) $expense->amount_minor,
                'credit_minor' => 0,
                'description' => "Beban: {$expense->category} - ".($expense->description ?? 'Operasional'),
            ],
            [
                'account_id' => $creditAccount->id,
                'debit_minor' => 0,
                'credit_minor' => (int) $expense->amount_minor,
                'description' => "Pembayaran beban {$expense->payment_method}",
            ],
        ];

        return DB::transaction(function () use ($expense, $lines, $userId) {
            $this->postJournal($expense->tenant_id, [
                'outlet_id' => $expense->outlet_id,
                'entry_date' => $expense->expense_date->toDateString(),
                'source_type' => JournalEntry::SOURCE_EXPENSE,
                'source_id' => $expense->id,
                'description' => "Jurnal Pengeluaran: {$expense->category} (".($expense->reference_number ?? $expense->id).')',
                'posted_by' => $userId ?? auth()->id(),
            ], $lines);

            $expense->status = Expense::STATUS_POSTED;
            $expense->posted_at = now();
            $expense->save();

            AuditLog::create([
                'tenant_id' => $expense->tenant_id,
                'actor_user_id' => $userId ?? auth()->id(),
                'action' => 'expense_posted',
                'entity_type' => Expense::class,
                'entity_id' => $expense->id,
                'after' => [
                    'status' => Expense::STATUS_POSTED,
                    'amount_minor' => $expense->amount_minor,
                ],
                'created_at' => now(),
            ]);

            return $expense->fresh(['account', 'outlet']);
        });
    }

    /**
     * Reverse a posted expense by issuing compensating double-entry lines.
     */
    public function reverseExpense(Expense $expense, string $reason, ?string $userId = null): Expense
    {
        if (! $expense->isPosted()) {
            throw new DomainException('Only posted expenses can be reversed.');
        }

        if ($expense->isReversed()) {
            throw new DomainException('Expense has already been reversed.');
        }

        $accounts = $this->ensureDefaultAccounts($expense->tenant_id);

        $isCash = ($expense->payment_method === Expense::PAYMENT_METHOD_CASH);
        $debitAccount = $isCash ? $accounts[Account::CODE_CASH] : $accounts[Account::CODE_BANK_CLEARING];

        // Compensating reversal lines (Debit Cash/Bank, Credit Expense)
        $lines = [
            [
                'account_id' => $debitAccount->id,
                'debit_minor' => (int) $expense->amount_minor,
                'credit_minor' => 0,
                'description' => "Pembalikan Kas/Bank atas pembatalan beban {$expense->id}",
            ],
            [
                'account_id' => $expense->account_id,
                'debit_minor' => 0,
                'credit_minor' => (int) $expense->amount_minor,
                'description' => "Pembalikan Beban: {$expense->category} - Alasan: {$reason}",
            ],
        ];

        return DB::transaction(function () use ($expense, $lines, $userId, $reason) {
            // Find original journal entry
            $originalEntry = JournalEntry::where('tenant_id', $expense->tenant_id)
                ->where('source_type', JournalEntry::SOURCE_EXPENSE)
                ->where('source_id', $expense->id)
                ->where('status', JournalEntry::STATUS_POSTED)
                ->first();

            // Post reversal entry
            $reversalEntry = $this->postJournal($expense->tenant_id, [
                'outlet_id' => $expense->outlet_id,
                'entry_date' => now()->toDateString(),
                'source_type' => JournalEntry::SOURCE_EXPENSE_REVERSAL,
                'source_id' => $expense->id,
                'description' => "Pembalikan Beban {$expense->category}: {$reason}",
                'posted_by' => $userId ?? auth()->id(),
            ], $lines);

            // Mark original entry as reversed
            if ($originalEntry) {
                $originalEntry->status = JournalEntry::STATUS_REVERSED;
                $originalEntry->reversed_at = now();
                $originalEntry->reversed_by = $userId ?? auth()->id();
                $originalEntry->reversal_of_id = $reversalEntry->id;
                $originalEntry->save();
            }

            // Mark expense as reversed
            $expense->status = Expense::STATUS_REVERSED;
            $expense->reversed_at = now();
            $expense->reversed_by = $userId ?? auth()->id();
            $expense->save();

            AuditLog::create([
                'tenant_id' => $expense->tenant_id,
                'actor_user_id' => $userId ?? auth()->id(),
                'action' => 'expense_reversed',
                'entity_type' => Expense::class,
                'entity_id' => $expense->id,
                'after' => [
                    'status' => Expense::STATUS_REVERSED,
                    'reason' => $reason,
                ],
                'created_at' => now(),
            ]);

            return $expense->fresh(['account', 'outlet']);
        });
    }

    /**
     * Compute authoritative Profit & Loss report from posted double-entry journals.
     *
     * Formula:
     *   Revenue = Sales Revenue (4100 credit - debit) - Sales Return (4110 debit - credit)
     *   COGS = COGS (5100 debit - credit)
     *   Gross Profit = Revenue - COGS
     *   Operating Expenses = Operating Expense (6100 debit - credit)
     *   Net Profit = Gross Profit - Operating Expenses
     */
    public function getProfitAndLoss(
        string $tenantId,
        ?string $outletId,
        Carbon $startDate,
        Carbon $endDate,
        string $timezone = 'Asia/Jakarta',
        bool $compare = false
    ): array {
        $startDateStr = $startDate->copy()->setTimezone($timezone)->toDateString();
        $endDateStr = $endDate->copy()->setTimezone($timezone)->toDateString();

        $accounts = $this->ensureDefaultAccounts($tenantId);

        $query = JournalLine::query()
            ->join('journal_entries', 'journal_lines.journal_entry_id', '=', 'journal_entries.id')
            ->where('journal_lines.tenant_id', $tenantId)
            ->whereIn('journal_entries.status', [JournalEntry::STATUS_POSTED, JournalEntry::STATUS_REVERSED])
            ->whereBetween('journal_entries.entry_date', [$startDateStr, $endDateStr]);

        if ($outletId) {
            $query->where('journal_entries.outlet_id', $outletId);
        }

        // 1. Revenue
        $retAccountId = $accounts[Account::CODE_SALES_RETURN]->id;

        $grossRevenue = (int) (clone $query)
            ->join('accounts', 'journal_lines.account_id', '=', 'accounts.id')
            ->where('accounts.type', Account::TYPE_REVENUE)
            ->where('accounts.id', '!=', $retAccountId)
            ->selectRaw('COALESCE(SUM(journal_lines.credit_minor - journal_lines.debit_minor), 0) as balance')
            ->value('balance');

        $salesReturn = (int) (clone $query)->where('journal_lines.account_id', $retAccountId)
            ->selectRaw('COALESCE(SUM(journal_lines.debit_minor - journal_lines.credit_minor), 0) as balance')
            ->value('balance');

        $netRevenue = $grossRevenue - $salesReturn;

        // 2. COGS
        $cogsAccountId = $accounts[Account::CODE_COGS]->id;
        $cogs = (int) (clone $query)->where('journal_lines.account_id', $cogsAccountId)
            ->selectRaw('COALESCE(SUM(journal_lines.debit_minor - journal_lines.credit_minor), 0) as balance')
            ->value('balance');

        $grossProfit = $netRevenue - $cogs;
        $grossMarginPct = $netRevenue > 0 ? round(($grossProfit / $netRevenue) * 100, 2) : 0.0;

        // 3. Operating Expenses (all expense accounts excluding COGS)
        $operatingExpense = (int) (clone $query)
            ->join('accounts', 'journal_lines.account_id', '=', 'accounts.id')
            ->where('accounts.type', Account::TYPE_EXPENSE)
            ->where('accounts.id', '!=', $cogsAccountId)
            ->selectRaw('COALESCE(SUM(journal_lines.debit_minor - journal_lines.credit_minor), 0) as balance')
            ->value('balance');

        // Breakdown by expense category
        $expenseCategoryQuery = Expense::where('tenant_id', $tenantId)
            ->where('status', Expense::STATUS_POSTED)
            ->whereBetween('expense_date', [$startDateStr, $endDateStr]);

        if ($outletId) {
            $expenseCategoryQuery->where('outlet_id', $outletId);
        }

        $categoryBreakdown = $expenseCategoryQuery
            ->selectRaw('category, SUM(amount_minor) as total_minor')
            ->groupBy('category')
            ->pluck('total_minor', 'category')
            ->map(fn ($val) => (int) $val)
            ->toArray();

        $netProfit = $grossProfit - $operatingExpense;
        $netMarginPct = $netRevenue > 0 ? round(($netProfit / $netRevenue) * 100, 2) : 0.0;

        $result = [
            'revenue_minor' => $netRevenue,
            'cogs_minor' => $cogs,
            'gross_profit_minor' => $grossProfit,
            'gross_margin_pct' => $grossMarginPct,
            'expense_minor' => $operatingExpense,
            'net_profit_minor' => $netProfit,
            'net_margin_pct' => $netMarginPct,
            'breakdown' => [
                'gross_revenue_minor' => $grossRevenue,
                'sales_return_minor' => $salesReturn,
                'cogs_minor' => $cogs,
                'operating_expense_minor' => $operatingExpense,
                'categories' => $categoryBreakdown,
            ],
            'period' => [
                'start_date' => $startDateStr,
                'end_date' => $endDateStr,
                'timezone' => $timezone,
            ],
        ];

        if ($compare) {
            $startCarbon = Carbon::parse($startDateStr, $timezone);
            $endCarbon = Carbon::parse($endDateStr, $timezone);
            $diffDays = $startCarbon->diffInDays($endCarbon) + 1;
            $prevEnd = $startCarbon->copy()->subDay();
            $prevStart = $prevEnd->copy()->subDays($diffDays - 1);

            $prevReport = $this->getProfitAndLoss($tenantId, $outletId, $prevStart, $prevEnd, $timezone, false);
            $prevRev = $prevReport['revenue_minor'];
            $prevNet = $prevReport['net_profit_minor'];

            $revGrowthPct = $prevRev > 0
                ? round((($netRevenue - $prevRev) / $prevRev) * 100, 2)
                : ($netRevenue > 0 ? 100.0 : ($netRevenue < 0 ? -100.0 : 0.0));

            $netGrowthPct = $prevNet != 0
                ? round((($netProfit - $prevNet) / abs($prevNet)) * 100, 2)
                : ($netProfit > 0 ? 100.0 : ($netProfit < 0 ? -100.0 : 0.0));

            $result['comparison'] = [
                'previous_period' => [
                    'start_date' => $prevStart->toDateString(),
                    'end_date' => $prevEnd->toDateString(),
                ],
                'previous_revenue_minor' => $prevRev,
                'previous_cogs_minor' => $prevReport['cogs_minor'],
                'previous_gross_profit_minor' => $prevReport['gross_profit_minor'],
                'previous_expense_minor' => $prevReport['expense_minor'],
                'previous_net_profit_minor' => $prevNet,
                'revenue_growth_pct' => $revGrowthPct,
                'net_profit_growth_pct' => $netGrowthPct,
            ];
        }

        return $result;
    }

    /**
     * Compute authoritative Cash Flow report from posted Cash ledger movements.
     *
     * Invariants enforced:
     *   Cash movements strictly evaluate physical cash account (1110) transactions.
     *   QRIS/Bank settlements do not count as physical cash in hand.
     */
    public function getCashFlow(
        string $tenantId,
        ?string $outletId,
        Carbon $startDate,
        Carbon $endDate,
        string $timezone = 'Asia/Jakarta'
    ): array {
        $startDateStr = $startDate->copy()->setTimezone($timezone)->toDateString();
        $endDateStr = $endDate->copy()->setTimezone($timezone)->toDateString();

        $accounts = $this->ensureDefaultAccounts($tenantId);
        $cashAccountId = $accounts[Account::CODE_CASH]->id;

        // Opening cash balance prior to start date
        $openingQuery = JournalLine::query()
            ->join('journal_entries', 'journal_lines.journal_entry_id', '=', 'journal_entries.id')
            ->where('journal_lines.tenant_id', $tenantId)
            ->where('journal_lines.account_id', $cashAccountId)
            ->whereIn('journal_entries.status', [JournalEntry::STATUS_POSTED, JournalEntry::STATUS_REVERSED])
            ->where('journal_entries.entry_date', '<', $startDateStr);

        if ($outletId) {
            $openingQuery->where('journal_entries.outlet_id', $outletId);
        }

        $openingCash = (int) $openingQuery->selectRaw('COALESCE(SUM(debit_minor - credit_minor), 0) as balance')->value('balance');

        $query = JournalLine::query()
            ->join('journal_entries', 'journal_lines.journal_entry_id', '=', 'journal_entries.id')
            ->where('journal_lines.tenant_id', $tenantId)
            ->where('journal_lines.account_id', $cashAccountId)
            ->whereIn('journal_entries.status', [JournalEntry::STATUS_POSTED, JournalEntry::STATUS_REVERSED])
            ->whereBetween('journal_entries.entry_date', [$startDateStr, $endDateStr]);

        if ($outletId) {
            $query->where('journal_entries.outlet_id', $outletId);
        }

        // Inflow: SUM(debit_minor) on Cash account
        $cashInTotal = (int) (clone $query)->sum('journal_lines.debit_minor');

        // Outflow: SUM(credit_minor) on Cash account
        $cashOutTotal = (int) (clone $query)->sum('journal_lines.credit_minor');

        $netCashflow = $cashInTotal - $cashOutTotal;
        $closingCash = $openingCash + $netCashflow;

        // Breakdown by source
        $cashSales = (int) (clone $query)
            ->where('journal_entries.source_type', JournalEntry::SOURCE_SALE)
            ->sum('journal_lines.debit_minor');

        $cashExpenses = (int) (clone $query)
            ->where('journal_entries.source_type', JournalEntry::SOURCE_EXPENSE)
            ->sum('journal_lines.credit_minor');

        $cashRefunds = (int) (clone $query)
            ->where('journal_entries.source_type', JournalEntry::SOURCE_REFUND)
            ->sum('journal_lines.credit_minor');

        $otherCashIn = max(0, $cashInTotal - $cashSales);
        $otherCashOut = max(0, $cashOutTotal - $cashExpenses - $cashRefunds);

        return [
            'opening_cash_minor' => $openingCash,
            'cash_in_minor' => $cashInTotal,
            'cash_out_minor' => $cashOutTotal,
            'net_cashflow_minor' => $netCashflow,
            'closing_cash_minor' => $closingCash,
            'breakdown' => [
                'cash_sales_minor' => $cashSales,
                'cash_expenses_minor' => $cashExpenses,
                'cash_refunds_minor' => $cashRefunds,
                'other_cash_in_minor' => $otherCashIn,
                'other_cash_out_minor' => $otherCashOut,
            ],
            'period' => [
                'start_date' => $startDateStr,
                'end_date' => $endDateStr,
                'timezone' => $timezone,
            ],
        ];
    }

    /**
     * Compute authoritative Balance Sheet as of given date.
     *
     * Invariant: Total Assets === Total Liabilities + Total Equity
     */
    public function getBalanceSheet(
        string $tenantId,
        ?string $outletId,
        Carbon $asOfDate,
        string $timezone = 'Asia/Jakarta'
    ): array {
        $asOfDateStr = $asOfDate->copy()->setTimezone($timezone)->toDateString();
        $accounts = $this->ensureDefaultAccounts($tenantId);

        $baseQuery = JournalLine::query()
            ->join('journal_entries', 'journal_lines.journal_entry_id', '=', 'journal_entries.id')
            ->where('journal_lines.tenant_id', $tenantId)
            ->whereIn('journal_entries.status', [JournalEntry::STATUS_POSTED, JournalEntry::STATUS_REVERSED])
            ->where('journal_entries.entry_date', '<=', $asOfDateStr);

        if ($outletId) {
            $baseQuery->where('journal_entries.outlet_id', $outletId);
        }

        // 1. Assets (Normal Balance: Debit - Credit)
        $cashBal = (int) (clone $baseQuery)
            ->where('journal_lines.account_id', $accounts[Account::CODE_CASH]->id)
            ->selectRaw('COALESCE(SUM(journal_lines.debit_minor - journal_lines.credit_minor), 0) as bal')
            ->value('bal');

        $clearingBal = (int) (clone $baseQuery)
            ->where('journal_lines.account_id', $accounts[Account::CODE_BANK_CLEARING]->id)
            ->selectRaw('COALESCE(SUM(journal_lines.debit_minor - journal_lines.credit_minor), 0) as bal')
            ->value('bal');

        $inventoryBal = (int) (clone $baseQuery)
            ->where('journal_lines.account_id', $accounts[Account::CODE_INVENTORY]->id)
            ->selectRaw('COALESCE(SUM(journal_lines.debit_minor - journal_lines.credit_minor), 0) as bal')
            ->value('bal');

        // Total Assets: Aggregates all asset accounts across Chart of Accounts
        $totalAssets = (int) (clone $baseQuery)
            ->join('accounts', 'journal_lines.account_id', '=', 'accounts.id')
            ->where('accounts.type', Account::TYPE_ASSET)
            ->selectRaw('COALESCE(SUM(journal_lines.debit_minor - journal_lines.credit_minor), 0) as bal')
            ->value('bal');

        // 2. Liabilities (Normal Balance: Credit - Debit)
        $taxPayableBal = (int) (clone $baseQuery)
            ->where('journal_lines.account_id', $accounts[Account::CODE_TAX_PAYABLE]->id)
            ->selectRaw('COALESCE(SUM(journal_lines.credit_minor - journal_lines.debit_minor), 0) as bal')
            ->value('bal');

        // Total Liabilities: Aggregates all liability accounts (Tax Payable, Accounts Payable, etc.)
        $totalLiabilities = (int) (clone $baseQuery)
            ->join('accounts', 'journal_lines.account_id', '=', 'accounts.id')
            ->where('accounts.type', Account::TYPE_LIABILITY)
            ->selectRaw('COALESCE(SUM(journal_lines.credit_minor - journal_lines.debit_minor), 0) as bal')
            ->value('bal');

        // 3. Equity (Normal Balance: Credit - Debit)
        $ownerEquityBal = (int) (clone $baseQuery)
            ->join('accounts', 'journal_lines.account_id', '=', 'accounts.id')
            ->where('accounts.type', Account::TYPE_EQUITY)
            ->selectRaw('COALESCE(SUM(journal_lines.credit_minor - journal_lines.debit_minor), 0) as bal')
            ->value('bal');

        // Retained / Cumulative Net Earnings from inception up to as_of_date:
        // Canonical Double-Entry truth: Independent Revenue Net minus Independent Expense Net
        $cumRevenueNet = (int) (clone $baseQuery)
            ->join('accounts', 'journal_lines.account_id', '=', 'accounts.id')
            ->where('accounts.type', Account::TYPE_REVENUE)
            ->selectRaw('COALESCE(SUM(journal_lines.credit_minor - journal_lines.debit_minor), 0) as bal')
            ->value('bal');

        $cumExpensesNet = (int) (clone $baseQuery)
            ->join('accounts', 'journal_lines.account_id', '=', 'accounts.id')
            ->where('accounts.type', Account::TYPE_EXPENSE)
            ->selectRaw('COALESCE(SUM(journal_lines.debit_minor - journal_lines.credit_minor), 0) as bal')
            ->value('bal');

        $retainedEarnings = $cumRevenueNet - $cumExpensesNet;
        $totalEquity = $ownerEquityBal + $retainedEarnings;

        $totalLiabilitiesAndEquity = $totalLiabilities + $totalEquity;
        $isBalanced = ($totalAssets === $totalLiabilitiesAndEquity);
        $discrepancy = $totalAssets - $totalLiabilitiesAndEquity;

        return [
            'as_of_date' => $asOfDateStr,
            'timezone' => $timezone,
            'assets' => [
                'cash_minor' => $cashBal,
                'clearing_minor' => $clearingBal,
                'inventory_minor' => $inventoryBal,
                'total_assets_minor' => $totalAssets,
            ],
            'liabilities' => [
                'tax_payable_minor' => $taxPayableBal,
                'total_liabilities_minor' => $totalLiabilities,
            ],
            'equity' => [
                'owner_equity_minor' => $ownerEquityBal,
                'retained_earnings_minor' => $retainedEarnings,
                'total_equity_minor' => $totalEquity,
            ],
            'total_liabilities_and_equity_minor' => $totalLiabilitiesAndEquity,
            'is_balanced' => $isBalanced,
            'discrepancy_minor' => $discrepancy,
        ];
    }

    /**
     * Compute Trial Balance across all active Chart of Accounts.
     *
     * Invariant: Total Debit === Total Credit
     */
    public function getTrialBalance(
        string $tenantId,
        ?string $outletId,
        Carbon $asOfDate,
        string $timezone = 'Asia/Jakarta'
    ): array {
        $asOfDateStr = $asOfDate->copy()->setTimezone($timezone)->toDateString();
        $this->ensureDefaultAccounts($tenantId);

        $accounts = Account::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->orderBy('code', 'asc')
            ->get();

        $baseQuery = JournalLine::query()
            ->join('journal_entries', 'journal_lines.journal_entry_id', '=', 'journal_entries.id')
            ->where('journal_lines.tenant_id', $tenantId)
            ->whereIn('journal_entries.status', [JournalEntry::STATUS_POSTED, JournalEntry::STATUS_REVERSED])
            ->where('journal_entries.entry_date', '<=', $asOfDateStr);

        if ($outletId) {
            $baseQuery->where('journal_entries.outlet_id', $outletId);
        }

        $items = [];
        $totalDebit = 0;
        $totalCredit = 0;

        foreach ($accounts as $acc) {
            $sumDebit = (int) (clone $baseQuery)
                ->where('journal_lines.account_id', $acc->id)
                ->sum('journal_lines.debit_minor');

            $sumCredit = (int) (clone $baseQuery)
                ->where('journal_lines.account_id', $acc->id)
                ->sum('journal_lines.credit_minor');

            $netDebit = 0;
            $netCredit = 0;

            if (in_array($acc->type, [Account::TYPE_ASSET, Account::TYPE_EXPENSE])) {
                $net = $sumDebit - $sumCredit;
                if ($net >= 0) {
                    $netDebit = $net;
                } else {
                    $netCredit = abs($net);
                }
            } else {
                $net = $sumCredit - $sumDebit;
                if ($net >= 0) {
                    $netCredit = $net;
                } else {
                    $netDebit = abs($net);
                }
            }

            $totalDebit += $netDebit;
            $totalCredit += $netCredit;

            $items[] = [
                'account_id' => $acc->id,
                'account_code' => $acc->code,
                'account_name' => $acc->name,
                'account_type' => $acc->type,
                'total_debit_minor' => $sumDebit,
                'total_credit_minor' => $sumCredit,
                'ending_debit_minor' => $netDebit,
                'ending_credit_minor' => $netCredit,
            ];
        }

        return [
            'as_of_date' => $asOfDateStr,
            'timezone' => $timezone,
            'accounts' => $items,
            'total_debit_minor' => $totalDebit,
            'total_credit_minor' => $totalCredit,
            'is_balanced' => ($totalDebit === $totalCredit),
            'discrepancy_minor' => $totalDebit - $totalCredit,
        ];
    }

    /**
     * Compute chronological Account Ledger with running balance.
     */
    public function getAccountLedger(
        string $tenantId,
        string $accountCode,
        ?string $outletId,
        Carbon $startDate,
        Carbon $endDate,
        string $timezone = 'Asia/Jakarta',
        int $page = 1,
        int $perPage = 50
    ): array {
        $startDateStr = $startDate->copy()->setTimezone($timezone)->toDateString();
        $endDateStr = $endDate->copy()->setTimezone($timezone)->toDateString();

        $account = $this->getAccount($tenantId, $accountCode);

        // Opening balance calculation based on normal balance type
        $openingQuery = JournalLine::query()
            ->join('journal_entries', 'journal_lines.journal_entry_id', '=', 'journal_entries.id')
            ->where('journal_lines.tenant_id', $tenantId)
            ->where('journal_lines.account_id', $account->id)
            ->whereIn('journal_entries.status', [JournalEntry::STATUS_POSTED, JournalEntry::STATUS_REVERSED])
            ->where('journal_entries.entry_date', '<', $startDateStr);

        if ($outletId) {
            $openingQuery->where('journal_entries.outlet_id', $outletId);
        }

        $isDebitNormal = in_array($account->type, [Account::TYPE_ASSET, Account::TYPE_EXPENSE]);

        $openingBalance = $isDebitNormal
            ? (int) $openingQuery->selectRaw('COALESCE(SUM(debit_minor - credit_minor), 0) as bal')->value('bal')
            : (int) $openingQuery->selectRaw('COALESCE(SUM(credit_minor - debit_minor), 0) as bal')->value('bal');

        // Period movements
        $periodQuery = JournalLine::query()
            ->join('journal_entries', 'journal_lines.journal_entry_id', '=', 'journal_entries.id')
            ->where('journal_lines.tenant_id', $tenantId)
            ->where('journal_lines.account_id', $account->id)
            ->whereIn('journal_entries.status', [JournalEntry::STATUS_POSTED, JournalEntry::STATUS_REVERSED])
            ->whereBetween('journal_entries.entry_date', [$startDateStr, $endDateStr]);

        if ($outletId) {
            $periodQuery->where('journal_entries.outlet_id', $outletId);
        }

        $allLines = (clone $periodQuery)
            ->select([
                'journal_lines.id',
                'journal_lines.journal_entry_id',
                'journal_lines.debit_minor',
                'journal_lines.credit_minor',
                'journal_lines.description as line_description',
                'journal_entries.entry_number',
                'journal_entries.entry_date',
                'journal_entries.source_type',
                'journal_entries.source_id',
                'journal_entries.description as entry_description',
                'journal_entries.created_at',
            ])
            ->orderBy('journal_entries.entry_date', 'asc')
            ->orderBy('journal_entries.created_at', 'asc')
            ->orderBy('journal_lines.id', 'asc')
            ->get();

        $running = $openingBalance;
        $totalPeriodDebit = 0;
        $totalPeriodCredit = 0;
        $processedLines = [];

        foreach ($allLines as $line) {
            $debit = (int) $line->debit_minor;
            $credit = (int) $line->credit_minor;
            $totalPeriodDebit += $debit;
            $totalPeriodCredit += $credit;

            if ($isDebitNormal) {
                $running += ($debit - $credit);
            } else {
                $running += ($credit - $debit);
            }

            $processedLines[] = [
                'id' => $line->id,
                'entry_id' => $line->journal_entry_id,
                'entry_number' => $line->entry_number,
                'entry_date' => $line->entry_date instanceof Carbon ? $line->entry_date->toDateString() : (string) $line->entry_date,
                'source_type' => $line->source_type,
                'source_id' => $line->source_id,
                'description' => $line->line_description ?: $line->entry_description,
                'debit_minor' => $debit,
                'credit_minor' => $credit,
                'running_balance_minor' => $running,
            ];
        }

        $closingBalance = $running;

        // Paginate processed lines (or return all lines if perPage <= 0, e.g. for complete report exports)
        $totalCount = count($processedLines);
        if ($perPage > 0) {
            $offset = max(0, ($page - 1) * $perPage);
            $pagedLines = array_slice($processedLines, $offset, $perPage);
            $lastPage = (int) ceil(max(1, $totalCount) / $perPage);
            $currentPage = $page;
        } else {
            $pagedLines = $processedLines;
            $lastPage = 1;
            $currentPage = 1;
        }

        return [
            'account' => [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'type' => $account->type,
                'normal_balance' => $isDebitNormal ? 'debit' : 'credit',
            ],
            'period' => [
                'start_date' => $startDateStr,
                'end_date' => $endDateStr,
                'timezone' => $timezone,
            ],
            'opening_balance_minor' => $openingBalance,
            'total_debit_minor' => $totalPeriodDebit,
            'total_credit_minor' => $totalPeriodCredit,
            'closing_balance_minor' => $closingBalance,
            'pagination' => [
                'current_page' => $currentPage,
                'per_page' => $perPage > 0 ? $perPage : $totalCount,
                'total' => $totalCount,
                'last_page' => $lastPage,
            ],
            'lines' => $pagedLines,
        ];
    }

    /**
     * Compute Payment Clearing & Reconciliation summary report.
     *
     * Combines Account 1120 ledger reality with Payment Reconciliation verification states.
     */
    public function getClearingReport(
        string $tenantId,
        ?string $outletId,
        Carbon $startDate,
        Carbon $endDate,
        string $timezone = 'Asia/Jakarta'
    ): array {
        $startDateStr = $startDate->copy()->setTimezone($timezone)->toDateString();
        $endDateStr = $endDate->copy()->setTimezone($timezone)->toDateString();

        $accounts = $this->ensureDefaultAccounts($tenantId);
        $clearingAccId = $accounts[Account::CODE_BANK_CLEARING]->id;

        // 1. Account 1120 Ledger reality
        $ledgerQuery = JournalLine::query()
            ->join('journal_entries', 'journal_lines.journal_entry_id', '=', 'journal_entries.id')
            ->where('journal_lines.tenant_id', $tenantId)
            ->where('journal_lines.account_id', $clearingAccId)
            ->whereIn('journal_entries.status', [JournalEntry::STATUS_POSTED, JournalEntry::STATUS_REVERSED])
            ->whereBetween('journal_entries.entry_date', [$startDateStr, $endDateStr]);

        if ($outletId) {
            $ledgerQuery->where('journal_entries.outlet_id', $outletId);
        }

        $totalClearedDebit = (int) (clone $ledgerQuery)->sum('journal_lines.debit_minor');
        $totalClearedCredit = (int) (clone $ledgerQuery)->sum('journal_lines.credit_minor');
        $netClearingMovement = $totalClearedDebit - $totalClearedCredit;

        // 2. Reconciliation records overlay
        $startUtc = $startDate->copy()->setTimezone($timezone)->startOfDay()->utc();
        $endUtc = $endDate->copy()->setTimezone($timezone)->endOfDay()->utc();

        $reconQuery = PaymentReconciliation::where('tenant_id', $tenantId)
            ->where(function ($q) use ($startUtc, $endUtc) {
                $q->whereBetween('reconciled_at', [$startUtc, $endUtc])
                    ->orWhereBetween('created_at', [$startUtc, $endUtc]);
            });

        if ($outletId) {
            $reconQuery->where('outlet_id', $outletId);
        }

        $matchedCount = (int) (clone $reconQuery)->where('reconciliation_status', PaymentReconciliation::STATUS_MATCHED)->count();
        $matchedAmount = (int) (clone $reconQuery)->where('reconciliation_status', PaymentReconciliation::STATUS_MATCHED)->sum('expected_amount_minor');

        $mismatchedCount = (int) (clone $reconQuery)->where('reconciliation_status', PaymentReconciliation::STATUS_MISMATCHED)->count();
        $mismatchedAmount = (int) (clone $reconQuery)->where('reconciliation_status', PaymentReconciliation::STATUS_MISMATCHED)->sum('expected_amount_minor');

        $needsReviewCount = (int) (clone $reconQuery)->whereIn('reconciliation_status', [PaymentReconciliation::STATUS_NEEDS_REVIEW, PaymentReconciliation::STATUS_UNMATCHED])->count();
        $needsReviewAmount = (int) (clone $reconQuery)->whereIn('reconciliation_status', [PaymentReconciliation::STATUS_NEEDS_REVIEW, PaymentReconciliation::STATUS_UNMATCHED])->sum('expected_amount_minor');

        $settledCount = (int) (clone $reconQuery)->where('settlement_status', PaymentReconciliation::SETTLEMENT_SETTLED)->count();
        $settledAmount = (int) (clone $reconQuery)->where('settlement_status', PaymentReconciliation::SETTLEMENT_SETTLED)->sum('net_amount_minor');

        $pendingSettlementCount = (int) (clone $reconQuery)->where('settlement_status', PaymentReconciliation::SETTLEMENT_PENDING)->count();
        $pendingSettlementAmount = (int) (clone $reconQuery)->where('settlement_status', PaymentReconciliation::SETTLEMENT_PENDING)->sum('expected_amount_minor');

        return [
            'period' => [
                'start_date' => $startDateStr,
                'end_date' => $endDateStr,
                'timezone' => $timezone,
            ],
            'clearing_ledger' => [
                'account_code' => Account::CODE_BANK_CLEARING,
                'debit_minor' => $totalClearedDebit,
                'credit_minor' => $totalClearedCredit,
                'net_movement_minor' => $netClearingMovement,
            ],
            'reconciliation_verification' => [
                'matched_count' => $matchedCount,
                'matched_amount_minor' => $matchedAmount,
                'mismatched_count' => $mismatchedCount,
                'mismatched_amount_minor' => $mismatchedAmount,
                'unresolved_count' => $needsReviewCount,
                'unresolved_amount_minor' => $needsReviewAmount,
                'settled_count' => $settledCount,
                'settled_amount_minor' => $settledAmount,
                'pending_settlement_count' => $pendingSettlementCount,
                'pending_settlement_amount_minor' => $pendingSettlementAmount,
            ],
        ];
    }

    /**
     * Generate sequential entry number.
     */
    protected function generateEntryNumber(string $tenantId, Carbon $date): string
    {
        $prefix = 'JE-'.$date->format('Ymd');
        $random = strtoupper(Str::random(5));

        return "{$prefix}-{$random}";
    }
}
