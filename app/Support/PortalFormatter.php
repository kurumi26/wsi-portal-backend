<?php

namespace App\Support;

use App\Models\Contract;
use App\Models\CustomerService;
use App\Models\HelpdeskTicket;
use App\Models\HelpdeskTicketActivity;
use App\Models\PortalNotification;
use App\Models\PortalOrder;
use App\Models\ProfileUpdateRequest;
use App\Models\Service;
use App\Models\ServiceAddon;
use App\Models\User;
use App\Models\Invoice;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class PortalFormatter
{
    public const BILLING_LABELS = [
        'monthly' => 'monthly',
        'yearly' => 'yearly',
        'one_time' => 'one-time',
    ];

    public const ORDER_LABELS = [
        'paid' => 'Paid',
        'approved' => 'Approved',
        'failed' => 'Failed',
        'pending_review' => 'Pending Review',
    ];

    public const SERVICE_STATUS_LABELS = [
        'active' => 'Active',
        'expired' => 'Expired',
        'unpaid' => 'Unpaid',
        'undergoing_provisioning' => 'Undergoing Provisioning',
    ];

    public const NOTIFICATION_TYPE_LABELS = [
        'info' => 'info',
        'warning' => 'warning',
        'success' => 'success',
        'danger' => 'danger',
    ];

    public const PROFILE_UPDATE_STATUS_LABELS = [
        'pending' => 'Pending Approval',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
    ];

    public const REGISTRATION_STATUS_LABELS = [
        'pending' => 'Pending Approval',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
    ];

    public const CANCELLATION_STATUS_LABELS = [
        'pending' => 'Pending Approval',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
    ];

    public const ADMIN_STATUS_MAP = [
        'Active' => 'active',
        'Expired' => 'expired',
        'Unpaid' => 'unpaid',
        'Undergoing Provisioning' => 'undergoing_provisioning',
    ];

    public const INTERNAL_ROLE_LABELS = [
        'admin' => 'Admin',
        'technical_support' => 'Technical Support',
        'sales' => 'Sales',
    ];

    public static function sanitizeUser(User $user): array
    {
        $profileUpdateRequest = $user->relationLoaded('latestProfileUpdateRequest')
            ? $user->latestProfileUpdateRequest
            : $user->latestProfileUpdateRequest()->with('reviewer')->first();

        return [
            'id' => (string) $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'company' => $user->company,
            'address' => $user->address,
            'mobileNumber' => $user->mobile_number,
            'tin' => $user->tin,
            'profilePhotoUrl' => $user->profile_photo_url,
            'profileUpdateRequest' => self::profileUpdateRequest($profileUpdateRequest),
            'registrationApproval' => self::registrationApproval($user),
            'twoFactorEnabled' => (bool) $user->two_factor_enabled,
            'isEnabled' => (bool) $user->is_enabled,
            'role' => strtolower($user->role),
        ];
    }

    public static function registrationApproval(User $user): ?array
    {
        if ($user->role !== 'customer') {
            return null;
        }

        return [
            'status' => self::REGISTRATION_STATUS_LABELS[$user->registration_status] ?? ucfirst($user->registration_status),
            'statusKey' => $user->registration_status,
            'submittedAt' => $user->created_at?->toISOString(),
            'reviewedAt' => $user->registration_reviewed_at?->toISOString(),
            'reviewedBy' => $user->registrationReviewer?->name,
            'adminNotes' => $user->registration_admin_notes,
        ];
    }

    public static function adminUser(User $user): array
    {
        return [
            'id' => (string) $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => self::INTERNAL_ROLE_LABELS[$user->role] ?? ucfirst(str_replace('_', ' ', $user->role)),
            'roleKey' => $user->role,
            'status' => $user->is_enabled ? 'Enabled' : 'Disabled',
            'isEnabled' => (bool) $user->is_enabled,
            'joinedAt' => $user->created_at?->toISOString(),
        ];
    }

    public static function profileUpdateRequest(?ProfileUpdateRequest $profileUpdateRequest): ?array
    {
        if (! $profileUpdateRequest) {
            return null;
        }

        return [
            'id' => (string) $profileUpdateRequest->id,
            'status' => self::PROFILE_UPDATE_STATUS_LABELS[$profileUpdateRequest->status] ?? ucfirst($profileUpdateRequest->status),
            'statusKey' => $profileUpdateRequest->status,
            'submittedAt' => $profileUpdateRequest->created_at?->toISOString(),
            'reviewedAt' => $profileUpdateRequest->reviewed_at?->toISOString(),
            'reviewedBy' => $profileUpdateRequest->reviewer?->name,
            'adminNotes' => $profileUpdateRequest->admin_notes,
            'requestedProfile' => [
                'name' => $profileUpdateRequest->name,
                'email' => $profileUpdateRequest->email,
                'company' => $profileUpdateRequest->company,
                'address' => $profileUpdateRequest->address,
                'mobileNumber' => $profileUpdateRequest->mobile_number,
                'profilePhotoUrl' => $profileUpdateRequest->profile_photo_url,
            ],
        ];
    }

    public static function service(Service $service): array
    {
        return [
            'id' => (string) $service->id,
            'category' => $service->category,
            'name' => $service->name,
            'description' => $service->description,
            'price' => (float) $service->price,
            'billing' => BillingCycle::label($service->billing_cycle),
            'configurations' => $service->configurations->pluck('label')->values()->all(),
            'addons' => $service->addons->map(fn (ServiceAddon $addon) => self::serviceAddon($addon, $service->billing_cycle))->values()->all(),
        ];
    }

    public static function order(PortalOrder $order): array
    {
        $firstItem = $order->items->first();

        $order->loadMissing(['payments', 'invoice.proofs']);

        $hasPendingCustomerSubmission = $order->payments->contains(fn ($payment) => $payment->status === 'pending')
            || ($order->invoice?->proofs?->contains(fn ($proof) => $proof->review_status === 'pending') ?? false);

        $invoiceStatusKey = null;

        if ($order->invoice) {
            $fallbackStatus = in_array($order->invoice->status, ['cancelled', 'overdue', 'pending_review', 'unpaid'], true)
                ? $order->invoice->status
                : ($order->status === 'pending_review' ? 'pending_review' : 'unpaid');

            $invoiceStatusKey = $order->invoice->paid_at
                ? 'paid'
                : ($hasPendingCustomerSubmission ? 'pending_review' : $fallbackStatus);
        }

        return [
            'id' => $order->order_number,
            'serviceName' => $firstItem?->service_name ?? 'Service Order',
            'amount' => (float) $order->total_amount,
            'paymentMethod' => $order->payment_method,
            'note' => $firstItem?->customer_note ?? $order->customer_note,
            'status' => self::ORDER_LABELS[$order->status] ?? $order->status,
            'statusKey' => $order->status,
            'date' => $order->created_at?->toISOString(),
            'payments' => $order->payments->map(fn ($p) => [
                'id' => (string) $p->id,
                'amount' => (float) $p->amount,
                'method' => $p->method,
                'status' => $p->status,
                'transactionRef' => $p->transaction_ref,
                'createdAt' => $p->created_at?->toISOString(),
            ])->values()->all(),
            'invoiceId' => $order->invoice?->id ? (string) $order->invoice->id : null,
            'invoiceNumber' => $order->invoice?->invoice_number,
            'invoiceStatus' => $invoiceStatusKey,
            'invoice_status' => $invoiceStatusKey,
            'billingStatus' => $invoiceStatusKey,
            'billing_status' => $invoiceStatusKey,
            'invoiceUrl' => $order->invoice?->invoice_number ? null : null,
            'dueDate' => $order->invoice?->due_date?->toDateString(),
            'paidAt' => $order->invoice?->paid_at?->toISOString(),
            'paid_at' => $order->invoice?->paid_at?->toISOString(),
            'invoicePaidAt' => $order->invoice?->paid_at?->toISOString(),
        ];
    }

    public static function invoice(Invoice $invoice): array
    {
        $invoice->loadMissing(['order', 'proofs', 'user']);

        $hasPendingCustomerSubmission = $invoice->proofs->contains(fn ($proof) => $proof->review_status === 'pending');
        $fallbackStatus = in_array($invoice->status, ['cancelled', 'overdue', 'pending_review', 'unpaid'], true)
            ? $invoice->status
            : 'unpaid';
        $statusKey = $invoice->paid_at
            ? 'paid'
            : ($hasPendingCustomerSubmission ? 'pending_review' : $fallbackStatus);

        return [
            'id' => (string) $invoice->id,
            'invoiceNumber' => $invoice->invoice_number,
            'invoice_number' => $invoice->invoice_number,
            'status' => str_replace('_', ' ', ucfirst($statusKey)),
            'statusKey' => $statusKey,
            'invoiceStatus' => $statusKey,
            'invoice_status' => $statusKey,
            'billingStatus' => $statusKey,
            'billing_status' => $statusKey,
            'clientName' => $invoice->client_name,
            'companyName' => $invoice->company_name,
            'subtotal' => (float) $invoice->subtotal,
            'discounts' => (float) $invoice->discounts,
            'totalAmount' => (float) $invoice->total_amount,
            'dueDate' => $invoice->due_date?->toDateString(),
            'paidAt' => $invoice->paid_at?->toISOString(),
            'paid_at' => $invoice->paid_at?->toISOString(),
            'invoicePaidAt' => $invoice->paid_at?->toISOString(),
            'paidBy' => $invoice->paid_by ? (string) $invoice->paid_by : null,
            'paymentReference' => $invoice->payment_reference,
            'internalNote' => $invoice->internal_note,
            'orderId' => $invoice->portal_order_id ? $invoice->portal_order_id : null,
            'orderNumber' => $invoice->order?->order_number,
            'proofs' => $invoice->proofs->map(fn ($p) => [
                'id' => (string) $p->id,
                'path' => $p->path,
                'uploadedAt' => $p->uploaded_at?->toISOString(),
                'reviewStatus' => $p->review_status,
                'reviewNote' => $p->review_note,
                'uploadedBy' => $p->uploader?->name,
            ])->values()->all(),
            'createdAt' => $invoice->created_at?->toISOString(),
            'updatedAt' => $invoice->updated_at?->toISOString(),
        ];
    }

    public static function customerService(CustomerService $service): array
    {
        $service->loadMissing(['service.configurations', 'service.addons', 'cancellationReviewer']);

        $catalogService = $service->service;
        $configurationLabels = $catalogService?->configurations?->pluck('label')->values()->all() ?? [];
        $migrationPaths = collect($configurationLabels)
            ->reject(fn (string $label) => $label === $service->plan)
            ->values()
            ->all();

        return [
            'id' => (string) $service->id,
            'name' => $service->name,
            'category' => $service->category,
            'status' => self::SERVICE_STATUS_LABELS[$service->status] ?? $service->status,
            'cancellationRequest' => self::cancellationRequest($service),
            'renewsOn' => $service->renews_on?->toISOString(),
            'plan' => $service->plan,
            'basePrice' => $catalogService?->price ? (float) $catalogService->price : null,
            'billing' => $catalogService ? BillingCycle::label($catalogService->billing_cycle) : null,
            'addons' => $catalogService?->addons?->map(fn (ServiceAddon $addon) => self::serviceAddon($addon, $catalogService->billing_cycle))->values()->all() ?? [],
            'migrationPaths' => $migrationPaths,
        ];
    }

    private static function serviceAddon(ServiceAddon $addon, ?string $parentBillingCycle = null): array
    {
        return [
            'label' => $addon->label,
            'price' => (float) $addon->extra_price,
            'billingCycle' => BillingCycle::addonValue($addon->billing_cycle, $parentBillingCycle),
        ];
    }

    public static function cancellationRequest(CustomerService $service): ?array
    {
        if (! $service->cancellation_status && ! $service->cancellation_requested_at && ! $service->cancellation_reviewed_at) {
            return null;
        }

        return [
            'status' => self::CANCELLATION_STATUS_LABELS[$service->cancellation_status] ?? ucfirst((string) $service->cancellation_status),
            'statusKey' => $service->cancellation_status,
            'reason' => $service->cancellation_reason,
            'requestedAt' => $service->cancellation_requested_at?->toISOString(),
            'reviewedAt' => $service->cancellation_reviewed_at?->toISOString(),
            'reviewedBy' => $service->cancellationReviewer?->name,
        ];
    }

    public static function notification(PortalNotification $notification): array
    {
        return [
            'id' => (string) $notification->id,
            'title' => $notification->title,
            'message' => $notification->message,
            'createdAt' => $notification->created_at?->toISOString(),
            'type' => self::NOTIFICATION_TYPE_LABELS[$notification->type] ?? $notification->type,
            'isRead' => (bool) $notification->is_read,
        ];
    }

    public static function contract(Contract $contract): array
    {
        $contract->loadMissing(['user', 'order.items', 'customerService.service', 'verifiedBy', 'signedDocumentUploader']);

        $client = $contract->user;
        $order = $contract->order;
        $firstOrderItem = $order?->items?->first();
        $customerService = $contract->customerService;
        $customerServiceId = $contract->customer_service_id ?? $customerService?->id;
        $catalogServiceId = $customerService?->service_id ?? $firstOrderItem?->service_id;
        $serviceId = $customerServiceId ?? $catalogServiceId;
        $serviceName = $contract->service_name ?? $customerService?->name ?? $firstOrderItem?->service_name;
        $signedDocumentUrl = $contract->signed_document_path
            ? URL::temporarySignedRoute(
                'contracts.signed-document.download',
                now()->addDay(),
                ['contract' => $contract->external_key]
            )
            : null;
        $verificationStatus = $contract->verification_status
            ?: ($contract->verified_at ? Contract::VERIFICATION_VERIFIED : Contract::VERIFICATION_PENDING);

        return [
            'id' => $contract->external_key,
            'externalKey' => $contract->external_key,
            'scope' => $contract->scope,
            'title' => $contract->title,
            'description' => $contract->description,
            'clientId' => (string) $contract->user_id,
            'clientName' => $client?->name,
            'serviceId' => $serviceId,
            'customerServiceId' => $customerServiceId,
            'catalogServiceId' => $catalogServiceId,
            'serviceName' => $serviceName,
            'orderId' => $contract->order_id,
            'orderNumber' => $order?->order_number,
            'status' => $contract->status,
            'version' => $contract->version,
            'issuedAt' => $contract->created_at?->toISOString(),
            'acceptedAt' => $contract->accepted_at?->toISOString(),
            'rejectedAt' => $contract->rejected_at?->toISOString(),
            'decisionAt' => $contract->decision_at?->toISOString(),
            'decisionBy' => $contract->decision_by,
            'verifiedAt' => $contract->verified_at?->toISOString(),
            'verifiedBy' => $contract->verifiedBy?->name,
            'verificationStatus' => $verificationStatus,
            'verification_status' => $verificationStatus,
            'isVerified' => $verificationStatus === Contract::VERIFICATION_VERIFIED,
            'requiresSignedDocument' => (bool) $contract->requires_signed_document,
            'signedDocumentName' => $contract->signed_document_name,
            'signedDocumentUploadedAt' => $contract->signed_document_uploaded_at?->toISOString(),
            'signedDocumentUploadedBy' => $contract->signedDocumentUploader?->name,
            'signedDocumentUrl' => $signedDocumentUrl,
            'signed_document_name' => $contract->signed_document_name,
            'signed_document_uploaded_at' => $contract->signed_document_uploaded_at?->toISOString(),
            'signed_document_uploaded_by' => $contract->signedDocumentUploader?->name,
            'signed_document_url' => $signedDocumentUrl,
            'downloadUrl' => URL::temporarySignedRoute(
                'contracts.download',
                now()->addDay(),
                ['contract' => $contract->external_key]
            ),
            'auditReference' => $contract->audit_reference ?? self::contractAuditReference($contract),
            'documentSections' => ! empty($contract->document_sections)
                ? $contract->document_sections
                : Contract::defaultDocumentSections(),
        ];
    }

    public static function helpdeskTicket(HelpdeskTicket $ticket, bool $includeActivities = false): array
    {
        $ticket->loadMissing(['customer', 'customerService', 'assignedTo']);

        if ($includeActivities) {
            $ticket->loadMissing('activities.actor');
        }

        $payload = [
            'id' => $ticket->id,
            'reference' => $ticket->reference_number,
            'title' => $ticket->title,
            'message' => $ticket->message,
            'category' => $ticket->category,
            'status' => $ticket->status,
            'priority' => $ticket->priority,
            'source' => $ticket->source,
            'createdAt' => $ticket->created_at?->toISOString(),
            'updatedAt' => $ticket->updated_at?->toISOString(),
            'resolvedAt' => $ticket->resolved_at?->toISOString(),
            'closedAt' => $ticket->closed_at?->toISOString(),
            'serviceId' => $ticket->service_id,
            'serviceName' => $ticket->customerService?->name,
            'clientId' => $ticket->customer_id,
            'clientName' => $ticket->customer?->name,
            'clientEmail' => $ticket->customer?->email,
            'assignedTo' => self::helpdeskAssignedUser($ticket->assignedTo),
        ];

        if ($includeActivities) {
            $payload['activities'] = $ticket->activities
                ->map(fn (HelpdeskTicketActivity $activity) => self::helpdeskActivity($activity))
                ->values()
                ->all();
        }

        return $payload;
    }

    public static function helpdeskTicketSummary(HelpdeskTicket $ticket): array
    {
        $ticket->loadMissing('customerService');

        return [
            'id' => $ticket->id,
            'reference' => $ticket->reference_number,
            'title' => $ticket->title,
            'serviceName' => $ticket->customerService?->name,
            'category' => $ticket->category,
            'status' => $ticket->status,
            'createdAt' => $ticket->created_at?->toISOString(),
            'updatedAt' => $ticket->updated_at?->toISOString(),
        ];
    }

    private static function helpdeskAssignedUser(?User $user): ?array
    {
        if (! $user) {
            return null;
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'role' => self::INTERNAL_ROLE_LABELS[$user->role] ?? ucfirst(str_replace('_', ' ', $user->role)),
        ];
    }

    private static function helpdeskActivity(HelpdeskTicketActivity $activity): array
    {
        return [
            'id' => $activity->id,
            'action' => $activity->action,
            'oldValue' => $activity->old_value,
            'newValue' => $activity->new_value,
            'note' => $activity->note,
            'createdAt' => $activity->created_at?->toISOString(),
            'actor' => $activity->actor ? [
                'id' => $activity->actor->id,
                'name' => $activity->actor->name,
                'role' => self::INTERNAL_ROLE_LABELS[$activity->actor->role] ?? ucfirst(str_replace('_', ' ', $activity->actor->role)),
            ] : null,
        ];
    }

    private static function contractAuditReference(Contract $contract): ?string
    {
        if ($contract->order?->order_number) {
            return 'ORDER-'.$contract->order->order_number;
        }

        if ($contract->customer_service_id) {
            return 'SERVICE-'.$contract->customer_service_id;
        }

        return $contract->external_key ? strtoupper($contract->external_key) : null;
    }

    private static function absoluteUrl(string $value): string
    {
        if (Str::startsWith($value, ['http://', 'https://'])) {
            return $value;
        }

        return url($value);
    }
}
