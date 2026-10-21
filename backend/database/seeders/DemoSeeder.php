<?php

namespace Database\Seeders;

use App\Domain\Subscription\SubscriptionService;
use App\Domain\Tenant\TenantManager;
use App\Models\Category;
use App\Models\Outlet;
use App\Models\Price;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\Table;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserTenantMembership;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Superadmin
        $superadmin = User::firstOrCreate(
            ['email' => 'admin@sewaroeng.id'],
            [
                'name' => 'Super Admin SeWaroeng',
                'phone_number' => '081111111111',
                'password' => Hash::make('password123'),
                'is_active' => true,
                'is_superadmin' => true,
            ]
        );

        // 2. Owner User
        $owner = User::firstOrCreate(
            ['email' => 'owner@sewaroeng.id'],
            [
                'name' => 'Budi Santoso (Owner)',
                'phone_number' => '081234567890',
                'password' => Hash::make('password123'),
                'is_active' => true,
                'is_superadmin' => false,
            ]
        );

        // 3. Tenant / Warung
        $tenant = Tenant::firstOrCreate(
            ['code' => 'WARUNG-01'],
            [
                'name' => 'Warung Berkah Nusantara',
                'business_type' => 'fnb',
                'owner_user_id' => $owner->id,
                'is_active' => true,
            ]
        );

        // 4. Owner Membership
        $ownerRole = Role::where('slug', 'owner')->firstOrFail();
        UserTenantMembership::firstOrCreate(
            [
                'user_id' => $owner->id,
                'tenant_id' => $tenant->id,
            ],
            [
                'role_id' => $ownerRole->id,
                'status' => 'active',
                'outlet_access' => 'all',
            ]
        );

        // 5. Tenant context setup (Outlet, Subscription, Catalog, Tables)
        app(TenantManager::class)->runForTenant($tenant, function () use ($tenant, $owner) {
            $outlet = Outlet::firstOrCreate(
                [
                    'tenant_id' => $tenant->id,
                    'code' => 'OUT-01',
                ],
                [
                    'name' => 'Outlet Pusat (Jakarta)',
                    'is_active' => true,
                ]
            );

            // Active Subscription (Business Plan: includes AI Copilot, WhatsApp, Multi-Outlet, Advanced Reports)
            try {
                $businessPlan = \App\Models\SubscriptionPlan::where('code', \App\Models\SubscriptionPlan::CODE_BUSINESS)->first();
                if ($businessPlan) {
                    app(SubscriptionService::class)->createTrialSubscription($tenant, $businessPlan, 365);
                } else {
                    app(SubscriptionService::class)->createTrialSubscription($tenant);
                }
            } catch (\Throwable $e) {}

            // 6. Kasir User
            $cashierUser = User::firstOrCreate(
                ['email' => 'kasir@sewaroeng.id'],
                [
                    'name' => 'Siti Kasir',
                    'phone_number' => '081234567891',
                    'password' => Hash::make('password123'),
                    'is_active' => true,
                ]
            );

            $cashierRole = Role::where('slug', 'cashier')->firstOrFail();
            UserTenantMembership::firstOrCreate(
                [
                    'user_id' => $cashierUser->id,
                    'tenant_id' => $tenant->id,
                ],
                [
                    'role_id' => $cashierRole->id,
                    'status' => 'active',
                    'outlet_access' => 'specific',
                    'assigned_outlet_ids' => [$outlet->id],
                ]
            );

            // 7. Categories
            $catMakanan = Category::firstOrCreate(
                ['tenant_id' => $tenant->id, 'slug' => 'makanan-utama'],
                ['name' => 'Makanan Utama', 'status' => 'active']
            );
            $catMinuman = Category::firstOrCreate(
                ['tenant_id' => $tenant->id, 'slug' => 'minuman-kopi'],
                ['name' => 'Minuman & Kopi', 'status' => 'active']
            );
            $catSnack = Category::firstOrCreate(
                ['tenant_id' => $tenant->id, 'slug' => 'snack-camilan'],
                ['name' => 'Snack & Camilan', 'status' => 'active']
            );

            // 8. Products & Prices
            $products = [
                ['name' => 'Nasi Goreng Spesial', 'slug' => 'nasi-goreng-spesial', 'cat' => $catMakanan, 'price' => 25000],
                ['name' => 'Ayam Geprek Sambal Bawang', 'slug' => 'ayam-geprek-sambal-bawang', 'cat' => $catMakanan, 'price' => 22000],
                ['name' => 'Kopi Susu Gula Aren', 'slug' => 'kopi-susu-gula-aren', 'cat' => $catMinuman, 'price' => 18000],
                ['name' => 'Matcha Latte', 'slug' => 'matcha-latte', 'cat' => $catMinuman, 'price' => 24000],
                ['name' => 'Es Teh Manis', 'slug' => 'es-teh-manis', 'cat' => $catMinuman, 'price' => 6000],
                ['name' => 'Roti Bakar Coklat Keju', 'slug' => 'roti-bakar-coklat-keju', 'cat' => $catSnack, 'price' => 18000],
            ];

            foreach ($products as $pData) {
                $prod = Product::firstOrCreate(
                    ['tenant_id' => $tenant->id, 'slug' => $pData['slug']],
                    [
                        'category_id' => $pData['cat']->id,
                        'name' => $pData['name'],
                        'status' => 'active',
                    ]
                );

                $variant = ProductVariant::firstOrCreate(
                    ['tenant_id' => $tenant->id, 'product_id' => $prod->id, 'name' => 'Porsi Standar'],
                    ['is_default' => true]
                );

                Price::firstOrCreate(
                    ['tenant_id' => $tenant->id, 'variant_id' => $variant->id, 'outlet_id' => null],
                    [
                        'amount' => $pData['price'],
                        'currency' => 'IDR',
                        'is_active' => true,
                    ]
                );
            }

            // Set Outlet Floor Plan Layout
            $outlet->settings = array_merge($outlet->settings ?? [], [
                'floor_plan' => [
                    'canvas_width' => 960,
                    'canvas_height' => 640,
                    'elements' => [
                        [
                            'id' => 'zone-indoor',
                            'type' => 'zone',
                            'x' => 30,
                            'y' => 30,
                            'width' => 430,
                            'height' => 580,
                            'label' => '❄️ AREA INDOOR (AC)',
                            'color' => '#1e293b',
                        ],
                        [
                            'id' => 'zone-outdoor',
                            'type' => 'zone',
                            'x' => 490,
                            'y' => 30,
                            'width' => 440,
                            'height' => 580,
                            'label' => '🌿 AREA OUTDOOR (SMOKING / TAMAN)',
                            'color' => '#064e3b',
                        ],
                        [
                            'id' => 'wall-divider',
                            'type' => 'wall',
                            'x1' => 475,
                            'y1' => 30,
                            'x2' => 475,
                            'y2' => 610,
                            'thickness' => 6,
                            'color' => '#64748b',
                        ],
                        [
                            'id' => 'text-entrance',
                            'type' => 'text',
                            'x' => 170,
                            'y' => 575,
                            'label' => '🚪 PINTU MASUK UTAMA',
                            'font_size' => 13,
                            'color' => '#94a3b8',
                        ],
                        [
                            'id' => 'text-cashier',
                            'type' => 'text',
                            'x' => 60,
                            'y' => 60,
                            'label' => '☕ KASIR & KITCHEN BAR',
                            'font_size' => 13,
                            'color' => '#38bdf8',
                        ],
                        [
                            'id' => 'text-stage',
                            'type' => 'text',
                            'x' => 590,
                            'y' => 60,
                            'label' => '🎸 LIVE ACOUSTIC STAGE',
                            'font_size' => 13,
                            'color' => '#fbbf24',
                        ],
                    ],
                ],
            ]);
            $outlet->save();

            // 9. Tables
            $tables = [
                ['code' => 'T-01', 'name' => 'Meja 01', 'capacity' => 2, 'x' => 70, 'y' => 140, 'width' => 85, 'height' => 85, 'shape' => 'square', 'zone' => 'indoor'],
                ['code' => 'T-02', 'name' => 'Meja 02', 'capacity' => 4, 'x' => 220, 'y' => 140, 'width' => 110, 'height' => 85, 'shape' => 'rect', 'zone' => 'indoor'],
                ['code' => 'T-03', 'name' => 'Meja 03', 'capacity' => 4, 'x' => 70, 'y' => 280, 'width' => 110, 'height' => 85, 'shape' => 'rect', 'zone' => 'indoor'],
                ['code' => 'T-04', 'name' => 'Meja 04', 'capacity' => 6, 'x' => 220, 'y' => 280, 'width' => 130, 'height' => 85, 'shape' => 'rect', 'zone' => 'indoor'],
                ['code' => 'VIP-01', 'name' => 'Meja VIP Lounge', 'capacity' => 8, 'x' => 110, 'y' => 430, 'width' => 180, 'height' => 95, 'shape' => 'rect', 'zone' => 'indoor'],
                ['code' => 'OUT-01', 'name' => 'Meja Taman 01', 'capacity' => 4, 'x' => 530, 'y' => 160, 'width' => 90, 'height' => 90, 'shape' => 'circle', 'zone' => 'outdoor'],
                ['code' => 'OUT-02', 'name' => 'Meja Taman 02', 'capacity' => 4, 'x' => 710, 'y' => 160, 'width' => 90, 'height' => 90, 'shape' => 'circle', 'zone' => 'outdoor'],
                ['code' => 'OUT-03', 'name' => 'Gazebo Outdoor', 'capacity' => 6, 'x' => 600, 'y' => 330, 'width' => 140, 'height' => 95, 'shape' => 'rect', 'zone' => 'outdoor'],
            ];

            foreach ($tables as $tData) {
                Table::updateOrCreate(
                    ['tenant_id' => $tenant->id, 'outlet_id' => $outlet->id, 'code' => $tData['code']],
                    [
                        'name' => $tData['name'],
                        'capacity' => $tData['capacity'],
                        'status' => Table::STATUS_AVAILABLE,
                        'is_active' => true,
                        'metadata' => [
                            'x' => $tData['x'],
                            'y' => $tData['y'],
                            'width' => $tData['width'],
                            'height' => $tData['height'],
                            'shape' => $tData['shape'],
                            'zone' => $tData['zone'],
                        ],
                    ]
                );
            }
        });
    }
}
