<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\ContractAuditLog;
use App\Models\CustomerService;
use App\Models\PortalOrder;
use App\Models\Service;
use App\Models\User;
use App\Support\PortalFormatter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ContractsTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_list_own_order_and_service_contracts(): void
    {
        $customer = $this->customerUser('contracts@example.com');
        $otherCustomer = $this->customerUser('other-contracts@example.com');
        $service = $this->catalogService('Business Hosting');

        $order = $this->orderFor($customer, $service, 'WSI-200001');
        $customerService = $this->customerServiceFor($customer, $service, 'Business Hosting');

        $orderContract = $this->createContract($customer, [
            'order_id' => $order->id,
            'external_key' => 'order-'.$order->order_number,
            'scope' => Contract::SCOPE_ORDER,
            'title' => 'Business Hosting Agreement',
            'service_name' => 'Business Hosting',
            'status' => Contract::STATUS_ACCEPTED,
            'agreement_accepted' => true,
            'terms_accepted' => true,
            'privacy_accepted' => true,
            'audit_reference' => 'ORDER-'.$order->order_number,
            'decision_by' => 'Customer',
            'decision_at' => now(),
            'accepted_at' => now(),
        ]);

        $serviceContract = $this->createContract($customer, [
            'customer_service_id' => $customerService->id,
            'external_key' => 'service-'.$customerService->id,
            'scope' => Contract::SCOPE_SERVICE,
            'title' => 'Business Hosting Renewal Agreement',
            'service_name' => 'Business Hosting',
            'status' => Contract::STATUS_PENDING_REVIEW,
            'audit_reference' => 'SERVICE-'.$customerService->id,
        ]);

        $otherOrder = $this->orderFor($otherCustomer, $service, 'WSI-200002');
        $this->createContract($otherCustomer, [
            'order_id' => $otherOrder->id,
            'external_key' => 'order-'.$otherOrder->order_number,
            'scope' => Contract::SCOPE_ORDER,
            'title' => 'Other Customer Agreement',
            'service_name' => 'Business Hosting',
            'audit_reference' => 'ORDER-'.$otherOrder->order_number,
        ]);

        Sanctum::actingAs($customer);

        $response = $this->getJson('/api/contracts/me');

        $response
            ->assertOk()
            ->assertJsonCount(2, 'contracts')
            ->assertJsonFragment([
                'id' => $orderContract->external_key,
                'externalKey' => $orderContract->external_key,
                'scope' => 'order',
                'orderNumber' => $order->order_number,
                'serviceName' => 'Business Hosting',
            ])
            ->assertJsonFragment([
                'id' => $serviceContract->external_key,
                'externalKey' => $serviceContract->external_key,
                'scope' => 'service',
                'serviceName' => 'Business Hosting',
            ])
            ->assertJsonMissing([
                'orderNumber' => $otherOrder->order_number,
            ]);
    }

    public function test_customer_cannot_change_another_customers_contract(): void
    {
        $customer = $this->customerUser('contracts@example.com');
        $otherCustomer = $this->customerUser('other-contracts@example.com');
        $service = $this->catalogService('Business Hosting');
        $otherOrder = $this->orderFor($otherCustomer, $service, 'WSI-210001');
        $contract = $this->createContract($otherCustomer, [
            'order_id' => $otherOrder->id,
            'external_key' => 'order-'.$otherOrder->order_number,
            'scope' => Contract::SCOPE_ORDER,
            'title' => 'Business Hosting Agreement',
            'service_name' => 'Business Hosting',
        ]);

        Sanctum::actingAs($customer);

        $this->patchJson('/api/contracts/'.$contract->external_key.'/decision', [
            'decision' => 'accept',
            'agreementAccepted' => true,
            'termsAccepted' => true,
            'privacyAccepted' => true,
            'source' => 'customer-portal',
        ])->assertForbidden();
    }

    public function test_customer_can_accept_contract_and_audit_is_recorded(): void
    {
        $customer = $this->customerUser('accept@example.com');
        $service = $this->catalogService('Business Hosting');
        $order = $this->orderFor($customer, $service, 'WSI-220001');
        $contract = $this->createContract($customer, [
            'order_id' => $order->id,
            'external_key' => 'order-'.$order->order_number,
            'scope' => Contract::SCOPE_ORDER,
            'title' => 'Business Hosting Agreement',
            'service_name' => 'Business Hosting',
            'status' => Contract::STATUS_PENDING_REVIEW,
        ]);

        Sanctum::actingAs($customer);

        $response = $this->patchJson('/api/contracts/'.$contract->external_key.'/decision', [
            'decision' => 'accept',
            'status' => 'Accepted',
            'agreementAccepted' => true,
            'termsAccepted' => true,
            'privacyAccepted' => true,
            'decisionAt' => now()->toISOString(),
            'decisionBy' => $customer->name,
            'source' => 'customer-portal',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('message', 'Agreement accepted successfully.')
            ->assertJsonPath('contract.id', $contract->external_key)
            ->assertJsonPath('contract.status', 'Accepted')
            ->assertJsonPath('contract.decisionBy', $customer->name)
            ->assertJsonPath('contract.requiresSignedDocument', true);

        $contract->refresh();

        $this->assertSame(Contract::STATUS_ACCEPTED, $contract->status);
        $this->assertTrue($contract->agreement_accepted);
        $this->assertTrue($contract->terms_accepted);
        $this->assertTrue($contract->privacy_accepted);
        $this->assertNotNull($contract->accepted_at);
        $this->assertNull($contract->rejected_at);
        $this->assertNotNull($contract->decision_at);

        $this->assertDatabaseHas('contract_audit_logs', [
            'contract_id' => $contract->id,
            'user_id' => $customer->id,
            'action' => ContractAuditLog::ACTION_ACCEPTED,
            'old_status' => Contract::STATUS_PENDING_REVIEW,
            'new_status' => Contract::STATUS_ACCEPTED,
        ]);
    }

    public function test_customer_can_reject_contract_and_audit_is_recorded(): void
    {
        $customer = $this->customerUser('reject@example.com');
        $service = $this->catalogService('Business Hosting');
        $order = $this->orderFor($customer, $service, 'WSI-230001');
        $contract = $this->createContract($customer, [
            'order_id' => $order->id,
            'external_key' => 'order-'.$order->order_number,
            'scope' => Contract::SCOPE_ORDER,
            'title' => 'Business Hosting Agreement',
            'service_name' => 'Business Hosting',
            'status' => Contract::STATUS_PENDING_REVIEW,
        ]);

        Sanctum::actingAs($customer);

        $response = $this->patchJson('/api/contracts/'.$contract->external_key.'/decision', [
            'decision' => 'reject',
            'status' => 'Rejected',
            'agreementAccepted' => false,
            'termsAccepted' => false,
            'privacyAccepted' => false,
            'decisionAt' => now()->toISOString(),
            'decisionBy' => $customer->name,
            'source' => 'customer-portal',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('message', 'Agreement rejected successfully.')
            ->assertJsonPath('contract.id', $contract->external_key)
            ->assertJsonPath('contract.status', 'Rejected');

        $contract->refresh();

        $this->assertSame(Contract::STATUS_REJECTED, $contract->status);
        $this->assertFalse($contract->agreement_accepted);
        $this->assertFalse($contract->terms_accepted);
        $this->assertFalse($contract->privacy_accepted);
        $this->assertNull($contract->accepted_at);
        $this->assertNotNull($contract->rejected_at);
        $this->assertNotNull($contract->decision_at);

        $this->assertDatabaseHas('contract_audit_logs', [
            'contract_id' => $contract->id,
            'user_id' => $customer->id,
            'action' => ContractAuditLog::ACTION_REJECTED,
            'old_status' => Contract::STATUS_PENDING_REVIEW,
            'new_status' => Contract::STATUS_REJECTED,
        ]);
    }

    public function test_customer_can_upload_signed_document(): void
    {
        Storage::fake('local');

        $customer = $this->customerUser('upload@example.com');
        $service = $this->catalogService('Business Hosting');
        $order = $this->orderFor($customer, $service, 'WSI-240001');
        $contract = $this->createContract($customer, [
            'order_id' => $order->id,
            'external_key' => 'order-'.$order->order_number,
            'scope' => Contract::SCOPE_ORDER,
            'title' => 'Business Hosting Agreement',
            'service_name' => 'Business Hosting',
            'status' => Contract::STATUS_ACCEPTED,
            'agreement_accepted' => true,
            'terms_accepted' => true,
            'privacy_accepted' => true,
            'requires_signed_document' => true,
            'decision_by' => $customer->name,
            'decision_at' => now(),
            'accepted_at' => now(),
        ]);

        Sanctum::actingAs($customer);

        $response = $this->post('/api/contracts/'.$contract->external_key.'/signed-document', [
            'signedDocument' => UploadedFile::fake()->create('signed-business-hosting.pdf', 128, 'application/pdf'),
        ], [
            'Accept' => 'application/json',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('message', 'Signed document uploaded successfully.')
            ->assertJsonPath('contract.id', $contract->external_key)
            ->assertJsonPath('contract.signedDocumentName', 'signed-business-hosting.pdf');

        $contract->refresh();

        $this->assertSame('signed-business-hosting.pdf', $contract->signed_document_name);
        $this->assertNotNull($contract->signed_document_path);
        $this->assertNotNull($contract->signed_document_uploaded_at);
        $this->assertSame($customer->id, $contract->signed_document_uploaded_by);
        Storage::disk('local')->assertExists($contract->signed_document_path);

        $this->assertDatabaseHas('contract_audit_logs', [
            'contract_id' => $contract->id,
            'user_id' => $customer->id,
            'action' => ContractAuditLog::ACTION_SIGNED_DOCUMENT_UPLOADED,
        ]);
    }

    public function test_customer_uploaded_signed_document_is_visible_in_admin_contracts_endpoint(): void
    {
        Storage::fake('local');

        $admin = $this->adminUser('admin-contracts-list@example.com');
        $customer = $this->customerUser('customer-contracts-list@example.com');
        $service = $this->catalogService('Business Hosting');
        $order = $this->orderFor($customer, $service, 'WSI-240101');
        $customerService = $this->customerServiceFor($customer, $service, 'Business Hosting');

        $this->createContract($customer, [
            'order_id' => $order->id,
            'customer_service_id' => $customerService->id,
            'external_key' => 'order-'.$order->order_number,
            'scope' => Contract::SCOPE_ORDER,
            'title' => 'Business Hosting Agreement',
            'service_name' => 'Business Hosting',
            'status' => Contract::STATUS_ACCEPTED,
            'agreement_accepted' => true,
            'terms_accepted' => true,
            'privacy_accepted' => true,
        ]);

        Sanctum::actingAs($customer);

        $this->post('/api/contracts/service-'.$customerService->id.'/signed-document', [
            'signedDocument' => UploadedFile::fake()->create('customer-signed.pdf', 128, 'application/pdf'),
        ], [
            'Accept' => 'application/json',
        ])
            ->assertOk()
            ->assertJsonPath('contract.id', 'service-'.$customerService->id)
            ->assertJsonPath('contract.signedDocumentName', 'customer-signed.pdf')
            ->assertJsonPath('contract.signed_document_name', 'customer-signed.pdf');

        Sanctum::actingAs($admin);

        $adminContractsResponse = $this->getJson('/api/admin/contracts');

        $adminContractsResponse->assertOk();

        $adminContract = collect($adminContractsResponse->json('contracts'))
            ->firstWhere('externalKey', 'service-'.$customerService->id);

        $this->assertNotNull($adminContract);
        $this->assertSame('service-'.$customerService->id, $adminContract['id']);
        $this->assertSame((string) $customer->id, $adminContract['clientId']);
        $this->assertSame($customer->name, $adminContract['clientName']);
        $this->assertSame($customerService->id, $adminContract['serviceId']);
        $this->assertSame($order->id, $adminContract['orderId']);
        $this->assertSame($order->order_number, $adminContract['orderNumber']);
        $this->assertSame('customer-signed.pdf', $adminContract['signedDocumentName']);
        $this->assertSame('customer-signed.pdf', $adminContract['signed_document_name']);
        $this->assertNotNull($adminContract['signedDocumentUploadedAt']);
        $this->assertNotNull($adminContract['signed_document_uploaded_at']);
        $this->assertNotNull($adminContract['signedDocumentUrl']);
        $this->assertNotNull($adminContract['signed_document_url']);
    }

    public function test_admin_can_upload_signed_document_and_customer_sees_it(): void
    {
        Storage::fake('local');

        $admin = $this->adminUser('admin-upload@example.com');
        $customer = $this->customerUser('customer-upload@example.com');
        $service = $this->catalogService('Business Hosting');
        $order = $this->orderFor($customer, $service, 'WSI-240102');
        $customerService = $this->customerServiceFor($customer, $service, 'Business Hosting');

        $this->createContract($customer, [
            'order_id' => $order->id,
            'customer_service_id' => $customerService->id,
            'external_key' => 'service-'.$customerService->id,
            'scope' => Contract::SCOPE_SERVICE,
            'title' => 'Business Hosting Agreement',
            'service_name' => 'Business Hosting',
            'status' => Contract::STATUS_ACCEPTED,
            'agreement_accepted' => true,
            'terms_accepted' => true,
            'privacy_accepted' => true,
        ]);

        Sanctum::actingAs($admin);

        $this->post('/api/admin/contracts/service-'.$customerService->id.'/signed-document', [
            'signedDocument' => UploadedFile::fake()->create('admin-signed.docx', 64, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
        ], [
            'Accept' => 'application/json',
        ])
            ->assertOk()
            ->assertJsonPath('contract.id', 'service-'.$customerService->id)
            ->assertJsonPath('contract.signedDocumentName', 'admin-signed.docx');

        Sanctum::actingAs($customer);

        $customerContractsResponse = $this->getJson('/api/contracts/me');
        $customerContractsResponse->assertOk();

        $customerContract = collect($customerContractsResponse->json('contracts'))
            ->firstWhere('externalKey', 'service-'.$customerService->id);

        $this->assertNotNull($customerContract);
        $this->assertSame('admin-signed.docx', $customerContract['signedDocumentName']);
        $this->assertNotNull($customerContract['signedDocumentUrl']);
    }

    public function test_signed_document_url_downloads_uploaded_file(): void
    {
        Storage::fake('local');

        $customer = $this->customerUser('download-signed@example.com');
        $service = $this->catalogService('Business Hosting');
        $order = $this->orderFor($customer, $service, 'WSI-240103');
        $contract = $this->createContract($customer, [
            'order_id' => $order->id,
            'external_key' => 'order-'.$order->order_number,
            'scope' => Contract::SCOPE_ORDER,
            'title' => 'Business Hosting Agreement',
            'service_name' => 'Business Hosting',
            'status' => Contract::STATUS_ACCEPTED,
            'agreement_accepted' => true,
            'terms_accepted' => true,
            'privacy_accepted' => true,
        ]);

        Sanctum::actingAs($customer);

        $this->post('/api/contracts/'.$contract->external_key.'/signed-document', [
            'signedDocument' => UploadedFile::fake()->create('signed-download.pdf', 32, 'application/pdf'),
        ], [
            'Accept' => 'application/json',
        ])->assertOk();

        $contractPayload = PortalFormatter::contract($contract->fresh('user', 'order.items', 'customerService.service', 'verifiedBy', 'signedDocumentUploader'));

        $response = $this->get($this->relativeUrl($contractPayload['signedDocumentUrl']));

        $response->assertOk();
        $response->assertHeader('content-disposition');
        $this->assertStringContainsString('signed-download.pdf', (string) $response->headers->get('content-disposition'));
    }

    public function test_checkout_creates_accepted_contract_record(): void
    {
        $customer = $this->customerUser('checkout@example.com');
        $service = $this->catalogService('Business Hosting');

        Sanctum::actingAs($customer);

        $response = $this->postJson('/api/orders/checkout', [
            'cart' => [[
                'serviceId' => $service->id,
                'serviceName' => $service->name,
                'category' => $service->category,
                'configuration' => 'Starter',
                'addon' => null,
                'price' => 4999,
            ]],
            'paymentMethod' => 'bank_transfer',
            'note' => 'Needs managed onboarding',
            'agreementAccepted' => true,
        ]);

        $response->assertCreated();

        $order = PortalOrder::query()->firstOrFail();
        $contract = Contract::query()->firstOrFail();

        $this->assertSame($customer->id, $contract->user_id);
        $this->assertSame($order->id, $contract->order_id);
        $this->assertSame('order-'.$order->order_number, $contract->external_key);
        $this->assertSame(Contract::STATUS_ACCEPTED, $contract->status);
        $this->assertTrue($contract->agreement_accepted);
        $this->assertTrue($contract->terms_accepted);
        $this->assertTrue($contract->privacy_accepted);
        $this->assertTrue($contract->requires_signed_document);
        $this->assertNotNull($contract->accepted_at);
        $this->assertNotNull($contract->decision_at);
        $this->assertSame('ORDER-'.$order->order_number, $contract->audit_reference);
        $this->assertSame(3, count($contract->document_sections ?? []));

        $this->assertDatabaseHas('contract_audit_logs', [
            'contract_id' => $contract->id,
            'user_id' => $customer->id,
            'action' => ContractAuditLog::ACTION_CREATED,
            'new_status' => Contract::STATUS_ACCEPTED,
        ]);

        $contractsResponse = $this->getJson('/api/contracts/me');

        $contractsResponse
            ->assertOk()
            ->assertJsonFragment([
                'id' => $contract->external_key,
                'externalKey' => $contract->external_key,
                'status' => 'Accepted',
                'serviceName' => 'Business Hosting',
                'requiresSignedDocument' => true,
            ]);
    }

    public function test_signed_contract_download_url_can_be_opened_without_bearer_token(): void
    {
        $customer = $this->customerUser('download@example.com');
        $service = $this->catalogService('Business Hosting');
        $order = $this->orderFor($customer, $service, 'WSI-245001');
        $contract = $this->createContract($customer, [
            'order_id' => $order->id,
            'external_key' => 'order-'.$order->order_number,
            'scope' => Contract::SCOPE_ORDER,
            'title' => 'Business Hosting Agreement',
            'service_name' => 'Business Hosting',
        ]);

        $downloadUrl = PortalFormatter::contract($contract->fresh('order.items', 'customerService'))['downloadUrl'];

        $response = $this->get($this->relativeUrl($downloadUrl));

        $response->assertOk();
        $this->assertStringContainsString('Business Hosting Agreement', $response->streamedContent());
    }

    public function test_unsigned_contract_download_returns_unauthorized_for_api_requests(): void
    {
        $customer = $this->customerUser('download-blocked@example.com');
        $service = $this->catalogService('Business Hosting');
        $order = $this->orderFor($customer, $service, 'WSI-245002');
        $contract = $this->createContract($customer, [
            'order_id' => $order->id,
            'external_key' => 'order-'.$order->order_number,
            'scope' => Contract::SCOPE_ORDER,
            'title' => 'Business Hosting Agreement',
            'service_name' => 'Business Hosting',
        ]);

        $this->getJson('/api/contracts/'.$contract->external_key.'/download')
            ->assertUnauthorized()
            ->assertJson([
                'message' => 'Unauthenticated.',
            ]);
    }

    public function test_admin_can_download_service_agreement_from_legacy_route(): void
    {
        $admin = $this->adminUser('admin-download@example.com');
        $customer = $this->customerUser('service-download-admin@example.com');
        $service = $this->catalogService('Business Hosting');
        $order = $this->orderFor($customer, $service, 'WSI-245003');
        $customerService = $this->customerServiceFor($customer, $service, 'Business Hosting');

        $this->createContract($customer, [
            'order_id' => $order->id,
            'customer_service_id' => $customerService->id,
            'external_key' => 'order-'.$order->order_number,
            'scope' => Contract::SCOPE_ORDER,
            'title' => 'Business Hosting Agreement',
            'service_name' => 'Business Hosting',
            'status' => Contract::STATUS_ACCEPTED,
            'agreement_accepted' => true,
            'terms_accepted' => true,
            'privacy_accepted' => true,
        ]);

        Sanctum::actingAs($admin);

        $response = $this->get('/api/services/'.$customerService->id.'/agreement.pdf');

        $response->assertOk();
        $this->assertStringContainsString('Business Hosting Agreement', $response->streamedContent());
    }

    public function test_service_key_materializes_missing_service_contract_and_allows_decision(): void
    {
        $customer = $this->customerUser('service-key@example.com');
        $service = $this->catalogService('Business Hosting');
        $order = $this->orderFor($customer, $service, 'WSI-250001');
        $customerService = $this->customerServiceFor($customer, $service, 'Business Hosting');

        $orderContract = $this->createContract($customer, [
            'order_id' => $order->id,
            'customer_service_id' => $customerService->id,
            'external_key' => 'order-'.$order->order_number,
            'scope' => Contract::SCOPE_ORDER,
            'title' => 'Business Hosting Agreement',
            'service_name' => 'Business Hosting',
            'status' => Contract::STATUS_PENDING_REVIEW,
            'agreement_accepted' => false,
            'terms_accepted' => false,
            'privacy_accepted' => false,
        ]);

        Sanctum::actingAs($customer);

        $response = $this->patchJson('/api/contracts/service-'.$customerService->id.'/decision', [
            'decision' => 'accept',
            'agreementAccepted' => true,
            'termsAccepted' => true,
            'privacyAccepted' => true,
            'decisionBy' => $customer->name,
            'source' => 'customer-portal',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('contract.id', 'service-'.$customerService->id)
            ->assertJsonPath('contract.externalKey', 'service-'.$customerService->id)
            ->assertJsonPath('contract.status', 'Accepted')
            ->assertJsonPath('contract.orderNumber', $order->order_number)
            ->assertJsonPath('contract.serviceName', 'Business Hosting');

        $serviceContract = Contract::query()->where('external_key', 'service-'.$customerService->id)->firstOrFail();

        $this->assertSame(Contract::SCOPE_SERVICE, $serviceContract->scope);
        $this->assertSame($customerService->id, $serviceContract->customer_service_id);
        $this->assertSame($order->id, $serviceContract->order_id);
        $this->assertSame(Contract::STATUS_ACCEPTED, $serviceContract->status);
        $this->assertTrue($serviceContract->agreement_accepted);

        $orderContract->refresh();
        $this->assertSame(Contract::STATUS_PENDING_REVIEW, $orderContract->status);
    }

    public function test_admin_can_verify_service_contract_key(): void
    {
        $admin = $this->adminUser('admin-contracts@example.com');
        $customer = $this->customerUser('service-verify@example.com');
        $service = $this->catalogService('Business Hosting');
        $order = $this->orderFor($customer, $service, 'WSI-260001');
        $customerService = $this->customerServiceFor($customer, $service, 'Business Hosting');

        $this->createContract($customer, [
            'order_id' => $order->id,
            'customer_service_id' => $customerService->id,
            'external_key' => 'order-'.$order->order_number,
            'scope' => Contract::SCOPE_ORDER,
            'title' => 'Business Hosting Agreement',
            'service_name' => 'Business Hosting',
            'status' => Contract::STATUS_ACCEPTED,
            'agreement_accepted' => true,
            'terms_accepted' => true,
            'privacy_accepted' => true,
        ]);

        Sanctum::actingAs($admin);

        $response = $this->patchJson('/api/admin/contracts/service-'.$customerService->id.'/verify', [
            'note' => 'Signed agreement reviewed by finance.',
            'source' => 'admin-portal',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('message', 'Contract verified successfully.')
            ->assertJsonPath('contract.id', 'service-'.$customerService->id)
            ->assertJsonPath('contract.externalKey', 'service-'.$customerService->id)
            ->assertJsonPath('contract.verifiedBy', $admin->name)
            ->assertJsonPath('contract.isVerified', true)
            ->assertJsonPath('contract.orderNumber', $order->order_number);

        $serviceContract = Contract::query()->where('external_key', 'service-'.$customerService->id)->firstOrFail();

        $this->assertSame($admin->id, $serviceContract->verified_by);
        $this->assertNotNull($serviceContract->verified_at);

        $this->assertDatabaseHas('contract_audit_logs', [
            'contract_id' => $serviceContract->id,
            'user_id' => $admin->id,
            'action' => ContractAuditLog::ACTION_VERIFIED,
            'old_status' => $serviceContract->status,
            'new_status' => $serviceContract->status,
        ]);
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

    private function adminUser(string $email): User
    {
        return User::factory()->create([
            'name' => 'Admin '.strtoupper(substr($email, 0, 1)),
            'email' => $email,
            'role' => 'admin',
            'is_enabled' => true,
        ]);
    }

    private function catalogService(string $name): Service
    {
        return Service::query()->create([
            'slug' => strtolower(str_replace(' ', '-', $name)).'-'.strtolower(bin2hex(random_bytes(2))),
            'category' => 'Hosting',
            'name' => $name,
            'description' => 'Managed service '.$name,
            'price' => 4999,
            'billing_cycle' => 'monthly',
            'is_active' => true,
        ]);
    }

    private function orderFor(User $customer, Service $service, string $orderNumber): PortalOrder
    {
        $order = PortalOrder::query()->create([
            'order_number' => $orderNumber,
            'user_id' => $customer->id,
            'total_amount' => 4999,
            'payment_method' => 'bank_transfer',
            'agreement_accepted' => true,
            'terms_accepted' => true,
            'privacy_accepted' => true,
            'status' => 'pending_review',
        ]);

        $order->items()->create([
            'service_id' => $service->id,
            'service_name' => $service->name,
            'category' => $service->category,
            'configuration' => 'Starter',
            'addon' => null,
            'price' => 4999,
            'billing_cycle' => 'monthly',
            'provisioning_status' => 'pending_review',
        ]);

        return $order->fresh('items');
    }

    private function customerServiceFor(User $customer, Service $service, string $name): CustomerService
    {
        return CustomerService::query()->create([
            'user_id' => $customer->id,
            'service_id' => $service->id,
            'order_item_id' => null,
            'name' => $name,
            'category' => $service->category,
            'plan' => $name,
            'status' => 'active',
            'renews_on' => now()->addMonth(),
        ]);
    }

    private function createContract(User $customer, array $overrides = []): Contract
    {
        return Contract::query()->create([
            'user_id' => $customer->id,
            'order_id' => $overrides['order_id'] ?? null,
            'customer_service_id' => $overrides['customer_service_id'] ?? null,
            'external_key' => $overrides['external_key'] ?? ('contract-'.bin2hex(random_bytes(3))),
            'scope' => $overrides['scope'] ?? Contract::SCOPE_ORDER,
            'title' => $overrides['title'] ?? 'WSI Agreement',
            'description' => $overrides['description'] ?? 'Customer agreement bundle attached to the record.',
            'service_name' => $overrides['service_name'] ?? null,
            'version' => $overrides['version'] ?? 'v1.0',
            'status' => $overrides['status'] ?? Contract::STATUS_PENDING_REVIEW,
            'agreement_accepted' => $overrides['agreement_accepted'] ?? false,
            'terms_accepted' => $overrides['terms_accepted'] ?? false,
            'privacy_accepted' => $overrides['privacy_accepted'] ?? false,
            'requires_signed_document' => $overrides['requires_signed_document'] ?? true,
            'signed_document_name' => $overrides['signed_document_name'] ?? null,
            'signed_document_path' => $overrides['signed_document_path'] ?? null,
            'signed_document_uploaded_at' => $overrides['signed_document_uploaded_at'] ?? null,
            'signed_document_uploaded_by' => $overrides['signed_document_uploaded_by'] ?? null,
            'download_path' => $overrides['download_path'] ?? null,
            'audit_reference' => $overrides['audit_reference'] ?? null,
            'decision_by' => $overrides['decision_by'] ?? null,
            'decision_at' => $overrides['decision_at'] ?? null,
            'verified_by' => $overrides['verified_by'] ?? null,
            'verified_at' => $overrides['verified_at'] ?? null,
            'verification_status' => $overrides['verification_status'] ?? Contract::VERIFICATION_PENDING,
            'accepted_at' => $overrides['accepted_at'] ?? null,
            'rejected_at' => $overrides['rejected_at'] ?? null,
            'document_sections' => $overrides['document_sections'] ?? Contract::defaultDocumentSections(),
        ]);
    }

    private function relativeUrl(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?: '/';
        $query = parse_url($url, PHP_URL_QUERY);

        return $query ? $path.'?'.$query : $path;
    }
}
