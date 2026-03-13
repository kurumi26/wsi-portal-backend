<?php

namespace App\Support;

use App\Models\CustomerService;
use App\Models\PortalNotification;
use App\Models\PortalOrder;
use App\Models\ProfileUpdateRequest;
use App\Models\Service;
use App\Models\User;

class PortalFormatter
{
    public const BILLING_LABELS = [
        'monthly' => 'monthly',
        'yearly' => 'yearly',
        'one_time' => 'one-time',
    ];

    public const ORDER_LABELS = [
        'paid' => 'Paid',
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
            'billing' => self::BILLING_LABELS[$service->billing_cycle] ?? $service->billing_cycle,
            'configurations' => $service->configurations->pluck('label')->values()->all(),
            'addons' => $service->addons->map(fn ($addon) => [
                'label' => $addon->label,
                'price' => (float) $addon->extra_price,
            ])->values()->all(),
        ];
    }

    public static function order(PortalOrder $order): array
    {
        $firstItem = $order->items->first();

        $order->loadMissing('payments');

        return [
            'id' => $order->order_number,
            'serviceName' => $firstItem?->service_name ?? 'Service Order',
            'amount' => (float) $order->total_amount,
            'paymentMethod' => $order->payment_method,
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
            'billing' => $catalogService ? (self::BILLING_LABELS[$catalogService->billing_cycle] ?? $catalogService->billing_cycle) : null,
            'addons' => $catalogService?->addons?->map(fn ($addon) => [
                'label' => $addon->label,
                'price' => (float) $addon->extra_price,
            ])->values()->all() ?? [],
            'migrationPaths' => $migrationPaths,
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
}
