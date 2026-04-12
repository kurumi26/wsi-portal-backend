<?php

namespace Tests\Feature;

use App\Models\OrderItem;
use App\Models\PortalOrder;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PortalOrderNotesTest extends TestCase
{
    use RefreshDatabase;

    public function test_checkout_persists_note_and_admin_purchases_include_it(): void
    {
        $customer = User::factory()->create([
            'role' => 'customer',
            'is_enabled' => true,
            'registration_status' => 'approved',
        ]);

        $admin = User::factory()->create([
            'role' => 'admin',
            'is_enabled' => true,
        ]);

        $service = Service::query()->create([
            'slug' => 'domain-registration',
            'category' => 'Domains',
            'name' => 'Domain Registration',
            'description' => 'Register a new domain.',
            'price' => 1299,
            'billing_cycle' => 'yearly',
            'is_active' => true,
        ]);

        Sanctum::actingAs($customer);

        $checkoutResponse = $this->postJson('/api/orders/checkout', [
            'cart' => [[
                'serviceId' => $service->id,
                'serviceName' => $service->name,
                'category' => $service->category,
                'configuration' => 'New registration',
                'addon' => null,
                'price' => 1299,
            ]],
            'paymentMethod' => 'bank_transfer',
            'note' => 'Desired domain: example.com',
            'agreementAccepted' => true,
        ]);

        $checkoutResponse
            ->assertCreated()
            ->assertJsonPath('orders.0.note', 'Desired domain: example.com');

        $order = PortalOrder::query()->firstOrFail();

        $this->assertDatabaseHas('order_items', [
            'portal_order_id' => $order->id,
            'customer_note' => 'Desired domain: example.com',
        ]);

        $order->update(['customer_note' => null]);

        Sanctum::actingAs($admin);

        $purchasesResponse = $this->getJson('/api/admin/purchases');

        $orderItem = OrderItem::query()->firstOrFail();

        $purchasesResponse
            ->assertOk()
            ->assertJsonPath('0.id', $order->order_number)
            ->assertJsonPath('0.serviceName', $orderItem->service_name)
            ->assertJsonPath('0.note', 'Desired domain: example.com');
    }
}
