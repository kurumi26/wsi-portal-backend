<?php

namespace Database\Seeders;

use App\Models\CustomerService;
use App\Models\PortalNotification;
use App\Models\PortalOrder;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        DB::table('personal_access_tokens')->truncate();
        DB::table('profile_update_requests')->truncate();
        DB::table('portal_notifications')->truncate();
        DB::table('customer_services')->truncate();
        DB::table('payments')->truncate();
        DB::table('order_items')->truncate();
        DB::table('portal_orders')->truncate();
        DB::table('service_addons')->truncate();
        DB::table('service_configurations')->truncate();
        DB::table('services')->truncate();
        DB::table('users')->truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        $customer = User::create([
            'name' => 'Jamal Saberola',
            'email' => 'customer@wsiportal.com',
            'company' => 'WSI Demo Client',
            'address' => 'Davao City, Davao del Sur, Philippines',
            'mobile_number' => '+63 912 345 6789',
            'profile_photo_url' => 'https://ui-avatars.com/api/?name=Jamal+Saberola&background=0f172a&color=7dd3fc&size=256',
            'two_factor_enabled' => true,
            'role' => 'customer',
            'is_enabled' => true,
            'password' => 'password',
        ]);

        $admin = User::create([
            'name' => 'Administrators',
            'email' => 'admin@wsiportal.com',
            'company' => 'WSI',
            'address' => 'Makati City, Metro Manila, Philippines',
            'mobile_number' => '+63 917 555 0101',
            'profile_photo_url' => 'https://ui-avatars.com/api/?name=Administrators&background=0f172a&color=fb923c&size=256',
            'two_factor_enabled' => false,
            'role' => 'admin',
            'is_enabled' => true,
            'password' => 'password',
        ]);

        User::insert([
            [
                'name' => 'Juan Dela Cruz',
                'email' => 'juan@cloudhost.ph',
                'company' => 'WSI Internal Team',
                'address' => 'Quezon City, Metro Manila, Philippines',
                'mobile_number' => '+63 998 700 1001',
                'profile_photo_url' => 'https://ui-avatars.com/api/?name=Juan+Dela+Cruz&background=0f172a&color=fb923c&size=256',
                'two_factor_enabled' => false,
                'role' => 'admin',
                'is_enabled' => true,
                'password' => bcrypt('password'),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Sanya Lopez',
                'email' => 'sanya.tech@cloudhost.ph',
                'company' => 'WSI Internal Team',
                'address' => 'Pasig City, Metro Manila, Philippines',
                'mobile_number' => '+63 998 700 1002',
                'profile_photo_url' => 'https://ui-avatars.com/api/?name=Sanya+Lopez&background=0f172a&color=7dd3fc&size=256',
                'two_factor_enabled' => false,
                'role' => 'technical_support',
                'is_enabled' => true,
                'password' => bcrypt('password'),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Mark Rivera',
                'email' => 'mark.sales@cloudhost.ph',
                'company' => 'WSI Internal Team',
                'address' => 'Mandaluyong City, Metro Manila, Philippines',
                'mobile_number' => '+63 998 700 1003',
                'profile_photo_url' => 'https://ui-avatars.com/api/?name=Mark+Rivera&background=0f172a&color=fdba74&size=256',
                'two_factor_enabled' => false,
                'role' => 'sales',
                'is_enabled' => false,
                'password' => bcrypt('password'),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Ana Reyes',
                'email' => 'ana.reyes@cloudhost.ph',
                'company' => 'WSI Internal Team',
                'address' => 'Cainta, Rizal, Philippines',
                'mobile_number' => '+63 998 700 1004',
                'profile_photo_url' => 'https://ui-avatars.com/api/?name=Ana+Reyes&background=0f172a&color=7dd3fc&size=256',
                'two_factor_enabled' => false,
                'role' => 'technical_support',
                'is_enabled' => true,
                'password' => bcrypt('password'),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Leo Santos',
                'email' => 'leo@cloudhost.ph',
                'company' => 'WSI Internal Team',
                'address' => 'Makati City, Metro Manila, Philippines',
                'mobile_number' => '+63 998 700 1005',
                'profile_photo_url' => 'https://ui-avatars.com/api/?name=Leo+Santos&background=0f172a&color=fb923c&size=256',
                'two_factor_enabled' => false,
                'role' => 'admin',
                'is_enabled' => true,
                'password' => bcrypt('password'),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Acme Digital Inc.',
                'email' => 'admin@acmedigital.com',
                'company' => 'Acme Digital Inc.',
                'address' => 'Cebu City, Cebu, Philippines',
                'mobile_number' => '+63 998 100 2001',
                'profile_photo_url' => 'https://ui-avatars.com/api/?name=Acme+Digital+Inc&background=0f172a&color=7dd3fc&size=256',
                'two_factor_enabled' => false,
                'role' => 'customer',
                'is_enabled' => true,
                'password' => bcrypt('client1234'),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Northwind Logistics',
                'email' => 'it@northwindlogistics.com',
                'company' => 'Northwind Logistics',
                'address' => 'Taguig City, Metro Manila, Philippines',
                'mobile_number' => '+63 998 100 2002',
                'profile_photo_url' => 'https://ui-avatars.com/api/?name=Northwind+Logistics&background=0f172a&color=7dd3fc&size=256',
                'two_factor_enabled' => false,
                'role' => 'customer',
                'is_enabled' => true,
                'password' => bcrypt('client1234'),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'PixelCraft Studio',
                'email' => 'ops@pixelcraft.io',
                'company' => 'PixelCraft Studio',
                'address' => 'Iloilo City, Iloilo, Philippines',
                'mobile_number' => '+63 998 100 2003',
                'profile_photo_url' => 'https://ui-avatars.com/api/?name=PixelCraft+Studio&background=0f172a&color=7dd3fc&size=256',
                'two_factor_enabled' => false,
                'role' => 'customer',
                'is_enabled' => true,
                'password' => bcrypt('client1234'),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $catalog = collect([
            [
                'category' => 'Domains',
                'name' => 'Top Level Domains',
                'price' => 1440.00,
                'billing_cycle' => 'yearly',
                'addons' => [
                    ['label' => 'WhoIs', 'extra_price' => 780.00],
                    ['label' => 'Secure Socket Layer (Wildcard SSL)', 'extra_price' => 23400.00],
                    ['label' => 'Secure Socket Layer (Standard SSL)', 'extra_price' => 10800.00],
                ],
            ],
            [
                'category' => 'Domains',
                'name' => 'Country Level Domains',
                'price' => 2880.00,
                'billing_cycle' => 'yearly',
                'addons' => [
                    ['label' => 'WhoIs', 'extra_price' => 780.00],
                    ['label' => 'Secure Socket Layer (Wildcard SSL)', 'extra_price' => 23400.00],
                    ['label' => 'Secure Socket Layer (Standard SSL)', 'extra_price' => 10800.00],
                ],
            ],
            [
                'category' => 'Domains',
                'name' => 'Hybrid Top Level Domains',
                'price' => 3360.00,
                'billing_cycle' => 'yearly',
                'addons' => [
                    ['label' => 'WhoIs', 'extra_price' => 780.00],
                    ['label' => 'Secure Socket Layer (Wildcard SSL)', 'extra_price' => 23400.00],
                    ['label' => 'Secure Socket Layer (Standard SSL)', 'extra_price' => 10800.00],
                ],
            ],
            [
                'category' => 'Domains',
                'name' => 'Education Domains',
                'price' => 4420.00,
                'billing_cycle' => 'yearly',
                'addons' => [
                    ['label' => 'WhoIs', 'extra_price' => 780.00],
                    ['label' => 'Secure Socket Layer (Wildcard SSL)', 'extra_price' => 23400.00],
                    ['label' => 'Secure Socket Layer (Standard SSL)', 'extra_price' => 10800.00],
                ],
            ],
            [
                'category' => 'Domains',
                'name' => 'Government Domains (one-time registration)',
                'price' => 4320.00,
                'billing_cycle' => 'one_time',
                'addons' => [
                    ['label' => 'WhoIs', 'extra_price' => 780.00],
                    ['label' => 'Secure Socket Layer (Wildcard SSL)', 'extra_price' => 23400.00],
                    ['label' => 'Secure Socket Layer (Standard SSL)', 'extra_price' => 10800.00],
                ],
            ],
            [
                'category' => 'Shared Hosting',
                'name' => 'Starter',
                'price' => 4080.00,
                'billing_cycle' => 'monthly',
                'addons' => [
                    ['label' => 'Static IP', 'extra_price' => 3000.00],
                    ['label' => 'SiteLock', 'extra_price' => 14160.00],
                    ['label' => 'Codeguard', 'extra_price' => 6720.00],
                    ['label' => 'Magic Spam PRO', 'extra_price' => 23400.00],
                    ['label' => 'Imunify360', 'extra_price' => 23400.00],
                    ['label' => 'Additional 1 GB Storage', 'extra_price' => 3120.00],
                    ['label' => 'Additional 10 GB Data Cap', 'extra_price' => 3120.00],
                    ['label' => 'MS SQL Database for Windows', 'extra_price' => 3720.00],
                ],
            ],
            [
                'category' => 'Shared Hosting',
                'name' => 'Standard',
                'price' => 8520.00,
                'billing_cycle' => 'monthly',
                'addons' => [
                    ['label' => 'Static IP', 'extra_price' => 3000.00],
                    ['label' => 'SiteLock', 'extra_price' => 14160.00],
                    ['label' => 'Codeguard', 'extra_price' => 6720.00],
                    ['label' => 'Magic Spam PRO', 'extra_price' => 23400.00],
                    ['label' => 'Imunify360', 'extra_price' => 23400.00],
                    ['label' => 'Additional 1 GB Storage', 'extra_price' => 3120.00],
                    ['label' => 'Additional 10 GB Data Cap', 'extra_price' => 3120.00],
                    ['label' => 'MS SQL Database for Windows', 'extra_price' => 3720.00],
                ],
            ],
            [
                'category' => 'Shared Hosting',
                'name' => 'Deluxe',
                'price' => 15000.00,
                'billing_cycle' => 'monthly',
                'addons' => [
                    ['label' => 'Static IP', 'extra_price' => 3000.00],
                    ['label' => 'SiteLock', 'extra_price' => 14160.00],
                    ['label' => 'Codeguard', 'extra_price' => 6720.00],
                    ['label' => 'Magic Spam PRO', 'extra_price' => 23400.00],
                    ['label' => 'Imunify360', 'extra_price' => 23400.00],
                    ['label' => 'Additional 1 GB Storage', 'extra_price' => 3120.00],
                    ['label' => 'Additional 10 GB Data Cap', 'extra_price' => 3120.00],
                    ['label' => 'MS SQL Database for Windows', 'extra_price' => 3720.00],
                ],
            ],
            [
                'category' => 'Shared Hosting',
                'name' => 'Business',
                'price' => 24120.00,
                'billing_cycle' => 'monthly',
                'addons' => [
                    ['label' => 'Static IP', 'extra_price' => 3000.00],
                    ['label' => 'SiteLock', 'extra_price' => 14160.00],
                    ['label' => 'Codeguard', 'extra_price' => 6720.00],
                    ['label' => 'Magic Spam PRO', 'extra_price' => 23400.00],
                    ['label' => 'Imunify360', 'extra_price' => 23400.00],
                    ['label' => 'Additional 1 GB Storage', 'extra_price' => 3120.00],
                    ['label' => 'Additional 10 GB Data Cap', 'extra_price' => 3120.00],
                    ['label' => 'MS SQL Database for Windows', 'extra_price' => 3720.00],
                ],
            ],
            [
                'category' => 'Dedicated Server',
                'name' => 'Dedicated_Essential',
                'price' => 33600.00,
                'billing_cycle' => 'monthly',
                'addons' => [
                    ['label' => 'Daily Back-Up with Retention of 3 Back-ups up to 150 GB', 'extra_price' => 27360.00],
                    ['label' => 'Bare Metal Control Panel for Linux (cPanel)', 'extra_price' => 78000.00],
                    ['label' => 'Bare Metal Control Panel for Windows (Parallel Plesk)', 'extra_price' => 78000.00],
                    ['label' => 'Bare Metal Daily Back-Up with Retention of 3 Back-ups up to 1.5 TB', 'extra_price' => 42960.00],
                    ['label' => 'Bare Metal MS SQL 2012/2016 Web Edition', 'extra_price' => 81120.00],
                    ['label' => 'Bare Metal Gigabit LAN', 'extra_price' => 43680.00],
                ],
            ],
            [
                'category' => 'Dedicated Server',
                'name' => 'Dedicated_Business',
                'price' => 64800.00,
                'billing_cycle' => 'monthly',
                'addons' => [
                    ['label' => 'Daily Back-Up with Retention of 3 Back-ups up to 150 GB', 'extra_price' => 27360.00],
                    ['label' => 'Bare Metal Control Panel for Linux (cPanel)', 'extra_price' => 78000.00],
                    ['label' => 'Bare Metal Control Panel for Windows (Parallel Plesk)', 'extra_price' => 78000.00],
                    ['label' => 'Bare Metal Daily Back-Up with Retention of 3 Back-ups up to 1.5 TB', 'extra_price' => 42960.00],
                    ['label' => 'Bare Metal MS SQL 2012/2016 Web Edition', 'extra_price' => 81120.00],
                    ['label' => 'Bare Metal Gigabit LAN', 'extra_price' => 43680.00],
                ],
            ],
            [
                'category' => 'Dedicated Server',
                'name' => 'Dedicated_Premium',
                'price' => 100680.00,
                'billing_cycle' => 'monthly',
                'addons' => [
                    ['label' => 'Daily Back-Up with Retention of 3 Back-ups up to 150 GB', 'extra_price' => 27360.00],
                    ['label' => 'Bare Metal Control Panel for Linux (cPanel)', 'extra_price' => 78000.00],
                    ['label' => 'Bare Metal Control Panel for Windows (Parallel Plesk)', 'extra_price' => 78000.00],
                    ['label' => 'Bare Metal Daily Back-Up with Retention of 3 Back-ups up to 1.5 TB', 'extra_price' => 42960.00],
                    ['label' => 'Bare Metal MS SQL 2012/2016 Web Edition', 'extra_price' => 81120.00],
                    ['label' => 'Bare Metal Gigabit LAN', 'extra_price' => 43680.00],
                ],
            ],
            [
                'category' => 'Dedicated Server',
                'name' => 'Dedicated_Professional',
                'price' => 181800.00,
                'billing_cycle' => 'monthly',
                'addons' => [
                    ['label' => 'Daily Back-Up with Retention of 3 Back-ups up to 150 GB', 'extra_price' => 27360.00],
                    ['label' => 'Bare Metal Control Panel for Linux (cPanel)', 'extra_price' => 78000.00],
                    ['label' => 'Bare Metal Control Panel for Windows (Parallel Plesk)', 'extra_price' => 78000.00],
                    ['label' => 'Bare Metal Daily Back-Up with Retention of 3 Back-ups up to 1.5 TB', 'extra_price' => 42960.00],
                    ['label' => 'Bare Metal MS SQL 2012/2016 Web Edition', 'extra_price' => 81120.00],
                    ['label' => 'Bare Metal Gigabit LAN', 'extra_price' => 43680.00],
                ],
            ],
            [
                'category' => 'Dedicated Server',
                'name' => 'Dedicated_Corporate',
                'price' => 280200.00,
                'billing_cycle' => 'monthly',
                'addons' => [
                    ['label' => 'Daily Back-Up with Retention of 3 Back-ups up to 150 GB', 'extra_price' => 27360.00],
                    ['label' => 'Bare Metal Control Panel for Linux (cPanel)', 'extra_price' => 78000.00],
                    ['label' => 'Bare Metal Control Panel for Windows (Parallel Plesk)', 'extra_price' => 78000.00],
                    ['label' => 'Bare Metal Daily Back-Up with Retention of 3 Back-ups up to 1.5 TB', 'extra_price' => 42960.00],
                    ['label' => 'Bare Metal MS SQL 2012/2016 Web Edition', 'extra_price' => 81120.00],
                    ['label' => 'Bare Metal Gigabit LAN', 'extra_price' => 43680.00],
                ],
            ],
            [
                'category' => 'Dedicated Server',
                'name' => 'Dedicated_Enterprise',
                'price' => 529200.00,
                'billing_cycle' => 'monthly',
                'addons' => [
                    ['label' => 'Daily Back-Up with Retention of 3 Back-ups up to 150 GB', 'extra_price' => 27360.00],
                    ['label' => 'Bare Metal Control Panel for Linux (cPanel)', 'extra_price' => 78000.00],
                    ['label' => 'Bare Metal Control Panel for Windows (Parallel Plesk)', 'extra_price' => 78000.00],
                    ['label' => 'Bare Metal Daily Back-Up with Retention of 3 Back-ups up to 1.5 TB', 'extra_price' => 42960.00],
                    ['label' => 'Bare Metal MS SQL 2012/2016 Web Edition', 'extra_price' => 81120.00],
                    ['label' => 'Bare Metal Gigabit LAN', 'extra_price' => 43680.00],
                ],
            ],
            [
                'category' => 'Dedicated Server',
                'name' => 'Dedicated BareMetal_Linux',
                'price' => 200520.00,
                'billing_cycle' => 'monthly',
                'addons' => [
                    ['label' => 'Daily Back-Up with Retention of 3 Back-ups up to 150 GB', 'extra_price' => 27360.00],
                    ['label' => 'Bare Metal Control Panel for Linux (cPanel)', 'extra_price' => 78000.00],
                    ['label' => 'Bare Metal Daily Back-Up with Retention of 3 Back-ups up to 1.5 TB', 'extra_price' => 42960.00],
                    ['label' => 'Bare Metal Gigabit LAN', 'extra_price' => 43680.00],
                ],
            ],
            [
                'category' => 'Dedicated Server',
                'name' => 'Dedicated BareMetal_Windows',
                'price' => 224160.00,
                'billing_cycle' => 'monthly',
                'addons' => [
                    ['label' => 'Daily Back-Up with Retention of 3 Back-ups up to 150 GB', 'extra_price' => 27360.00],
                    ['label' => 'Bare Metal Control Panel for Windows (Parallel Plesk)', 'extra_price' => 78000.00],
                    ['label' => 'Bare Metal Daily Back-Up with Retention of 3 Back-ups up to 1.5 TB', 'extra_price' => 42960.00],
                    ['label' => 'Bare Metal MS SQL 2012/2016 Web Edition', 'extra_price' => 81120.00],
                    ['label' => 'Bare Metal Gigabit LAN', 'extra_price' => 43680.00],
                ],
            ],
        ])->map(fn (array $service) => [
            'slug' => Str::slug($service['category'].'-'.$service['name']),
            'category' => $service['category'],
            'name' => $service['name'],
            'description' => 'WSI managed service offering for '.$service['name'].'.',
            'price' => $service['price'],
            'billing_cycle' => $service['billing_cycle'],
            'configurations' => [$service['name']],
            'addons' => $service['addons'],
        ])->map(function (array $service) {
            $record = Service::create([
                'slug' => $service['slug'],
                'category' => $service['category'],
                'name' => $service['name'],
                'description' => $service['description'],
                'price' => $service['price'],
                'billing_cycle' => $service['billing_cycle'],
                'is_active' => true,
            ]);

            $record->configurations()->createMany(collect($service['configurations'])->map(fn ($label) => ['label' => $label])->all());
            $record->addons()->createMany(collect($service['addons'])->map(fn ($addon) => [
                'label' => $addon['label'],
                'extra_price' => $addon['extra_price'],
            ])->all());

            return $record;
        })->keyBy('slug');

        $sharedHostingOrder = PortalOrder::create([
            'order_number' => 'WSI-100201',
            'user_id' => $customer->id,
            'total_amount' => 4080.00,
            'payment_method' => 'Credit Card',
            'agreement_accepted' => true,
            'terms_accepted' => true,
            'privacy_accepted' => true,
            'status' => 'paid',
            'created_at' => now()->subDays(9),
            'updated_at' => now()->subDays(9),
        ]);

        $sharedHostingItem = $sharedHostingOrder->items()->create([
            'service_id' => $catalog['shared-hosting-starter']->id,
            'service_name' => 'Starter',
            'category' => 'Shared Hosting',
            'configuration' => 'Starter',
            'addon' => 'No add-on',
            'price' => 4080.00,
            'billing_cycle' => 'monthly',
            'provisioning_status' => 'active',
            'created_at' => now()->subDays(9),
            'updated_at' => now()->subDays(9),
        ]);

        $sharedHostingOrder->payments()->create([
            'amount' => 4080.00,
            'method' => 'Credit Card',
            'status' => 'success',
            'transaction_ref' => 'TX-100201',
            'created_at' => now()->subDays(9),
            'updated_at' => now()->subDays(9),
        ]);

        $sslOrder = PortalOrder::create([
            'order_number' => 'WSI-100199',
            'user_id' => $customer->id,
            'total_amount' => 64800.00,
            'payment_method' => 'PayPal',
            'agreement_accepted' => true,
            'terms_accepted' => true,
            'privacy_accepted' => true,
            'status' => 'pending_review',
            'created_at' => now()->subDays(16),
            'updated_at' => now()->subDays(16),
        ]);

        $sslItem = $sslOrder->items()->create([
            'service_id' => $catalog['dedicated-server-dedicated-business']->id,
            'service_name' => 'Dedicated_Business',
            'category' => 'Dedicated Server',
            'configuration' => 'Dedicated_Business',
            'addon' => 'Bare Metal Gigabit LAN',
            'price' => 64800.00,
            'billing_cycle' => 'monthly',
            'provisioning_status' => 'undergoing_provisioning',
            'created_at' => now()->subDays(16),
            'updated_at' => now()->subDays(16),
        ]);

        $sslOrder->payments()->create([
            'amount' => 64800.00,
            'method' => 'PayPal',
            'status' => 'pending',
            'transaction_ref' => 'TX-100199',
            'created_at' => now()->subDays(16),
            'updated_at' => now()->subDays(16),
        ]);

        $codeguardOrder = PortalOrder::create([
            'order_number' => 'WSI-100188',
            'user_id' => $customer->id,
            'total_amount' => 24120.00,
            'payment_method' => 'Bank Transfer',
            'agreement_accepted' => true,
            'terms_accepted' => true,
            'privacy_accepted' => true,
            'status' => 'failed',
            'created_at' => now()->subDays(2),
            'updated_at' => now()->subDays(2),
        ]);

        $codeguardItem = $codeguardOrder->items()->create([
            'service_id' => $catalog['shared-hosting-business']->id,
            'service_name' => 'Business',
            'category' => 'Shared Hosting',
            'configuration' => 'Business',
            'addon' => 'Codeguard',
            'price' => 24120.00,
            'billing_cycle' => 'monthly',
            'provisioning_status' => 'unpaid',
            'created_at' => now()->subDays(2),
            'updated_at' => now()->subDays(2),
        ]);

        $codeguardOrder->payments()->create([
            'amount' => 24120.00,
            'method' => 'Bank Transfer',
            'status' => 'failed',
            'transaction_ref' => 'TX-100188',
            'created_at' => now()->subDays(2),
            'updated_at' => now()->subDays(2),
        ]);

        $domainOrder = PortalOrder::create([
            'order_number' => 'WSI-100176',
            'user_id' => $customer->id,
            'total_amount' => 1440.00,
            'payment_method' => 'Credit Card',
            'agreement_accepted' => true,
            'terms_accepted' => true,
            'privacy_accepted' => true,
            'status' => 'paid',
            'created_at' => now()->subDays(28),
            'updated_at' => now()->subDays(28),
        ]);

        $domainItem = $domainOrder->items()->create([
            'service_id' => $catalog['domains-top-level-domains']->id,
            'service_name' => 'Top Level Domains',
            'category' => 'Domains',
            'configuration' => 'Top Level Domains',
            'addon' => 'No add-on',
            'price' => 1440.00,
            'billing_cycle' => 'yearly',
            'provisioning_status' => 'active',
            'created_at' => now()->subDays(28),
            'updated_at' => now()->subDays(28),
        ]);

        $domainOrder->payments()->create([
            'amount' => 1440.00,
            'method' => 'Credit Card',
            'status' => 'success',
            'transaction_ref' => 'TX-100176',
            'created_at' => now()->subDays(28),
            'updated_at' => now()->subDays(28),
        ]);

        $sitelockOrder = PortalOrder::create([
            'order_number' => 'WSI-100172',
            'user_id' => $customer->id,
            'total_amount' => 33600.00,
            'payment_method' => 'Credit Card',
            'agreement_accepted' => true,
            'terms_accepted' => true,
            'privacy_accepted' => true,
            'status' => 'paid',
            'created_at' => now()->subDays(21),
            'updated_at' => now()->subDays(21),
        ]);

        $sitelockItem = $sitelockOrder->items()->create([
            'service_id' => $catalog['dedicated-server-dedicated-essential']->id,
            'service_name' => 'Dedicated_Essential',
            'category' => 'Dedicated Server',
            'configuration' => 'Dedicated_Essential',
            'addon' => 'Daily Back-Up with Retention of 3 Back-ups up to 150 GB',
            'price' => 33600.00,
            'billing_cycle' => 'monthly',
            'provisioning_status' => 'active',
            'created_at' => now()->subDays(21),
            'updated_at' => now()->subDays(21),
        ]);

        $sitelockOrder->payments()->create([
            'amount' => 33600.00,
            'method' => 'Credit Card',
            'status' => 'success',
            'transaction_ref' => 'TX-100172',
            'created_at' => now()->subDays(21),
            'updated_at' => now()->subDays(21),
        ]);

        CustomerService::insert([
            [
                'user_id' => $customer->id,
                'service_id' => $catalog['shared-hosting-starter']->id,
                'order_item_id' => $sharedHostingItem->id,
                'name' => 'Starter',
                'category' => 'Shared Hosting',
                'plan' => 'Starter',
                'status' => 'active',
                'renews_on' => now()->addMonths(6),
                'created_at' => now()->subDays(9),
                'updated_at' => now()->subDays(9),
            ],
            [
                'user_id' => $customer->id,
                'service_id' => $catalog['dedicated-server-dedicated-business']->id,
                'order_item_id' => $sslItem->id,
                'name' => 'Dedicated_Business',
                'category' => 'Dedicated Server',
                'plan' => 'Dedicated_Business',
                'status' => 'undergoing_provisioning',
                'renews_on' => now()->addYear(),
                'created_at' => now()->subDays(16),
                'updated_at' => now()->subDays(16),
            ],
            [
                'user_id' => $customer->id,
                'service_id' => $catalog['shared-hosting-business']->id,
                'order_item_id' => $codeguardItem->id,
                'name' => 'Business',
                'category' => 'Shared Hosting',
                'plan' => 'Business',
                'status' => 'unpaid',
                'renews_on' => now()->addDays(8),
                'created_at' => now()->subDays(2),
                'updated_at' => now()->subDays(2),
            ],
            [
                'user_id' => $customer->id,
                'service_id' => $catalog['domains-top-level-domains']->id,
                'order_item_id' => $domainItem->id,
                'name' => 'Top Level Domains',
                'category' => 'Domains',
                'plan' => 'Top Level Domains',
                'status' => 'active',
                'renews_on' => now()->addMonths(10),
                'created_at' => now()->subDays(28),
                'updated_at' => now()->subDays(28),
            ],
            [
                'user_id' => $customer->id,
                'service_id' => $catalog['dedicated-server-dedicated-essential']->id,
                'order_item_id' => $sitelockItem->id,
                'name' => 'Dedicated_Essential',
                'category' => 'Dedicated Server',
                'plan' => 'Dedicated_Essential',
                'status' => 'active',
                'renews_on' => now()->addMonths(1),
                'created_at' => now()->subDays(21),
                'updated_at' => now()->subDays(21),
            ],
        ]);

        PortalNotification::insert([
            [
                'user_id' => $admin->id,
                'title' => 'Provisioning queue requires review',
                'message' => 'Dedicated_Business for Jamal Saberola is still undergoing provisioning and needs admin follow-up.',
                'type' => 'warning',
                'is_read' => false,
                'created_at' => now()->subHours(3),
                'updated_at' => now()->subHours(3),
            ],
            [
                'user_id' => $admin->id,
                'title' => 'New unpaid service detected',
                'message' => 'Business shared hosting has a failed payment and should be reviewed by the billing team.',
                'type' => 'danger',
                'is_read' => false,
                'created_at' => now()->subHours(7),
                'updated_at' => now()->subHours(7),
            ],
            [
                'user_id' => $admin->id,
                'title' => 'Customer account activated',
                'message' => 'WSI Demo Client now has multiple active phase 1 services available in the portal.',
                'type' => 'success',
                'is_read' => true,
                'created_at' => now()->subDays(1),
                'updated_at' => now()->subDays(1),
            ],
            [
                'user_id' => $customer->id,
                'title' => 'Domain connected successfully',
                'message' => 'Top Level Domains is active and ready for use.',
                'type' => 'success',
                'is_read' => false,
                'created_at' => now()->subHours(6),
                'updated_at' => now()->subHours(6),
            ],
            [
                'user_id' => $customer->id,
                'title' => 'Provisioning in progress',
                'message' => 'Your Dedicated_Business service is being provisioned by the admin team.',
                'type' => 'info',
                'is_read' => false,
                'created_at' => now()->subDay(),
                'updated_at' => now()->subDay(),
            ],
            [
                'user_id' => $customer->id,
                'title' => 'Security scan completed',
                'message' => 'Dedicated_Essential completed its latest health check with no issues detected.',
                'type' => 'success',
                'is_read' => true,
                'created_at' => now()->subDays(1),
                'updated_at' => now()->subDays(1),
            ],
            [
                'user_id' => $customer->id,
                'title' => 'Invoice reminder',
                'message' => 'Business shared hosting has an unpaid balance due in 3 days.',
                'type' => 'warning',
                'is_read' => false,
                'created_at' => now()->subDays(2),
                'updated_at' => now()->subDays(2),
            ],
            [
                'user_id' => $customer->id,
                'title' => 'Hosting service renewed',
                'message' => 'Starter shared hosting remains active for the next billing period.',
                'type' => 'info',
                'is_read' => true,
                'created_at' => now()->subDays(4),
                'updated_at' => now()->subDays(4),
            ],
        ]);
    }
}
