<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Service;
use App\Models\CustomerService;

class NearExpireCustomerServicesSeeder extends Seeder
{
    public function run(): void
    {
        $customer = User::where('email', 'customer@wsiportal.com')->first();
        if (! $customer) {
            return;
        }

        $starter = Service::where('name', 'Starter')->first();
        $topDomain = Service::where('name', 'Top Level Domains')->first();

        if ($starter) {
            CustomerService::create([
                'user_id' => $customer->id,
                'service_id' => $starter->id,
                'order_item_id' => null,
                'name' => $starter->name,
                'category' => $starter->category ?? 'Shared Hosting',
                'plan' => $starter->name,
                'status' => 'active',
                'renews_on' => now()->addDays(6),
            ]);
        }

        if ($topDomain) {
            CustomerService::create([
                'user_id' => $customer->id,
                'service_id' => $topDomain->id,
                'order_item_id' => null,
                'name' => $topDomain->name,
                'category' => $topDomain->category ?? 'Domains',
                'plan' => $topDomain->name,
                'status' => 'active',
                'renews_on' => now()->addDays(3),
            ]);
        }
    }
}
