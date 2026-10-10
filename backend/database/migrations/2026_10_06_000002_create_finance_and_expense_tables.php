<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates Phase 1G:
     *   1. accounts: Chart of accounts (double-entry ledger classifications)
     *   2. journal_entries: Immutable posted double-entry journal headers
     *   3. journal_lines: Immutable double-entry debit/credit rows
     *   4. expenses: Operational expense lifecycle
     *   5. Row-Level Security (RLS) enforcement and integrity constraints
     */
    public function up(): void
    {
        // 1. Accounts Table (Chart of Accounts)
        Schema::create('accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('code', 50);
            $table->string('name', 255);
            $table->string('type', 50); // asset, liability, equity, revenue, expense
            $table->boolean('is_system')->default(true);
            $table->boolean('is_active')->default(true);
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id'], 'uq_accounts_tenant_id_id');
            $table->unique(['tenant_id', 'code'], 'uq_accounts_tenant_code');
            $table->index(['tenant_id', 'type'], 'idx_accounts_tenant_type');
            $table->index(['tenant_id', 'is_active'], 'idx_accounts_tenant_active');
        });

        // 2. Journal Entries Table
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('outlet_id')->nullable();
            $table->string('entry_number', 50);
            $table->date('entry_date');
            $table->string('status', 50)->default('posted'); // draft, posted, reversed
            $table->string('source_type', 50); // sale, payment_refund, expense, expense_reversal, manual
            $table->uuid('source_id')->nullable();
            $table->text('description')->nullable();
            $table->timestampTz('posted_at')->nullable();
            $table->uuid('posted_by')->nullable();
            $table->timestampTz('reversed_at')->nullable();
            $table->uuid('reversed_by')->nullable();
            $table->uuid('reversal_of_id')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id'], 'uq_journal_entries_tenant_id_id');
            $table->unique(['tenant_id', 'entry_number'], 'uq_journal_entries_tenant_number');
            $table->index(['tenant_id', 'outlet_id', 'entry_date'], 'idx_journal_entries_tenant_outlet_date');
            $table->index(['tenant_id', 'status'], 'idx_journal_entries_tenant_status');
            $table->index(['tenant_id', 'source_type', 'source_id'], 'idx_journal_entries_source');
        });

        // Partial unique index: Guarantee automated idempotency so a single business source event cannot create duplicate posted entries
        DB::statement('CREATE UNIQUE INDEX uq_journal_entries_source_idempotent ON journal_entries (tenant_id, source_type, source_id) WHERE source_id IS NOT NULL AND status = \'posted\';');

        // Optional FK to outlets
        DB::statement('ALTER TABLE journal_entries ADD CONSTRAINT fk_journal_entries_outlet FOREIGN KEY (tenant_id, outlet_id) REFERENCES outlets (tenant_id, id) ON DELETE SET NULL;');

        // 3. Journal Lines Table
        Schema::create('journal_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('journal_entry_id');
            $table->uuid('account_id');
            $table->bigInteger('debit_minor')->default(0);
            $table->bigInteger('credit_minor')->default(0);
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id'], 'uq_journal_lines_tenant_id_id');
            $table->index(['tenant_id', 'journal_entry_id'], 'idx_journal_lines_entry');
            $table->index(['tenant_id', 'account_id'], 'idx_journal_lines_account');
        });

        DB::statement('ALTER TABLE journal_lines ADD CONSTRAINT fk_journal_lines_entry FOREIGN KEY (tenant_id, journal_entry_id) REFERENCES journal_entries (tenant_id, id) ON DELETE CASCADE;');
        DB::statement('ALTER TABLE journal_lines ADD CONSTRAINT fk_journal_lines_account FOREIGN KEY (tenant_id, account_id) REFERENCES accounts (tenant_id, id) ON DELETE RESTRICT;');
        DB::statement('ALTER TABLE journal_lines ADD CONSTRAINT chk_journal_lines_non_negative CHECK (debit_minor >= 0 AND credit_minor >= 0);');
        DB::statement('ALTER TABLE journal_lines ADD CONSTRAINT chk_journal_lines_has_amount CHECK (debit_minor > 0 OR credit_minor > 0);');

        // 4. Expenses Table
        Schema::create('expenses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('outlet_id')->nullable();
            $table->uuid('account_id');
            $table->string('category', 100); // utilities, supplies, rent, salary, maintenance, other
            $table->bigInteger('amount_minor');
            $table->date('expense_date');
            $table->string('payment_method', 50); // cash, bank
            $table->string('status', 50)->default('posted'); // draft, posted, reversed
            $table->text('description')->nullable();
            $table->string('reference_number', 100)->nullable();
            $table->uuid('recorded_by')->nullable();
            $table->timestampTz('posted_at')->nullable();
            $table->timestampTz('reversed_at')->nullable();
            $table->uuid('reversed_by')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id'], 'uq_expenses_tenant_id_id');
            $table->index(['tenant_id', 'outlet_id', 'expense_date'], 'idx_expenses_tenant_outlet_date');
            $table->index(['tenant_id', 'status'], 'idx_expenses_tenant_status');
            $table->index(['tenant_id', 'category'], 'idx_expenses_tenant_category');
        });

        DB::statement('ALTER TABLE expenses ADD CONSTRAINT fk_expenses_outlet FOREIGN KEY (tenant_id, outlet_id) REFERENCES outlets (tenant_id, id) ON DELETE SET NULL;');
        DB::statement('ALTER TABLE expenses ADD CONSTRAINT fk_expenses_account FOREIGN KEY (tenant_id, account_id) REFERENCES accounts (tenant_id, id) ON DELETE RESTRICT;');
        DB::statement('ALTER TABLE expenses ADD CONSTRAINT chk_expenses_positive_amount CHECK (amount_minor > 0);');

        // 5. PostgreSQL Row-Level Security (RLS) Policies
        DB::statement('ALTER TABLE accounts ENABLE ROW LEVEL SECURITY;');
        DB::statement('ALTER TABLE accounts FORCE ROW LEVEL SECURITY;');
        DB::statement("
            CREATE POLICY account_tenant_isolation_policy ON accounts
            FOR ALL
            USING (tenant_id = NULLIF(current_setting('app.current_tenant_id', true), '')::uuid);
        ");

        DB::statement('ALTER TABLE journal_entries ENABLE ROW LEVEL SECURITY;');
        DB::statement('ALTER TABLE journal_entries FORCE ROW LEVEL SECURITY;');
        DB::statement("
            CREATE POLICY journal_entry_tenant_isolation_policy ON journal_entries
            FOR ALL
            USING (tenant_id = NULLIF(current_setting('app.current_tenant_id', true), '')::uuid);
        ");

        DB::statement('ALTER TABLE journal_lines ENABLE ROW LEVEL SECURITY;');
        DB::statement('ALTER TABLE journal_lines FORCE ROW LEVEL SECURITY;');
        DB::statement("
            CREATE POLICY journal_line_tenant_isolation_policy ON journal_lines
            FOR ALL
            USING (tenant_id = NULLIF(current_setting('app.current_tenant_id', true), '')::uuid);
        ");

        DB::statement('ALTER TABLE expenses ENABLE ROW LEVEL SECURITY;');
        DB::statement('ALTER TABLE expenses FORCE ROW LEVEL SECURITY;');
        DB::statement("
            CREATE POLICY expense_tenant_isolation_policy ON expenses
            FOR ALL
            USING (tenant_id = NULLIF(current_setting('app.current_tenant_id', true), '')::uuid);
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP POLICY IF EXISTS expense_tenant_isolation_policy ON expenses;');
        DB::statement('DROP POLICY IF EXISTS journal_line_tenant_isolation_policy ON journal_lines;');
        DB::statement('DROP POLICY IF EXISTS journal_entry_tenant_isolation_policy ON journal_entries;');
        DB::statement('DROP POLICY IF EXISTS account_tenant_isolation_policy ON accounts;');

        Schema::dropIfExists('expenses');
        Schema::dropIfExists('journal_lines');
        Schema::dropIfExists('journal_entries');
        Schema::dropIfExists('accounts');
    }
};
