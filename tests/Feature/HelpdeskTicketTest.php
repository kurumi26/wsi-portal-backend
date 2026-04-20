<?php

namespace Tests\Feature;

use App\Models\CustomerService;
use App\Models\HelpdeskTicket;
use App\Models\PortalNotification;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class HelpdeskTicketTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_report_issue_creates_helpdesk_ticket_and_notifies_support_users(): void
    {
        $customer = $this->customerUser('customer@example.com');
        $admin = $this->internalUser('admin', 'admin@example.com');
        $support = $this->internalUser('technical_support', 'support@example.com');
        $customerService = $this->customerServiceFor($customer, 'Business Hosting');

        Sanctum::actingAs($customer);

        $response = $this->postJson('/api/customer-services/'.$customerService->id.'/report-issue', [
            'message' => 'Website not loading',
        ]);

        $ticket = HelpdeskTicket::query()->firstOrFail();

        $response
            ->assertCreated()
            ->assertJsonPath('ticket.id', $ticket->id)
            ->assertJsonPath('ticket.reference', 'T-000001')
            ->assertJsonPath('ticket.status', 'Open')
            ->assertJsonPath('ticket.serviceId', $customerService->id)
            ->assertJsonPath('ticket.serviceName', 'Business Hosting')
            ->assertJsonPath('ticket.clientId', $customer->id)
            ->assertJsonPath('ticket.clientEmail', $customer->email)
            ->assertJsonPath('ticket.assignedTo', null);

        $this->assertDatabaseHas('helpdesk_tickets', [
            'id' => $ticket->id,
            'service_id' => $customerService->id,
            'customer_id' => $customer->id,
            'title' => 'Issue with Business Hosting',
            'message' => 'Website not loading',
            'category' => 'Technical',
            'status' => 'Open',
            'priority' => 'Normal',
            'source' => 'customer_portal',
            'reference_number' => 'T-000001',
        ]);

        $this->assertDatabaseHas('helpdesk_ticket_activities', [
            'ticket_id' => $ticket->id,
            'actor_user_id' => $customer->id,
            'action' => 'ticket_created',
        ]);

        $this->assertDatabaseHas('portal_notifications', [
            'user_id' => $customer->id,
            'title' => 'Support ticket created',
        ]);

        $this->assertDatabaseHas('portal_notifications', [
            'user_id' => $admin->id,
            'title' => 'New helpdesk ticket',
        ]);

        $this->assertDatabaseHas('portal_notifications', [
            'user_id' => $support->id,
            'title' => 'New helpdesk ticket',
        ]);
    }

    public function test_customer_cannot_report_issue_for_someone_elses_service(): void
    {
        $customer = $this->customerUser('customer@example.com');
        $otherCustomer = $this->customerUser('other@example.com');
        $customerService = $this->customerServiceFor($otherCustomer, 'Business Hosting');

        Sanctum::actingAs($customer);

        $this->postJson('/api/customer-services/'.$customerService->id.'/report-issue', [
            'message' => 'Unauthorized issue report',
        ])->assertForbidden();

        $this->assertDatabaseCount('helpdesk_tickets', 0);
    }

    public function test_admin_helpdesk_list_supports_filters_and_returns_related_fields(): void
    {
        $admin = $this->internalUser('admin', 'admin@example.com');
        $support = $this->internalUser('technical_support', 'support@example.com');
        $customer = $this->customerUser('customer@example.com');
        $customerService = $this->customerServiceFor($customer, 'Business Hosting');

        $matchingTicket = $this->createTicket($customerService, [
            'reference_number' => 'T-000101',
            'title' => 'Website not loading',
            'category' => 'Technical',
            'status' => 'Open',
            'assigned_to_user_id' => $support->id,
        ]);

        $this->createTicket($customerService, [
            'reference_number' => 'T-000102',
            'title' => 'Billing concern',
            'category' => 'Billing',
            'status' => 'Closed',
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/admin/helpdesk/tickets?status=Open&assigned_to_user_id='.$support->id.'&category=Technical&customer_id='.$customer->id.'&service_id='.$customerService->id.'&search=T-000101');

        $response
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $matchingTicket->id)
            ->assertJsonPath('0.reference', 'T-000101')
            ->assertJsonPath('0.serviceName', 'Business Hosting')
            ->assertJsonPath('0.clientName', $customer->name)
            ->assertJsonPath('0.clientEmail', $customer->email)
            ->assertJsonPath('0.assignedTo.id', $support->id)
            ->assertJsonPath('0.assignedTo.name', $support->name);
    }

    public function test_admin_can_assign_update_status_and_add_internal_note_with_persistence(): void
    {
        $admin = $this->internalUser('admin', 'admin@example.com');
        $support = $this->internalUser('technical_support', 'support@example.com');
        $customer = $this->customerUser('customer@example.com');
        $customerService = $this->customerServiceFor($customer, 'Business Hosting');
        $ticket = $this->createTicket($customerService, [
            'reference_number' => 'T-000150',
            'status' => 'Open',
        ]);

        Sanctum::actingAs($admin);

        $updateResponse = $this->patchJson('/api/admin/helpdesk/tickets/'.$ticket->id, [
            'assigned_to_user_id' => $support->id,
            'status' => 'Resolved',
            'priority' => 'High',
            'internal_note' => 'Investigating server-side timeout issue.',
        ]);

        $updateResponse
            ->assertOk()
            ->assertJsonPath('ticket.assignedTo.id', $support->id)
            ->assertJsonPath('ticket.status', 'Resolved')
            ->assertJsonPath('ticket.priority', 'High');

        $ticket->refresh();

        $this->assertSame($support->id, $ticket->assigned_to_user_id);
        $this->assertSame('Resolved', $ticket->status);
        $this->assertSame('High', $ticket->priority);
        $this->assertNotNull($ticket->resolved_at);
        $this->assertNull($ticket->closed_at);

        $this->assertDatabaseHas('helpdesk_ticket_activities', [
            'ticket_id' => $ticket->id,
            'actor_user_id' => $admin->id,
            'action' => 'assignment_changed',
        ]);

        $this->assertDatabaseHas('helpdesk_ticket_activities', [
            'ticket_id' => $ticket->id,
            'actor_user_id' => $admin->id,
            'action' => 'status_changed',
        ]);

        $this->assertDatabaseHas('helpdesk_ticket_activities', [
            'ticket_id' => $ticket->id,
            'actor_user_id' => $admin->id,
            'action' => 'priority_changed',
        ]);

        $this->assertDatabaseHas('helpdesk_ticket_activities', [
            'ticket_id' => $ticket->id,
            'actor_user_id' => $admin->id,
            'action' => 'note_added',
            'note' => 'Investigating server-side timeout issue.',
        ]);

        $this->assertDatabaseHas('portal_notifications', [
            'user_id' => $support->id,
            'title' => 'Helpdesk ticket assigned',
        ]);

        $this->assertDatabaseHas('portal_notifications', [
            'user_id' => $customer->id,
            'title' => 'Support ticket updated',
        ]);

        $resetResponse = $this->patchJson('/api/admin/helpdesk/tickets/'.$ticket->id, [
            'status' => 'In Progress',
        ]);

        $resetResponse
            ->assertOk()
            ->assertJsonPath('ticket.status', 'In Progress');

        $ticket->refresh();

        $this->assertNull($ticket->resolved_at);
        $this->assertNull($ticket->closed_at);
    }

    public function test_admin_helpdesk_detail_returns_activity_history(): void
    {
        $admin = $this->internalUser('admin', 'admin@example.com');
        $support = $this->internalUser('technical_support', 'support@example.com');
        $customer = $this->customerUser('customer@example.com');
        $customerService = $this->customerServiceFor($customer, 'Business Hosting');
        $ticket = $this->createTicket($customerService, [
            'reference_number' => 'T-000200',
            'assigned_to_user_id' => $support->id,
        ]);

        $ticket->activities()->create([
            'actor_user_id' => $admin->id,
            'action' => 'note_added',
            'note' => 'Waiting for monitoring confirmation.',
            'created_at' => now(),
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/admin/helpdesk/tickets/'.$ticket->id);

        $response
            ->assertOk()
            ->assertJsonPath('reference', 'T-000200')
            ->assertJsonPath('assignedTo.id', $support->id)
            ->assertJsonPath('activities.0.action', 'note_added')
            ->assertJsonPath('activities.0.note', 'Waiting for monitoring confirmation.')
            ->assertJsonPath('activities.0.actor.id', $admin->id);
    }

    public function test_customer_ticket_tracker_returns_only_own_tickets(): void
    {
        $customer = $this->customerUser('customer@example.com');
        $otherCustomer = $this->customerUser('other@example.com');
        $customerService = $this->customerServiceFor($customer, 'Business Hosting');
        $otherCustomerService = $this->customerServiceFor($otherCustomer, 'Dedicated Server');

        $ownTicket = $this->createTicket($customerService, [
            'reference_number' => 'T-000301',
            'title' => 'Website not loading',
        ]);

        $this->createTicket($otherCustomerService, [
            'reference_number' => 'T-000302',
            'title' => 'Other customer issue',
        ]);

        Sanctum::actingAs($customer);

        $response = $this->getJson('/api/helpdesk/tickets/me');

        $response
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $ownTicket->id)
            ->assertJsonPath('0.reference', 'T-000301')
            ->assertJsonPath('0.title', 'Website not loading')
            ->assertJsonPath('0.serviceName', 'Business Hosting');
    }

    public function test_admin_update_rejects_non_assignable_agent_and_invalid_status(): void
    {
        $admin = $this->internalUser('admin', 'admin@example.com');
        $sales = $this->internalUser('sales', 'sales@example.com');
        $customer = $this->customerUser('customer@example.com');
        $customerService = $this->customerServiceFor($customer, 'Business Hosting');
        $ticket = $this->createTicket($customerService, [
            'reference_number' => 'T-000401',
        ]);

        Sanctum::actingAs($admin);

        $response = $this->patchJson('/api/admin/helpdesk/tickets/'.$ticket->id, [
            'assigned_to_user_id' => $sales->id,
            'status' => 'Pending',
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors(['assigned_to_user_id', 'status']);
    }

    private function customerUser(string $email): User
    {
        return User::factory()->create([
            'name' => 'Customer '.strtoupper(substr($email, 0, 1)),
            'email' => $email,
            'role' => 'customer',
            'is_enabled' => true,
            'registration_status' => 'approved',
        ]);
    }

    private function internalUser(string $role, string $email): User
    {
        return User::factory()->create([
            'name' => ucfirst(str_replace('_', ' ', $role)),
            'email' => $email,
            'role' => $role,
            'is_enabled' => true,
        ]);
    }

    private function customerServiceFor(User $customer, string $name): CustomerService
    {
        $catalogService = Service::query()->create([
            'slug' => strtolower(str_replace(' ', '-', $name)).'-'.strtolower(str_replace(['@', '.'], '-', $customer->email)),
            'category' => 'Hosting',
            'name' => $name,
            'description' => 'Managed service '.$name,
            'price' => 24000,
            'billing_cycle' => 'monthly',
            'is_active' => true,
        ]);

        return CustomerService::query()->create([
            'user_id' => $customer->id,
            'service_id' => $catalogService->id,
            'order_item_id' => null,
            'name' => $name,
            'category' => 'Hosting',
            'plan' => $name,
            'status' => 'active',
            'renews_on' => now()->addMonth(),
        ]);
    }

    private function createTicket(CustomerService $customerService, array $overrides = []): HelpdeskTicket
    {
        $ticket = new HelpdeskTicket([
            'service_id' => $customerService->id,
            'customer_id' => $customerService->user_id,
            'title' => $overrides['title'] ?? 'Website not loading',
            'message' => $overrides['message'] ?? 'Customer reports the website is down.',
            'category' => $overrides['category'] ?? 'Technical',
            'status' => $overrides['status'] ?? 'Open',
            'assigned_to_user_id' => $overrides['assigned_to_user_id'] ?? null,
            'priority' => $overrides['priority'] ?? 'Normal',
            'source' => $overrides['source'] ?? 'customer_portal',
            'reference_number' => $overrides['reference_number'] ?? 'T-000999',
            'resolved_at' => $overrides['resolved_at'] ?? null,
            'closed_at' => $overrides['closed_at'] ?? null,
        ]);

        $ticket->save();

        $ticket->activities()->create([
            'actor_user_id' => $customerService->user_id,
            'action' => 'ticket_created',
            'new_value' => ['status' => $ticket->status],
            'created_at' => now(),
        ]);

        return $ticket->fresh();
    }
}
