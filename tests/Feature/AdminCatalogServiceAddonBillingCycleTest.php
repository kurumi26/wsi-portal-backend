<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminCatalogServiceAddonBillingCycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_catalog_service_with_addon_billing_cycles(): void
    {
        Sanctum::actingAs($this->adminUser());

        $response = $this->postJson('/api/admin/catalog-services', [
            'name' => 'Business Hosting',
            'description' => 'Managed hosting for business sites',
            'category' => 'Shared Hosting',
            'price' => 24120,
            'billingCycle' => 'monthly',
            'addons' => [
                [
                    'label' => 'Whois Privacy',
                    'price' => 780,
                    'billingCycle' => 'yearly',
                ],
                [
                    'label' => 'Static IP',
                    'price' => 3000,
                    'billing_cycle' => 'per_month',
                ],
            ],
        ]);

        $service = Service::query()->where('name', 'Business Hosting')->firstOrFail();

        $response
            ->assertCreated()
            ->assertJsonPath('service.id', (string) $service->id)
            ->assertJsonPath('service.addons.0.billingCycle', 'yearly')
            ->assertJsonPath('service.addons.1.billingCycle', 'monthly');

        $this->assertDatabaseHas('service_addons', [
            'service_id' => $service->id,
            'label' => 'Whois Privacy',
            'extra_price' => 780,
            'billing_cycle' => 'yearly',
        ]);

        $this->assertDatabaseHas('service_addons', [
            'service_id' => $service->id,
            'label' => 'Static IP',
            'extra_price' => 3000,
            'billing_cycle' => 'monthly',
        ]);
    }

    public function test_admin_can_update_catalog_service_and_change_addon_billing_cycle(): void
    {
        Sanctum::actingAs($this->adminUser());

        $service = Service::query()->create([
            'slug' => 'business-hosting',
            'category' => 'Shared Hosting',
            'name' => 'Business Hosting',
            'description' => 'Managed hosting for business sites',
            'price' => 24120,
            'billing_cycle' => 'monthly',
            'is_active' => true,
        ]);

        $service->configurations()->create(['label' => 'Business Hosting']);
        $service->addons()->create([
            'label' => 'Static IP',
            'extra_price' => 3000,
            'billing_cycle' => 'monthly',
        ]);

        $response = $this->patchJson('/api/admin/catalog-services/'.$service->id, [
            'name' => 'Business Hosting',
            'description' => 'Managed hosting for growing business sites',
            'category' => 'Shared Hosting',
            'price' => 26120,
            'billingCycle' => 'monthly',
            'addons' => [
                [
                    'label' => 'Static IP',
                    'price' => 3000,
                    'billingCycle' => 'one-time',
                ],
            ],
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('service.addons.0.billingCycle', 'one_time')
            ->assertJsonPath('service.addons.0.price', 3000.0);

        $this->assertDatabaseHas('service_addons', [
            'service_id' => $service->id,
            'label' => 'Static IP',
            'billing_cycle' => 'one_time',
        ]);
    }

    public function test_missing_addon_billing_cycle_defaults_to_parent_service_billing_cycle(): void
    {
        Sanctum::actingAs($this->adminUser());

        $response = $this->postJson('/api/admin/catalog-services', [
            'name' => 'Business Hosting',
            'description' => 'Managed hosting for business sites',
            'category' => 'Shared Hosting',
            'price' => 24120,
            'billing_cycle' => 'annual',
            'addons' => [
                [
                    'label' => 'Whois Privacy',
                    'price' => 780,
                ],
            ],
        ]);

        $service = Service::query()->where('name', 'Business Hosting')->firstOrFail();

        $response
            ->assertCreated()
            ->assertJsonPath('service.addons.0.billingCycle', 'yearly');

        $this->assertDatabaseHas('services', [
            'id' => $service->id,
            'billing_cycle' => 'yearly',
        ]);

        $this->assertDatabaseHas('service_addons', [
            'service_id' => $service->id,
            'label' => 'Whois Privacy',
            'billing_cycle' => 'yearly',
        ]);
    }

    public function test_invalid_addon_billing_cycle_is_rejected(): void
    {
        Sanctum::actingAs($this->adminUser());

        $response = $this->postJson('/api/admin/catalog-services', [
            'name' => 'Business Hosting',
            'description' => 'Managed hosting for business sites',
            'category' => 'Shared Hosting',
            'price' => 24120,
            'billingCycle' => 'monthly',
            'addons' => [
                [
                    'label' => 'Whois Privacy',
                    'price' => 780,
                    'billingCycle' => 'weekly',
                ],
            ],
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors(['addons.0.billingCycle']);
    }

    public function test_catalog_get_endpoint_returns_addons_with_billing_cycle_and_legacy_fallback(): void
    {
        $service = Service::query()->create([
            'slug' => 'business-hosting',
            'category' => 'Shared Hosting',
            'name' => 'Business Hosting',
            'description' => 'Managed hosting for business sites',
            'price' => 24120,
            'billing_cycle' => 'monthly',
            'is_active' => true,
        ]);

        $service->configurations()->create(['label' => 'Business Hosting']);
        $service->addons()->create([
            'label' => 'Legacy Add-on',
            'extra_price' => 780,
        ]);
        $service->addons()->create([
            'label' => 'Static IP',
            'extra_price' => 3000,
            'billing_cycle' => 'yearly',
        ]);

        $response = $this->getJson('/api/services');

        $response
            ->assertOk()
            ->assertJsonFragment([
                'label' => 'Legacy Add-on',
                'price' => 780.0,
                'billingCycle' => 'monthly',
            ])
            ->assertJsonFragment([
                'label' => 'Static IP',
                'price' => 3000.0,
                'billingCycle' => 'yearly',
            ]);
    }

    private function adminUser(): User
    {
        return User::factory()->create([
            'role' => 'admin',
            'is_enabled' => true,
        ]);
    }
}
