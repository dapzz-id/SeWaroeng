<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database with system roles and foundational permissions.
     */
    public function run(): void
    {
        $permissions = [
            ['name' => 'POS Sell', 'slug' => 'pos.sell', 'group' => 'pos'],
            ['name' => 'POS Discount', 'slug' => 'pos.discount', 'group' => 'pos'],
            ['name' => 'POS Refund', 'slug' => 'pos.refund', 'group' => 'pos'],
            ['name' => 'POS Void', 'slug' => 'pos.void', 'group' => 'pos'],
            ['name' => 'POS View', 'slug' => 'pos.view', 'group' => 'pos'],
            ['name' => 'POS Create', 'slug' => 'pos.create', 'group' => 'pos'],
            ['name' => 'POS Finalize', 'slug' => 'pos.finalize', 'group' => 'pos'],
            ['name' => 'POS Cancel', 'slug' => 'pos.cancel', 'group' => 'pos'],
            ['name' => 'POS History View', 'slug' => 'pos.history.view', 'group' => 'pos'],
            ['name' => 'Inventory View', 'slug' => 'inventory.view', 'group' => 'inventory'],
            ['name' => 'Inventory Adjust', 'slug' => 'inventory.adjust', 'group' => 'inventory'],
            ['name' => 'Inventory Opname Create', 'slug' => 'inventory.opname.create', 'group' => 'inventory'],
            ['name' => 'Inventory Opname Review', 'slug' => 'inventory.opname.review', 'group' => 'inventory'],
            ['name' => 'Inventory Opname Approve', 'slug' => 'inventory.opname.approve', 'group' => 'inventory'],
            ['name' => 'Inventory Transfer Create', 'slug' => 'inventory.transfer.create', 'group' => 'inventory'],
            ['name' => 'Inventory Transfer Approve', 'slug' => 'inventory.transfer.approve', 'group' => 'inventory'],
            ['name' => 'Inventory Transfer Receive', 'slug' => 'inventory.transfer.receive', 'group' => 'inventory'],
            ['name' => 'Inventory Location Manage', 'slug' => 'inventory.location.manage', 'group' => 'inventory'],
            ['name' => 'Inventory Threshold Manage', 'slug' => 'inventory.threshold.manage', 'group' => 'inventory'],
            ['name' => 'Finance View', 'slug' => 'finance.view', 'group' => 'finance'],
            ['name' => 'Finance Edit', 'slug' => 'finance.edit', 'group' => 'finance'],
            ['name' => 'Expense View', 'slug' => 'finance.expense.view', 'group' => 'finance'],
            ['name' => 'Expense Create', 'slug' => 'finance.expense.create', 'group' => 'finance'],
            ['name' => 'Expense Update', 'slug' => 'finance.expense.update', 'group' => 'finance'],
            ['name' => 'Expense Post', 'slug' => 'finance.expense.post', 'group' => 'finance'],
            ['name' => 'Expense Reverse', 'slug' => 'finance.expense.reverse', 'group' => 'finance'],
            ['name' => 'Finance Report View', 'slug' => 'finance.report.view', 'group' => 'finance'],
            ['name' => 'Employee Manage', 'slug' => 'employee.manage', 'group' => 'employee'],
            ['name' => 'Report View', 'slug' => 'report.view', 'group' => 'report'],
            ['name' => 'Booking Manage', 'slug' => 'booking.manage', 'group' => 'booking'],
            ['name' => 'Booking View', 'slug' => 'booking.view', 'group' => 'booking'],
            ['name' => 'Table View', 'slug' => 'table.view', 'group' => 'table'],
            ['name' => 'Table Manage', 'slug' => 'table.manage', 'group' => 'table'],
            ['name' => 'Order View', 'slug' => 'order.view', 'group' => 'order'],
            ['name' => 'Order Manage', 'slug' => 'order.manage', 'group' => 'order'],
            ['name' => 'Business View', 'slug' => 'business.view', 'group' => 'business'],
            ['name' => 'Business Update', 'slug' => 'business.update', 'group' => 'business'],
            ['name' => 'Outlet View', 'slug' => 'outlet.view', 'group' => 'outlet'],
            ['name' => 'Outlet Create', 'slug' => 'outlet.create', 'group' => 'outlet'],
            ['name' => 'Outlet Update', 'slug' => 'outlet.update', 'group' => 'outlet'],
            ['name' => 'Outlet Activate', 'slug' => 'outlet.activate', 'group' => 'outlet'],
            ['name' => 'Outlet Deactivate', 'slug' => 'outlet.deactivate', 'group' => 'outlet'],
            ['name' => 'Catalog View', 'slug' => 'catalog.view', 'group' => 'catalog'],
            ['name' => 'Catalog Create', 'slug' => 'catalog.create', 'group' => 'catalog'],
            ['name' => 'Catalog Update', 'slug' => 'catalog.update', 'group' => 'catalog'],
            ['name' => 'Catalog Archive', 'slug' => 'catalog.archive', 'group' => 'catalog'],
            ['name' => 'Catalog Price Update', 'slug' => 'catalog.price.update', 'group' => 'catalog'],
            ['name' => 'Catalog Modifier Manage', 'slug' => 'catalog.modifier.manage', 'group' => 'catalog'],
            ['name' => 'Catalog Category Manage', 'slug' => 'catalog.category.manage', 'group' => 'catalog'],
            ['name' => 'Purchasing View', 'slug' => 'purchasing.view', 'group' => 'purchasing'],
            ['name' => 'Purchasing Supplier Manage', 'slug' => 'purchasing.supplier.manage', 'group' => 'purchasing'],
            ['name' => 'Purchasing Create', 'slug' => 'purchasing.create', 'group' => 'purchasing'],
            ['name' => 'Purchasing Update', 'slug' => 'purchasing.update', 'group' => 'purchasing'],
            ['name' => 'Purchasing Submit', 'slug' => 'purchasing.submit', 'group' => 'purchasing'],
            ['name' => 'Purchasing Approve', 'slug' => 'purchasing.approve', 'group' => 'purchasing'],
            ['name' => 'Purchasing Receive', 'slug' => 'purchasing.receive', 'group' => 'purchasing'],
            ['name' => 'Purchasing Cancel', 'slug' => 'purchasing.cancel', 'group' => 'purchasing'],
            // Recipe/BOM permissions (Phase 1B.4)
            ['name' => 'Recipe View', 'slug' => 'recipe.view', 'group' => 'recipe'],
            ['name' => 'Recipe Create', 'slug' => 'recipe.create', 'group' => 'recipe'],
            ['name' => 'Recipe Update', 'slug' => 'recipe.update', 'group' => 'recipe'],
            ['name' => 'Recipe Publish', 'slug' => 'recipe.publish', 'group' => 'recipe'],
            ['name' => 'Recipe Archive', 'slug' => 'recipe.archive', 'group' => 'recipe'],
            ['name' => 'Recipe Cost View', 'slug' => 'recipe.cost.view', 'group' => 'recipe'],
            // Payment permissions (Phase 1D)
            ['name' => 'Payment View', 'slug' => 'payment.view', 'group' => 'payment'],
            ['name' => 'Payment Create', 'slug' => 'payment.create', 'group' => 'payment'],
            ['name' => 'Payment Process', 'slug' => 'payment.process', 'group' => 'payment'],
            ['name' => 'Payment Refund', 'slug' => 'payment.refund', 'group' => 'payment'],
            ['name' => 'Payment Cancel', 'slug' => 'payment.cancel', 'group' => 'payment'],
            ['name' => 'Payment Reconcile', 'slug' => 'payment.reconcile', 'group' => 'payment'],

            // Shift & Cash permissions (Phase 1F)
            ['name' => 'Shift View', 'slug' => 'shift.view', 'group' => 'shift'],
            ['name' => 'Shift Open', 'slug' => 'shift.open', 'group' => 'shift'],
            ['name' => 'Shift Close', 'slug' => 'shift.close', 'group' => 'shift'],
            ['name' => 'Cash View', 'slug' => 'cash.view', 'group' => 'cash'],
            ['name' => 'Cash In', 'slug' => 'cash.in', 'group' => 'cash'],
            ['name' => 'Cash Out', 'slug' => 'cash.out', 'group' => 'cash'],
            ['name' => 'Cash Adjust', 'slug' => 'cash.adjust', 'group' => 'cash'],

            // Customer / CRM permissions (Phase 1F)
            ['name' => 'Customer View', 'slug' => 'customer.view', 'group' => 'customer'],
            ['name' => 'Customer Create', 'slug' => 'customer.create', 'group' => 'customer'],
            ['name' => 'Customer Update', 'slug' => 'customer.update', 'group' => 'customer'],
            ['name' => 'Customer History View', 'slug' => 'customer.history.view', 'group' => 'customer'],

            // Receipt permissions (Phase 1F)
            ['name' => 'Receipt View', 'slug' => 'receipt.view', 'group' => 'pos'],
            ['name' => 'Receipt Reprint', 'slug' => 'receipt.reprint', 'group' => 'pos'],

            // Dashboard & Notification permissions (Phase 1H)
            ['name' => 'Dashboard View', 'slug' => 'dashboard.view', 'group' => 'dashboard'],
            ['name' => 'Dashboard Finance View', 'slug' => 'dashboard.view.finance', 'group' => 'dashboard'],
            ['name' => 'Notification View', 'slug' => 'notification.view', 'group' => 'notification'],
            ['name' => 'Notification Read', 'slug' => 'notification.read', 'group' => 'notification'],

            // Subscription permissions (Phase 1J)
            ['name' => 'Subscription View', 'slug' => 'subscription.view', 'group' => 'subscription'],
            ['name' => 'Subscription Manage', 'slug' => 'subscription.manage', 'group' => 'subscription'],

            // Loyalty permissions (Phase 1K / V1 Loyalty)
            ['name' => 'Loyalty View', 'slug' => 'loyalty.view', 'group' => 'loyalty'],
            ['name' => 'Loyalty Manage', 'slug' => 'loyalty.manage', 'group' => 'loyalty'],
            ['name' => 'Loyalty Adjust', 'slug' => 'loyalty.adjust', 'group' => 'loyalty'],
            ['name' => 'Loyalty Redeem', 'slug' => 'loyalty.redeem', 'group' => 'loyalty'],
            ['name' => 'Loyalty Report', 'slug' => 'loyalty.report', 'group' => 'loyalty'],

            // WhatsApp Integration permissions (Phase 1V / V1 WhatsApp)
            ['name' => 'WhatsApp View', 'slug' => 'whatsapp.view', 'group' => 'whatsapp'],
            ['name' => 'WhatsApp Manage', 'slug' => 'whatsapp.manage', 'group' => 'whatsapp'],
            ['name' => 'WhatsApp Send', 'slug' => 'whatsapp.send', 'group' => 'whatsapp'],
        ];

        $createdPermissions = [];
        foreach ($permissions as $p) {
            $createdPermissions[$p['slug']] = Permission::firstOrCreate(['slug' => $p['slug']], $p);
        }

        $roles = [
            ['name' => 'Owner', 'slug' => 'owner', 'description' => 'Unrestricted tenant administrative and financial ownership'],
            ['name' => 'Manager', 'slug' => 'manager', 'description' => 'Outlet operational and inventory management'],
            ['name' => 'Cashier', 'slug' => 'cashier', 'description' => 'Cashier operations and shift handling'],
            ['name' => 'Staff', 'slug' => 'staff', 'description' => 'General floor staff capabilities'],
            ['name' => 'Accountant', 'slug' => 'accountant', 'description' => 'Financial ledgers, expenses, and tax review'],
        ];

        foreach ($roles as $r) {
            Role::firstOrCreate(['slug' => $r['slug']], $r);
        }

        // Assign default permissions to non-owner roles
        $cashierRole = Role::where('slug', 'cashier')->first();
        $cashierRole?->permissions()->syncWithoutDetaching([
            $createdPermissions['pos.sell']->id,
            $createdPermissions['pos.view']->id,
            $createdPermissions['pos.create']->id,
            $createdPermissions['pos.finalize']->id,
            $createdPermissions['pos.history.view']->id,
            $createdPermissions['pos.discount']->id,
            $createdPermissions['catalog.view']->id,
            $createdPermissions['inventory.view']->id,
            $createdPermissions['payment.view']->id,
            $createdPermissions['payment.create']->id,
            $createdPermissions['payment.process']->id,
            $createdPermissions['shift.view']->id,
            $createdPermissions['shift.open']->id,
            $createdPermissions['shift.close']->id,
            $createdPermissions['cash.view']->id,
            $createdPermissions['cash.in']->id,
            $createdPermissions['cash.out']->id,
            $createdPermissions['customer.view']->id,
            $createdPermissions['customer.create']->id,
            $createdPermissions['customer.update']->id,
            $createdPermissions['customer.history.view']->id,
            $createdPermissions['receipt.view']->id,
            $createdPermissions['receipt.reprint']->id,
            $createdPermissions['notification.view']->id,
            $createdPermissions['notification.read']->id,
            $createdPermissions['table.view']->id,
            $createdPermissions['table.manage']->id,
            $createdPermissions['booking.view']->id,
            $createdPermissions['booking.manage']->id,
            $createdPermissions['order.view']->id,
            $createdPermissions['order.manage']->id,
            $createdPermissions['loyalty.view']->id,
            $createdPermissions['loyalty.redeem']->id,
            $createdPermissions['whatsapp.view']->id,
            $createdPermissions['whatsapp.send']->id,
        ]);

        $staffRole = Role::where('slug', 'staff')->first();
        $staffRole?->permissions()->syncWithoutDetaching([
            $createdPermissions['catalog.view']->id,
            $createdPermissions['notification.view']->id,
            $createdPermissions['notification.read']->id,
        ]);

        $managerRole = Role::where('slug', 'manager')->first();
        $managerRole?->permissions()->syncWithoutDetaching([
            $createdPermissions['pos.sell']->id,
            $createdPermissions['pos.view']->id,
            $createdPermissions['pos.create']->id,
            $createdPermissions['pos.finalize']->id,
            $createdPermissions['pos.cancel']->id,
            $createdPermissions['pos.history.view']->id,
            $createdPermissions['pos.discount']->id,
            $createdPermissions['pos.refund']->id,
            $createdPermissions['pos.void']->id,
            $createdPermissions['inventory.view']->id,
            $createdPermissions['inventory.adjust']->id,
            $createdPermissions['inventory.opname.create']->id,
            $createdPermissions['inventory.opname.review']->id,
            $createdPermissions['inventory.opname.approve']->id,
            $createdPermissions['inventory.transfer.create']->id,
            $createdPermissions['inventory.transfer.approve']->id,
            $createdPermissions['inventory.transfer.receive']->id,
            $createdPermissions['inventory.location.manage']->id,
            $createdPermissions['inventory.threshold.manage']->id,
            $createdPermissions['report.view']->id,
            $createdPermissions['booking.manage']->id,
            $createdPermissions['booking.view']->id,
            $createdPermissions['table.view']->id,
            $createdPermissions['table.manage']->id,
            $createdPermissions['order.view']->id,
            $createdPermissions['order.manage']->id,
            $createdPermissions['catalog.view']->id,
            $createdPermissions['catalog.create']->id,
            $createdPermissions['catalog.update']->id,
            $createdPermissions['catalog.archive']->id,
            $createdPermissions['catalog.modifier.manage']->id,
            $createdPermissions['catalog.category.manage']->id,
            $createdPermissions['purchasing.view']->id,
            $createdPermissions['purchasing.supplier.manage']->id,
            $createdPermissions['purchasing.create']->id,
            $createdPermissions['purchasing.update']->id,
            $createdPermissions['purchasing.submit']->id,
            $createdPermissions['purchasing.approve']->id,
            $createdPermissions['purchasing.receive']->id,
            $createdPermissions['purchasing.cancel']->id,
            $createdPermissions['recipe.view']->id,
            $createdPermissions['recipe.create']->id,
            $createdPermissions['recipe.update']->id,
            $createdPermissions['recipe.publish']->id,
            $createdPermissions['recipe.archive']->id,
            $createdPermissions['payment.view']->id,
            $createdPermissions['payment.create']->id,
            $createdPermissions['payment.process']->id,
            $createdPermissions['payment.refund']->id,
            $createdPermissions['payment.cancel']->id,
            $createdPermissions['payment.reconcile']->id,
            $createdPermissions['shift.view']->id,
            $createdPermissions['shift.open']->id,
            $createdPermissions['shift.close']->id,
            $createdPermissions['cash.view']->id,
            $createdPermissions['cash.in']->id,
            $createdPermissions['cash.out']->id,
            $createdPermissions['cash.adjust']->id,
            $createdPermissions['customer.view']->id,
            $createdPermissions['customer.create']->id,
            $createdPermissions['customer.update']->id,
            $createdPermissions['customer.history.view']->id,
            $createdPermissions['receipt.view']->id,
            $createdPermissions['receipt.reprint']->id,
            $createdPermissions['finance.expense.view']->id,
            $createdPermissions['finance.expense.create']->id,
            $createdPermissions['finance.expense.update']->id,
            $createdPermissions['finance.expense.post']->id,
            $createdPermissions['finance.report.view']->id,
            $createdPermissions['dashboard.view']->id,
            $createdPermissions['notification.view']->id,
            $createdPermissions['notification.read']->id,
            $createdPermissions['loyalty.view']->id,
            $createdPermissions['loyalty.manage']->id,
            $createdPermissions['loyalty.redeem']->id,
            $createdPermissions['loyalty.report']->id,
            $createdPermissions['whatsapp.view']->id,
            $createdPermissions['whatsapp.manage']->id,
            $createdPermissions['whatsapp.send']->id,
        ]);

        $accountantRole = Role::where('slug', 'accountant')->first();
        $accountantRole?->permissions()->syncWithoutDetaching([
            $createdPermissions['finance.view']->id,
            $createdPermissions['finance.edit']->id,
            $createdPermissions['finance.expense.view']->id,
            $createdPermissions['finance.expense.create']->id,
            $createdPermissions['finance.expense.update']->id,
            $createdPermissions['finance.expense.post']->id,
            $createdPermissions['finance.expense.reverse']->id,
            $createdPermissions['finance.report.view']->id,
            $createdPermissions['report.view']->id,
            $createdPermissions['recipe.view']->id,
            $createdPermissions['recipe.cost.view']->id,
            $createdPermissions['payment.view']->id,
            $createdPermissions['payment.reconcile']->id,
            $createdPermissions['dashboard.view']->id,
            $createdPermissions['dashboard.view.finance']->id,
            $createdPermissions['notification.view']->id,
            $createdPermissions['notification.read']->id,
        ]);

        $this->call(SubscriptionPlanSeeder::class);
    }
}
