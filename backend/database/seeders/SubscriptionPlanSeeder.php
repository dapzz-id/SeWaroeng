<?php

namespace Database\Seeders;

use App\Models\SubscriptionAddOn;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionPlanEntitlement;
use Illuminate\Database\Seeder;

class SubscriptionPlanSeeder extends Seeder
{
    /**
     * Seeds canonical subscription plans, entitlements, and add-ons per PRD.
     */
    public function run(): void
    {
        $plans = [
            [
                'code' => SubscriptionPlan::CODE_FREE,
                'name' => 'Free / Trial',
                'description' => 'Paket dasar gratis untuk usaha mikro dan evaluasi fitur SeWaroeng.',
                'price_minor' => 0,
                'currency' => 'IDR',
                'billing_interval' => 'monthly',
                'is_active' => true,
                'sort_order' => 1,
                'entitlements' => [
                    'pos' => ['is_enabled' => true, 'quota_limit' => null],
                    'inventory' => ['is_enabled' => true, 'quota_limit' => null],
                    'customer_crm' => ['is_enabled' => true, 'quota_limit' => null],
                    'finance' => ['is_enabled' => true, 'quota_limit' => null],
                    'dashboard' => ['is_enabled' => true, 'quota_limit' => null],
                    'offline_pos' => ['is_enabled' => true, 'quota_limit' => null],
                    'multi_outlet' => ['is_enabled' => true, 'quota_limit' => null],
                    'booking' => ['is_enabled' => true, 'quota_limit' => null],
                    'qr_table_order' => ['is_enabled' => true, 'quota_limit' => null],
                    'loyalty' => ['is_enabled' => true, 'quota_limit' => null],
                    'advanced_reports' => ['is_enabled' => false, 'quota_limit' => null],
                    'whatsapp' => ['is_enabled' => false, 'quota_limit' => null],
                    'ai_copilot' => ['is_enabled' => false, 'quota_limit' => null],
                    'max_outlets' => ['is_enabled' => true, 'quota_limit' => 5],
                    'max_users' => ['is_enabled' => true, 'quota_limit' => 5],
                    'max_products' => ['is_enabled' => true, 'quota_limit' => 100],
                    'max_monthly_transactions' => ['is_enabled' => true, 'quota_limit' => 500],
                ],
            ],
            [
                'code' => SubscriptionPlan::CODE_STARTER,
                'name' => 'Starter',
                'description' => 'Paket untuk usaha berkembang dengan volume transaksi menengah.',
                'price_minor' => 9900000,
                'currency' => 'IDR',
                'billing_interval' => 'monthly',
                'is_active' => true,
                'sort_order' => 2,
                'entitlements' => [
                    'pos' => ['is_enabled' => true, 'quota_limit' => null],
                    'inventory' => ['is_enabled' => true, 'quota_limit' => null],
                    'customer_crm' => ['is_enabled' => true, 'quota_limit' => null],
                    'finance' => ['is_enabled' => true, 'quota_limit' => null],
                    'dashboard' => ['is_enabled' => true, 'quota_limit' => null],
                    'offline_pos' => ['is_enabled' => true, 'quota_limit' => null],
                    'multi_outlet' => ['is_enabled' => false, 'quota_limit' => null],
                    'booking' => ['is_enabled' => true, 'quota_limit' => null],
                    'qr_table_order' => ['is_enabled' => true, 'quota_limit' => null],
                    'loyalty' => ['is_enabled' => true, 'quota_limit' => null],
                    'advanced_reports' => ['is_enabled' => true, 'quota_limit' => null],
                    'whatsapp' => ['is_enabled' => false, 'quota_limit' => null],
                    'ai_copilot' => ['is_enabled' => false, 'quota_limit' => null],
                    'max_outlets' => ['is_enabled' => true, 'quota_limit' => 1],
                    'max_users' => ['is_enabled' => true, 'quota_limit' => 5],
                    'max_products' => ['is_enabled' => true, 'quota_limit' => 500],
                    'max_monthly_transactions' => ['is_enabled' => true, 'quota_limit' => 2000],
                ],
            ],
            [
                'code' => SubscriptionPlan::CODE_BUSINESS,
                'name' => 'Business',
                'description' => 'Solusi komprehensif untuk bisnis tunggal dengan kebutuhan analitik dan tim penuh.',
                'price_minor' => 19900000,
                'currency' => 'IDR',
                'billing_interval' => 'monthly',
                'is_active' => true,
                'sort_order' => 3,
                'entitlements' => [
                    'pos' => ['is_enabled' => true, 'quota_limit' => null],
                    'inventory' => ['is_enabled' => true, 'quota_limit' => null],
                    'customer_crm' => ['is_enabled' => true, 'quota_limit' => null],
                    'finance' => ['is_enabled' => true, 'quota_limit' => null],
                    'dashboard' => ['is_enabled' => true, 'quota_limit' => null],
                    'offline_pos' => ['is_enabled' => true, 'quota_limit' => null],
                    'multi_outlet' => ['is_enabled' => false, 'quota_limit' => null],
                    'booking' => ['is_enabled' => true, 'quota_limit' => null],
                    'qr_table_order' => ['is_enabled' => true, 'quota_limit' => null],
                    'loyalty' => ['is_enabled' => true, 'quota_limit' => null],
                    'advanced_reports' => ['is_enabled' => true, 'quota_limit' => null],
                    'whatsapp' => ['is_enabled' => true, 'quota_limit' => null],
                    'ai_copilot' => ['is_enabled' => true, 'quota_limit' => null],
                    'max_outlets' => ['is_enabled' => true, 'quota_limit' => 1],
                    'max_users' => ['is_enabled' => true, 'quota_limit' => 15],
                    'max_products' => ['is_enabled' => true, 'quota_limit' => null], // Unlimited
                    'max_monthly_transactions' => ['is_enabled' => true, 'quota_limit' => null], // Unlimited
                ],
            ],
            [
                'code' => SubscriptionPlan::CODE_MULTI_OUTLET,
                'name' => 'Multi-Outlet',
                'description' => 'Solusi multi-cabang terintegrasi untuk bisnis dengan banyak cabang.',
                'price_minor' => 39900000,
                'currency' => 'IDR',
                'billing_interval' => 'monthly',
                'is_active' => true,
                'sort_order' => 4,
                'entitlements' => [
                    'pos' => ['is_enabled' => true, 'quota_limit' => null],
                    'inventory' => ['is_enabled' => true, 'quota_limit' => null],
                    'customer_crm' => ['is_enabled' => true, 'quota_limit' => null],
                    'finance' => ['is_enabled' => true, 'quota_limit' => null],
                    'dashboard' => ['is_enabled' => true, 'quota_limit' => null],
                    'offline_pos' => ['is_enabled' => true, 'quota_limit' => null],
                    'multi_outlet' => ['is_enabled' => true, 'quota_limit' => null],
                    'booking' => ['is_enabled' => true, 'quota_limit' => null],
                    'qr_table_order' => ['is_enabled' => true, 'quota_limit' => null],
                    'loyalty' => ['is_enabled' => true, 'quota_limit' => null],
                    'advanced_reports' => ['is_enabled' => true, 'quota_limit' => null],
                    'whatsapp' => ['is_enabled' => true, 'quota_limit' => null],
                    'ai_copilot' => ['is_enabled' => true, 'quota_limit' => null],
                    'max_outlets' => ['is_enabled' => true, 'quota_limit' => 10],
                    'max_users' => ['is_enabled' => true, 'quota_limit' => 50],
                    'max_products' => ['is_enabled' => true, 'quota_limit' => null], // Unlimited
                    'max_monthly_transactions' => ['is_enabled' => true, 'quota_limit' => null], // Unlimited
                ],
            ],
        ];

        foreach ($plans as $planData) {
            $entitlements = $planData['entitlements'];
            unset($planData['entitlements']);

            $plan = SubscriptionPlan::firstOrCreate(
                ['code' => $planData['code']],
                $planData
            );

            foreach ($entitlements as $featureKey => $entitlementConfig) {
                SubscriptionPlanEntitlement::updateOrCreate(
                    [
                        'plan_id' => $plan->id,
                        'feature_key' => $featureKey,
                    ],
                    [
                        'is_enabled' => $entitlementConfig['is_enabled'],
                        'quota_limit' => $entitlementConfig['quota_limit'],
                    ]
                );
            }
        }

        // Add-ons
        $addOns = [
            [
                'code' => 'addon.whatsapp',
                'name' => 'Integrasi Notifikasi WhatsApp',
                'description' => 'Kirim nota dan alert operasional melalui WhatsApp resmi.',
                'price_minor' => 5000000,
                'feature_key' => 'whatsapp',
                'quota_increment' => null,
                'is_active' => true,
            ],
            [
                'code' => 'addon.ai_copilot',
                'name' => 'SeWaroeng AI Copilot & Forecasting',
                'description' => 'Prediksi kebutuhan stok dan asisten analitik bisnis berbasis AI.',
                'price_minor' => 7500000,
                'feature_key' => 'ai_copilot',
                'quota_increment' => null,
                'is_active' => true,
            ],
            [
                'code' => 'addon.additional_outlet',
                'name' => 'Tambahan 1 Outlet Aktif',
                'description' => 'Tambah kapasitas operasional untuk 1 cabang baru.',
                'price_minor' => 5000000,
                'feature_key' => 'max_outlets',
                'quota_increment' => 1,
                'is_active' => true,
            ],
            [
                'code' => 'addon.additional_staff',
                'name' => 'Tambahan 5 Akun Staff/Pengguna',
                'description' => 'Tambah kuota 5 akun staf atau kasir.',
                'price_minor' => 2500000,
                'feature_key' => 'max_users',
                'quota_increment' => 5,
                'is_active' => true,
            ],
            [
                'code' => 'addon.advanced_reports',
                'name' => 'Laporan Keuangan & Analitik Lanjutan',
                'description' => 'Akses laporan laba rugi mendalam dan pergerakan kas lanjutan.',
                'price_minor' => 3500000,
                'feature_key' => 'advanced_reports',
                'quota_increment' => null,
                'is_active' => true,
            ],
        ];

        foreach ($addOns as $addOnData) {
            SubscriptionAddOn::firstOrCreate(
                ['code' => $addOnData['code']],
                $addOnData
            );
        }
    }
}
