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

    public function test_admin_purchases_include_flattened_billing_owner_and_stage_fields(): void
    {
        $reviewer = User::factory()->create([
            'name' => 'Michelle Durian',
            'role' => 'admin',
            'is_enabled' => true,
        ]);

        $customerWithOwner = User::factory()->create([
            'name' => 'Maria Santos',
            'email' => 'maria@example.com',
            'role' => 'customer',
            'is_enabled' => true,
            'registration_status' => 'approved',
            'registration_reviewed_by' => $reviewer->id,
        ]);

        $customerWithoutOwner = User::factory()->create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'role' => 'customer',
            'is_enabled' => true,
            'registration_status' => 'approved',
        ]);

        $admin = User::factory()->create([
            'role' => 'admin',
            'is_enabled' => true,
        ]);

        $service = Service::query()->create([
            'slug' => 'managed-hosting',
            'category' => 'Hosting',
            'name' => 'Managed Hosting',
            'description' => 'Managed hosting package.',
            'price' => 4999,
            'billing_cycle' => 'monthly',
            'is_active' => true,
        ]);

        $closedWonOrder = PortalOrder::query()->create([
            'order_number' => 'WSI-100001',
            'user_id' => $customerWithOwner->id,
            'total_amount' => 4999,
            'payment_method' => 'bank_transfer',
            'status' => 'paid',
        ]);

        $closedWonOrder->items()->create([
            'service_id' => $service->id,
            'service_name' => $service->name,
            'category' => $service->category,
            'configuration' => 'Starter',
            'addon' => null,
            'price' => 4999,
            'billing_cycle' => 'monthly',
            'provisioning_status' => 'undergoing_provisioning',
        ]);

        $pendingReviewOrder = PortalOrder::query()->create([
            'order_number' => 'WSI-100002',
            'user_id' => $customerWithOwner->id,
            'total_amount' => 5999,
            'payment_method' => 'bank_transfer',
            'status' => 'pending_review',
        ]);

        $pendingReviewOrder->items()->create([
            'service_id' => $service->id,
            'service_name' => $service->name,
            'category' => $service->category,
            'configuration' => 'Growth',
            'addon' => null,
            'price' => 5999,
            'billing_cycle' => 'monthly',
            'provisioning_status' => 'pending_review',
        ]);

        $closedLostOrder = PortalOrder::query()->create([
            'order_number' => 'WSI-100003',
            'user_id' => $customerWithoutOwner->id,
            'total_amount' => 6999,
            'payment_method' => 'bank_transfer',
            'status' => 'failed',
        ]);

        $closedLostOrder->items()->create([
            'service_id' => $service->id,
            'service_name' => $service->name,
            'category' => $service->category,
            'configuration' => 'Scale',
            'addon' => null,
            'price' => 6999,
            'billing_cycle' => 'monthly',
            'provisioning_status' => 'unpaid',
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/admin/purchases');

        $response
            ->assertOk()
            ->assertJsonFragment([
                'id' => $closedWonOrder->order_number,
                'billing_in_charge' => 'Maria Santos',
                'deal_owner' => 'Michelle Durian',
                'stage' => 'Closed Won',
            ])
            ->assertJsonFragment([
                'id' => $pendingReviewOrder->order_number,
                'billing_in_charge' => 'Maria Santos',
                'deal_owner' => 'Michelle Durian',
                'stage' => 'Pending Review',
            ])
            ->assertJsonFragment([
                'id' => $closedLostOrder->order_number,
                'billing_in_charge' => 'John Doe',
                'deal_owner' => null,
                'stage' => 'Closed Lost',
            ]);
    }
}
