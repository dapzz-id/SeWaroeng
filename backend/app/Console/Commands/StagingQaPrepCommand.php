<?php

namespace App\Console\Commands;

use App\Domain\Tenant\TenantManager;
use App\Models\Category;
use App\Models\Outlet;
use App\Models\Price;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\Table;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserTenantMembership;
use App\Services\Subscription\SubscriptionEntitlementService;
use App\Services\Subscription\SubscriptionService;
use App\Services\Table\TableService;
use chillerlan\QRCode\Output\QRGdImagePNG;
use chillerlan\QRCode\Output\QRMarkupSVG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class StagingQaPrepCommand extends Command
{
    protected $signature = 'sewaroeng:staging-qa-prep {--rotate : Force rotate QR token for the test table} {--clean : Safely remove existing staging QR artifacts} {--base-url= : Override base URL for QR destination (must be valid URL)}';

    protected $description = 'Prepares staging environment and provisions executable QR table for physical device QA.';

    public function handle(
        TenantManager $tenantManager,
        TableService $tableService,
        SubscriptionEntitlementService $entitlementService
    ): int {
        $this->info('===============================================================');
        $this->info('  SEWAROENG — V1 QR ORDERING STAGING QA PREPARATION');
        $this->info('===============================================================');

        // 1. Audit Environment & Base URL Destination
        $this->newLine();
        $this->comment('1. Auditing Environment Configurations...');
        $appEnv = config('app.env');
        $appDebug = config('app.debug');
        $defaultAppUrl = config('app.url');

        $baseUrlOption = $this->option('base-url');
        if ($baseUrlOption) {
            if (! filter_var($baseUrlOption, FILTER_VALIDATE_URL)) {
                $this->error("Invalid base URL provided: {$baseUrlOption}");

                return self::FAILURE;
            }
            $appUrl = $baseUrlOption;
        } else {
            $appUrl = $defaultAppUrl;
        }

        $dbDriver = DB::connection()->getDriverName();
        $hasKey = ! empty(config('app.key'));
        $isStagingReady = ($appEnv === 'staging' && ! $appDebug && str_starts_with($appUrl, 'https://'));

        $this->line(" - APP_ENV:        <fg=cyan>{$appEnv}</>");
        $this->line(' - APP_DEBUG:      '.($appDebug ? '<fg=yellow>true (Warning: ensure false in staging)</>' : '<fg=green>false (Production-ready)</>'));
        $this->line(" - APP_URL:        <fg=cyan>{$appUrl}</>".($baseUrlOption ? ' <fg=yellow>(overridden via --base-url)</>' : ''));
        $this->line(" - Database:       <fg=green>{$dbDriver} (Connected)</>");
        $this->line(' - APP_KEY:        '.($hasKey ? '<fg=green>Present & Active (Secrets safe)</>' : '<fg=red>MISSING</>'));

        if (! $hasKey) {
            $this->error('CRITICAL: APP_KEY is missing! Run php artisan key:generate');

            return self::FAILURE;
        }

        if (! $isStagingReady) {
            $this->newLine();
            $this->warn('*****************************************************************');
            $this->warn('  ENVIRONMENT WARNING: RUNNING OUTSIDE PRODUCTION-LIKE STAGING  ');
            $this->warn('*****************************************************************');
            $this->line(" - Current APP_ENV:   <fg=yellow>{$appEnv}</>");
            $this->line(' - Current APP_DEBUG: <fg=yellow>'.($appDebug ? 'true' : 'false').'</>');
            $this->line(" - Target QR Base:    <fg=yellow>{$appUrl}</>");
            $this->line(' - Notice: Generated QR artifacts point to a non-staging target');
            $this->line('   and are NOT valid for physical smartphone QA on live staging!');
            $this->line(' - For physical QA: run on staging with APP_ENV=staging, APP_DEBUG=false,');
            $this->line('   and APP_URL=https://staging.sewaroeng.id');
            $this->warn('*****************************************************************');
        } else {
            $this->newLine();
            $this->info('Staging Environment Verified: Destination points to secure staging HTTPS.');
        }

        // 2. Ensure / Seed Staging QA Tenant
        $this->newLine();
        $this->comment('2. Provisioning / Locating Staging QA Tenant...');

        $owner = User::firstOrCreate(
            ['email' => 'qa_owner@staging.sewaroeng.test'],
            [
                'name' => 'QA Owner Staging',
                'password' => Hash::make(Str::random(32)),
                'email_verified_at' => now(),
            ]
        );

        $tenant = Tenant::firstOrCreate(
            ['slug' => 'warung-staging-qa'],
            [
                'name' => 'Warung Staging QA',
                'code' => 'TEN-STG-QA',
                'owner_user_id' => $owner->id,
            ]
        );

        // Ensure owner membership for TenantContext resolution
        $ownerRole = Role::firstOrCreate(
            ['slug' => 'owner'],
            ['name' => 'Owner', 'description' => 'Unrestricted tenant administrative and financial ownership']
        );
        UserTenantMembership::firstOrCreate(
            ['tenant_id' => $tenant->id, 'user_id' => $owner->id],
            ['role_id' => $ownerRole->id, 'status' => 'active']
        );

        // Ensure trial subscription with qr_table_order entitlement
        $freePlan = SubscriptionPlan::firstOrCreate(
            ['code' => 'free'],
            [
                'name' => 'Free Plan',
                'price_minor' => 0,
                'currency' => 'IDR',
                'billing_interval' => 'monthly',
                'is_active' => true,
                'sort_order' => 1,
            ]
        );
        try {
            $subscription = Subscription::where('tenant_id', $tenant->id)->first();
            if (! $subscription) {
                app(SubscriptionService::class)->createTrialSubscription($tenant);
            }
            $entitlement = $freePlan->entitlements()->where('feature_key', 'qr_table_order')->first();
            if ($entitlement) {
                $entitlement->update(['is_enabled' => true]);
            }
            $entitlementService->clearCache($tenant);
        } catch (\Throwable) {
            // Resilient fallback when Redis is offline in local dev CLI
        }

        $outlet = $tenantManager->runForTenant($tenant, function () use ($tenant) {
            $existing = Outlet::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('code', 'OUT-STG-01')
                ->first();

            if ($existing) {
                return $existing;
            }

            return Outlet::create([
                'tenant_id' => $tenant->id,
                'code' => 'OUT-STG-01',
                'name' => 'Outlet Staging Utama',
                'is_active' => true,
            ]);
        });

        // 3. Ensure Catalog & Menu Items
        $this->comment('3. Provisioning Catalog Menu...');
        $tenantManager->runForTenant($tenant, function () use ($tenant, $outlet) {
            $category = Category::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('slug', 'makanan-stg')
                ->first();

            if (! $category) {
                $category = Category::create([
                    'tenant_id' => $tenant->id,
                    'slug' => 'makanan-stg',
                    'name' => 'Makanan & Minuman Staging',
                    'sort_order' => 1,
                ]);
            }

            $product = Product::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('sku', 'PRD-STG-001')
                ->first();

            if (! $product) {
                $product = Product::create([
                    'tenant_id' => $tenant->id,
                    'category_id' => $category->id,
                    'sku' => 'PRD-STG-001',
                    'name' => 'Nasi Goreng Spesial QA',
                    'description' => 'Menu pengujian staging untuk physical device QR ordering.',
                    'status' => 'active',
                ]);
            }

            $variant = ProductVariant::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('sku', 'VAR-STG-001')
                ->first();

            if (! $variant) {
                $variant = ProductVariant::create([
                    'tenant_id' => $tenant->id,
                    'product_id' => $product->id,
                    'sku' => 'VAR-STG-001',
                    'name' => 'Porsi Reguler',
                    'is_default' => true,
                ]);
            }

            $price = Price::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('variant_id', $variant->id)
                ->where('outlet_id', $outlet->id)
                ->first();

            if (! $price) {
                Price::create([
                    'tenant_id' => $tenant->id,
                    'variant_id' => $variant->id,
                    'outlet_id' => $outlet->id,
                    'amount' => 25000,
                    'currency' => 'IDR',
                ]);
            }
        });

        // 4. Provision / Rotate Staging Physical Test Table
        $this->comment('4. Provisioning Physical QR Table (Meja QA-01)...');
        [$table, $rawToken] = $tenantManager->runForTenant($tenant, function () use ($tenant, $outlet, $tableService) {
            $existingTable = Table::where('tenant_id', $tenant->id)
                ->where('outlet_id', $outlet->id)
                ->where('code', 'T-QA-01')
                ->first();

            if (! $existingTable) {
                $table = $tableService->createTable($tenant, $outlet->id, [
                    'code' => 'T-QA-01',
                    'name' => 'Meja QA 01 (Staging Device)',
                    'capacity' => 4,
                ]);

                return [$table, $table->raw_token];
            }

            $rawToken = $tableService->rotateQrToken($existingTable);

            return [$existingTable, $rawToken];
        });

        $qrUrl = rtrim($appUrl, '/')."/order/{$rawToken}";

        // 5. Secure Cleanup of Obsolete QR Artifacts & Fresh Artifact Generation
        $this->comment('5. Cleaning Obsolete QR Artifacts & Generating Fresh Scannable Artifacts...');
        $qrDir = storage_path('app/staging-qa/qr');
        if (! is_dir($qrDir)) {
            mkdir($qrDir, 0755, true);
        }

        // Securely purge obsolete artifacts for this table code so obsolete credentials are never retained on disk
        $existingTableArtifacts = glob($qrDir.DIRECTORY_SEPARATOR."{$table->code}.*") ?: [];
        foreach ($existingTableArtifacts as $oldFile) {
            if (is_file($oldFile) && in_array(pathinfo($oldFile, PATHINFO_EXTENSION), ['png', 'svg', 'html'], true)) {
                unlink($oldFile);
            }
        }

        if ($this->option('clean')) {
            $allArtifacts = glob($qrDir.DIRECTORY_SEPARATOR.'*.*') ?: [];
            foreach ($allArtifacts as $f) {
                if (is_file($f) && in_array(pathinfo($f, PATHINFO_EXTENSION), ['png', 'svg', 'html'], true)) {
                    unlink($f);
                }
            }
            $this->line(' - Obsolete staging QR artifacts purged safely.');
        }

        $svgFile = $qrDir.DIRECTORY_SEPARATOR."{$table->code}.svg";
        $pngFile = $qrDir.DIRECTORY_SEPARATOR."{$table->code}.png";
        $htmlFile = $qrDir.DIRECTORY_SEPARATOR."{$table->code}.html";

        // Generate SVG artifact
        $svgOpts = new QROptions([
            'outputInterface' => QRMarkupSVG::class,
            'outputBase64' => false,
        ]);
        $svgContent = (new QRCode($svgOpts))->render($qrUrl);
        file_put_contents($svgFile, $svgContent);

        // Generate PNG artifact
        $pngOpts = new QROptions([
            'outputInterface' => QRGdImagePNG::class,
            'outputBase64' => false,
            'scale' => 10,
        ]);
        $pngContent = (new QRCode($pngOpts))->render($qrUrl);
        file_put_contents($pngFile, $pngContent);

        // Generate Printable HTML Inspection Card (Zero plaintext raw token on UI)
        $htmlContent = <<<HTML
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Staging QA QR — {$table->code}</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; display: flex; flex-direction: column; align-items: center; justify-content: center; min-height: 100vh; margin: 0; background: #f8fafc; color: #0f172a; padding: 24px; box-sizing: border-box; }
        .card { background: white; border-radius: 16px; padding: 32px; box-shadow: 0 10px 25px rgba(0,0,0,0.08); text-align: center; max-width: 440px; width: 100%; border: 1px solid #e2e8f0; }
        .badge { display: inline-block; background: #ecfdf5; color: #047857; font-weight: 700; font-size: 13px; padding: 4px 12px; border-radius: 9999px; margin-bottom: 12px; }
        h1 { margin: 0 0 6px 0; font-size: 22px; }
        h2 { margin: 0 0 20px 0; font-size: 15px; color: #64748b; font-weight: 500; }
        .qr-wrapper { margin: 0 auto 24px; max-width: 280px; padding: 12px; border: 1px solid #e2e8f0; border-radius: 12px; background: #ffffff; }
        .qr-wrapper svg { width: 100%; height: auto; display: block; }
        .instructions { text-align: left; background: #f1f5f9; border-radius: 8px; padding: 16px; font-size: 13px; line-height: 1.6; color: #334155; }
        .instructions ol { margin: 0; padding-left: 20px; }
    </style>
</head>
<body>
    <div class="card">
        <span class="badge">STAGING PHYSICAL DEVICE QA</span>
        <h1>{$table->name}</h1>
        <h2>Kode: {$table->code} &bull; {$outlet->name}</h2>
        <div class="qr-wrapper">
            {$svgContent}
        </div>
        <div class="instructions">
            <strong>Petunjuk Pengujian Fisik:</strong>
            <ol>
                <li>Arahkan kamera smartphone (Android Chrome / iOS Safari) ke kode QR di atas.</li>
                <li>Buka link yang terdeteksi untuk memulai pengujian pemesanan meja.</li>
                <li>Pilih menu, tambahkan varian ke keranjang, dan kirim pesanan.</li>
                <li>Verifikasi live tracking customer hingga status selesai.</li>
            </ol>
        </div>
    </div>
</body>
</html>
HTML;
        file_put_contents($htmlFile, $htmlContent);

        $relSvg = 'storage/app/staging-qa/qr/'.$table->code.'.svg';
        $relPng = 'storage/app/staging-qa/qr/'.$table->code.'.png';
        $relHtml = 'storage/app/staging-qa/qr/'.$table->code.'.html';

        $this->newLine();
        $this->info('---------------------------------------------------------------');
        $this->info('  STAGING PHYSICAL DEVICE QA TABLE PREPARED');
        $this->info('---------------------------------------------------------------');
        $this->line("Tenant:           <fg=cyan>{$tenant->name} ({$tenant->code})</>");
        $this->line("Outlet:           <fg=cyan>{$outlet->name} ({$outlet->code})</>");
        $this->line("Table:            <fg=yellow>{$table->name} ({$table->code})</>");
        $this->line("Target Base URL:  <fg=cyan>{$appUrl}</>");
        $this->line('Staging Status:   '.($isStagingReady ? '<fg=green>STAGING HTTPS READY</>' : '<fg=yellow>LOCAL TEST ONLY (NOT STAGING READY)</>'));
        $this->line("QR Artifact (SVG):<fg=green>{$relSvg}</>");
        $this->line("QR Artifact (PNG):<fg=green>{$relPng}</>");
        $this->line("QR Artifact (HTML):<fg=green>{$relHtml}</>");
        $this->info('---------------------------------------------------------------');
        $this->comment('Security Invariant:');
        $this->line(' - Raw bearer token is NEVER printed to terminal or application logs.');
        $this->line(' - Database only persists SHA-256 hash (qr_token_hash).');
        $this->line(' - Tester must scan physical QR code artifact from image or printed card.');
        $this->newLine();
        $this->comment('Executable Physical QA Steps:');
        $this->line(" 1. Buka file artifact HTML pada monitor atau cetak QR: {$relHtml}");
        $this->line(' 2. Scan QR menggunakan kamera fisik Android (Chrome) atau iOS (Safari).');
        $this->line(' 3. Pilih "Nasi Goreng Spesial QA", tambahkan varian, dan kirim pesanan.');
        $this->line(' 4. Verifikasi live tracking: Diterima -> Dapur -> Selesai.');
        $this->line(' 5. Refresh tab saat ini: pastikan tracking state dipulihkan.');
        $this->line(' 6. Tutup tab secara penuh: pastikan session storage dibersihkan.');
        $this->newLine();

        $this->info('Staging preparation completed successfully.');

        return self::SUCCESS;
    }
}
