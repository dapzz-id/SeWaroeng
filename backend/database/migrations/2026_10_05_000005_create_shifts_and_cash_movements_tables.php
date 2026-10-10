<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates:
     *   1. shifts: Shift / Cash Drawer Session per Outlet
     *   2. cash_movements: Immutable Cash Ledger
     *   3. RLS policies on shifts and cash_movements
     *   4. Database CHECK constraints and partial unique indexes
     *   5. Seed shift & cash permissions
     */
    public function up(): void
    {
        // 1. Shifts Table
        Schema::create('shifts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('outlet_id')->constrained('outlets')->cascadeOnDelete();
            $table->foreignUuid('opened_by_user_id')->constrained('users');
            $table->foreignUuid('closed_by_user_id')->nullable()->constrained('users');
            $table->string('status', 20)->default('open');
            $table->timestampTz('opened_at');
            $table->timestampTz('closed_at')->nullable();
            $table->bigInteger('opening_cash_minor')->default(0);
            $table->bigInteger('closing_cash_minor')->nullable();
            $table->bigInteger('expected_cash_minor')->nullable();
            $table->bigInteger('variance_minor')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'outlet_id', 'status']);
        });

        // 1b. Partial Unique Index: Exactly one open shift per outlet
        DB::statement("
            CREATE UNIQUE INDEX uq_shifts_active_outlet 
            ON shifts (outlet_id) 
            WHERE status = 'open';
        ");

        // 1c. Check constraints for Shift invariants
        DB::statement("
            ALTER TABLE shifts 
            ADD CONSTRAINT chk_shifts_status 
            CHECK (status IN ('open', 'closed'));
        ");
        DB::statement('
            ALTER TABLE shifts 
            ADD CONSTRAINT chk_shifts_opening_cash 
            CHECK (opening_cash_minor >= 0);
        ');
        DB::statement('
            ALTER TABLE shifts 
            ADD CONSTRAINT chk_shifts_closing_cash 
            CHECK (closing_cash_minor IS NULL OR closing_cash_minor >= 0);
        ');
        DB::statement("
            ALTER TABLE shifts 
            ADD CONSTRAINT chk_shifts_state_invariants 
            CHECK (
                (status = 'open' AND closed_at IS NULL AND closed_by_user_id IS NULL AND closing_cash_minor IS NULL AND expected_cash_minor IS NULL AND variance_minor IS NULL)
                OR
                (status = 'closed' AND closed_at IS NOT NULL AND closed_by_user_id IS NOT NULL AND closing_cash_minor IS NOT NULL AND expected_cash_minor IS NOT NULL AND variance_minor IS NOT NULL)
            );
        ");

        // 2. Cash Movements Table (Immutable Ledger)
        Schema::create('cash_movements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('outlet_id')->constrained('outlets')->cascadeOnDelete();
            $table->foreignUuid('shift_id')->constrained('shifts')->cascadeOnDelete();
            $table->string('type', 30);
            $table->string('direction', 10);
            $table->bigInteger('amount_minor');
            $table->string('reference_type', 50)->nullable();
            $table->string('reference_id', 100)->nullable();
            $table->string('reason', 500)->nullable();
            $table->foreignUuid('performed_by_user_id')->constrained('users');
            $table->jsonb('metadata')->nullable();
            $table->timestampTz('created_at');

            $table->index(['tenant_id', 'outlet_id']);
            $table->index(['shift_id', 'type']);
            $table->index(['reference_type', 'reference_id']);
        });

        // 2b. Partial unique index to prevent duplicate payment / refund cash movements in a shift
        DB::statement('
            CREATE UNIQUE INDEX uq_cash_movements_reference 
            ON cash_movements (shift_id, reference_type, reference_id) 
            WHERE reference_id IS NOT NULL;
        ');

        // 2c. Check constraints for Cash Movements
        DB::statement('
            ALTER TABLE cash_movements 
            ADD CONSTRAINT chk_cash_movements_amount 
            CHECK (amount_minor > 0);
        ');
        DB::statement("
            ALTER TABLE cash_movements 
            ADD CONSTRAINT chk_cash_movements_direction 
            CHECK (direction IN ('in', 'out'));
        ");
        DB::statement("
            ALTER TABLE cash_movements 
            ADD CONSTRAINT chk_cash_movements_type 
            CHECK (type IN ('opening', 'sale', 'cash_in', 'cash_out', 'refund', 'adjustment', 'closing'));
        ");

        // 3. PostgreSQL Row Level Security (RLS) on shifts and cash_movements
        DB::statement('ALTER TABLE shifts ENABLE ROW LEVEL SECURITY;');
        DB::statement('ALTER TABLE shifts FORCE ROW LEVEL SECURITY;');

        DB::statement("
            CREATE POLICY shifts_tenant_isolation_policy ON shifts
                FOR ALL
                USING (
                    tenant_id = NULLIF(current_setting('app.current_tenant_id', true), '')::uuid
                    OR NULLIF(current_setting('app.is_platform_context', true), '') = 'true'
                )
                WITH CHECK (
                    tenant_id = NULLIF(current_setting('app.current_tenant_id', true), '')::uuid
                    OR NULLIF(current_setting('app.is_platform_context', true), '') = 'true'
                );
        ");

        DB::statement('ALTER TABLE cash_movements ENABLE ROW LEVEL SECURITY;');
        DB::statement('ALTER TABLE cash_movements FORCE ROW LEVEL SECURITY;');

        DB::statement("
            CREATE POLICY cash_movements_tenant_isolation_policy ON cash_movements
                FOR ALL
                USING (
                    tenant_id = NULLIF(current_setting('app.current_tenant_id', true), '')::uuid
                    OR NULLIF(current_setting('app.is_platform_context', true), '') = 'true'
                )
                WITH CHECK (
                    tenant_id = NULLIF(current_setting('app.current_tenant_id', true), '')::uuid
                    OR NULLIF(current_setting('app.is_platform_context', true), '') = 'true'
                );
        ");

        // 4. Seed Permissions
        $shiftPermissions = [
            ['name' => 'Shift View', 'slug' => 'shift.view', 'group' => 'shift'],
            ['name' => 'Shift Open', 'slug' => 'shift.open', 'group' => 'shift'],
            ['name' => 'Shift Close', 'slug' => 'shift.close', 'group' => 'shift'],
            ['name' => 'Cash View', 'slug' => 'cash.view', 'group' => 'cash'],
            ['name' => 'Cash In', 'slug' => 'cash.in', 'group' => 'cash'],
            ['name' => 'Cash Out', 'slug' => 'cash.out', 'group' => 'cash'],
            ['name' => 'Cash Adjust', 'slug' => 'cash.adjust', 'group' => 'cash'],
        ];

        $created = [];
        foreach ($shiftPermissions as $p) {
            $created[$p['slug']] = Permission::firstOrCreate(['slug' => $p['slug']], $p);
        }

        // Assign to Cashier role
        $cashierRole = Role::where('slug', 'cashier')->first();
        $cashierRole?->permissions()->syncWithoutDetaching([
            $created['shift.view']->id,
            $created['shift.open']->id,
            $created['shift.close']->id,
            $created['cash.view']->id,
            $created['cash.in']->id,
            $created['cash.out']->id,
        ]);

        // Assign to Manager role (including cash.adjust)
        $managerRole = Role::where('slug', 'manager')->first();
        $managerRole?->permissions()->syncWithoutDetaching([
            $created['shift.view']->id,
            $created['shift.open']->id,
            $created['shift.close']->id,
            $created['cash.view']->id,
            $created['cash.in']->id,
            $created['cash.out']->id,
            $created['cash.adjust']->id,
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP POLICY IF EXISTS cash_movements_tenant_isolation_policy ON cash_movements;');
        DB::statement('DROP POLICY IF EXISTS shifts_tenant_isolation_policy ON shifts;');
        Schema::dropIfExists('cash_movements');
        Schema::dropIfExists('shifts');
    }
};
