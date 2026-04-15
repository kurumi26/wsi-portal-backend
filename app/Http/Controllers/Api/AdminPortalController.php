<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CustomerService;
use App\Models\PortalNotification;
use App\Models\PortalOrder;
use App\Models\ProfileUpdateRequest;
use App\Models\Service;
use App\Models\User;
use App\Support\BillingCycle;
use App\Support\PortalFormatter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AdminPortalController extends Controller
{
    private const INTERNAL_USER_ROLES = ['admin', 'technical_support', 'sales'];

    public function clients(): JsonResponse
    {
        $clients = User::query()
            ->where('role', 'customer')
            ->with(['latestProfileUpdateRequest.reviewer', 'registrationReviewer'])
            ->withCount('customerServices')
            ->latest()
            ->get()
            ->map(fn (User $client) => [
                'id' => (string) $client->id,
                'name' => $client->name,
                'email' => $client->email,
                'company' => $client->company ?? '—',
                'address' => $client->address,
                'mobileNumber' => $client->mobile_number,
                'tin' => $client->tin,
                'profilePhotoUrl' => $client->profile_photo_url,
                'joinedAt' => $client->created_at?->toISOString(),
                'services' => $client->customer_services_count,
                'status' => $client->registration_status === 'rejected'
                    ? 'Rejected'
                    : ($client->registration_status === 'pending'
                        ? 'Pending Approval'
                        : ($client->customer_services_count > 0 ? 'Active' : 'Approved')),
                'registrationApproval' => PortalFormatter::registrationApproval($client),
                'profileUpdateRequest' => PortalFormatter::profileUpdateRequest($client->latestProfileUpdateRequest),
            ])
            ->values();

        return response()->json($clients);
    }

    public function purchases(): JsonResponse
    {
        $purchases = PortalOrder::query()
            ->with(['user', 'items'])
            ->latest()
            ->get()
            ->map(fn (PortalOrder $order) => [
                ...PortalFormatter::order($order),
                'client' => $order->user->name,
            ])
            ->values();

        return response()->json($purchases);
    }

    public function services(): JsonResponse
    {
        $services = CustomerService::query()
            ->with(['user', 'service.configurations', 'service.addons', 'cancellationReviewer'])
            ->latest()
            ->get()
            ->map(fn (CustomerService $service) => [
                ...PortalFormatter::customerService($service),
                'client' => $service->user->name,
                'clientEmail' => $service->user->email,
            ])
            ->values();

        return response()->json($services);
    }

    public function createCatalogService(Request $request): JsonResponse
    {
        $validated = $this->validateCatalogServiceRequest($request);

        $service = DB::transaction(function () use ($validated) {
            $service = Service::query()->create([
                'slug' => $this->generateUniqueServiceSlug($validated['name']),
                'category' => $validated['category'],
                'name' => $validated['name'],
                'description' => $validated['description'] ?: 'WSI managed service offering for '.$validated['name'].'.',
                'price' => $validated['price'],
                'billing_cycle' => $validated['billingCycle'],
                'is_active' => true,
            ]);

            $this->syncCatalogServiceConfiguration($service, $validated['name']);
            $this->syncCatalogServiceAddons($service, $validated['addons'], $validated['billingCycle']);

            return $service->fresh()->load(['configurations', 'addons']);
        });

        return response()->json([
            'message' => 'Service offering created successfully.',
            'service' => PortalFormatter::service($service),
        ], 201);
    }

    public function updateCatalogService(Request $request, Service $service): JsonResponse
    {
        $validated = $this->validateCatalogServiceRequest($request);
        $previousName = $service->name;

        $service = DB::transaction(function () use ($validated, $service, $previousName) {
            $service->update([
                'category' => $validated['category'],
                'name' => $validated['name'],
                'description' => $validated['description'] ?: 'WSI managed service offering for '.$validated['name'].'.',
                'price' => $validated['price'],
                'billing_cycle' => $validated['billingCycle'],
            ]);

            $this->syncCatalogServiceConfiguration($service, $validated['name'], $previousName);
            $this->syncCatalogServiceAddons($service, $validated['addons'], $validated['billingCycle']);

            return $service->fresh()->load(['configurations', 'addons']);
        });

        return response()->json([
            'message' => 'Service offering updated successfully.',
            'service' => PortalFormatter::service($service),
        ]);
    }

    public function createService(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'userId' => ['required', 'integer', 'exists:users,id'],
            'serviceId' => ['required', 'integer', 'exists:services,id'],
            'plan' => ['required', 'string', 'max:255'],
            'status' => ['required', 'string'],
            'renewsOn' => ['required', 'date'],
        ]);

        $mappedStatus = PortalFormatter::ADMIN_STATUS_MAP[$validated['status']] ?? null;

        if (! $mappedStatus) {
            return response()->json(['message' => 'Unsupported service status.'], 400);
        }

        $customer = User::query()->findOrFail($validated['userId']);
        abort_unless($customer->role === 'customer', 422, 'Only customer accounts can receive services.');

        if ($customer->registration_status === 'pending' || $customer->registration_status === 'rejected') {
            return response()->json(['message' => 'Approve the client registration before assigning a service.'], 422);
        }

        $service = Service::query()->with('configurations')->findOrFail($validated['serviceId']);

        if (! $service->configurations->pluck('label')->contains($validated['plan'])) {
            return response()->json(['message' => 'Selected plan is not available for this service.'], 422);
        }

        $customerService = CustomerService::query()->create([
            'user_id' => $customer->id,
            'service_id' => $service->id,
            'order_item_id' => null,
            'name' => $service->name,
            'category' => $service->category,
            'plan' => $validated['plan'],
            'status' => $mappedStatus,
            'renews_on' => $validated['renewsOn'],
        ]);

        PortalNotification::create([
            'user_id' => $customer->id,
            'title' => 'New service assigned',
            'message' => $service->name.' has been added to your portal account with the '.$validated['plan'].' plan.',
            'type' => 'success',
        ]);

        return response()->json([
            'message' => 'New service added successfully.',
            'service' => [
                ...PortalFormatter::customerService($customerService->fresh()),
                'client' => $customer->name,
                'clientEmail' => $customer->email,
            ],
        ], 201);
    }

    public function adminUsers(): JsonResponse
    {
        $users = User::query()
            ->whereIn('role', self::INTERNAL_USER_ROLES)
            ->latest()
            ->get()
            ->map(fn (User $user) => PortalFormatter::adminUser($user))
            ->values();

        return response()->json($users);
    }

    public function createAdminUser(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'role' => ['required', Rule::in(self::INTERNAL_USER_ROLES)],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
        ]);

        $password = $validated['password'] ?? Str::random(12);

        $user = User::query()->create([
            'name' => $validated['name'],
            'email' => strtolower($validated['email']),
            'role' => $validated['role'],
            'password' => Hash::make($password),
            'is_enabled' => true,
        ]);

        try {
            $body = "Hello {$user->name},\n\nAn account has been created for you on the WSI portal.\n\nEmail: {$user->email}\nPassword: {$password}\n\nPlease sign in and change your password.";

            Mail::raw($body, fn ($message) => $message->to($user->email)->subject('Your WSI Portal account'));
        } catch (\Throwable $e) {
        }

        return response()->json([
            'message' => 'User created successfully.',
            'user' => PortalFormatter::adminUser($user->fresh()),
        ], 201);
    }

    public function updateClientBilling(Request $request, User $user): JsonResponse
    {
        abort_unless($user->role === 'customer', 422, 'Only customer accounts can be updated from Manage Services.');

        $validated = $request->validate([
            'company' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'address' => ['nullable', 'string', 'max:255'],
            'tin' => ['nullable', 'string', 'max:50'],
            'mobileNumber' => ['nullable', 'string', 'max:30'],
        ]);

        $user->update([
            'company' => $validated['company'] ?? null,
            'email' => strtolower($validated['email']),
            'address' => $validated['address'] ?? null,
            'tin' => $validated['tin'] ?? null,
            'mobile_number' => $validated['mobileNumber'] ?? null,
        ]);

        PortalNotification::create([
            'user_id' => $user->id,
            'title' => 'Billing details updated',
            'message' => 'Your billing contact information was updated by the admin team.',
            'type' => 'info',
        ]);

        return response()->json([
            'message' => 'Client billing details updated successfully.',
            'client' => [
                'id' => (string) $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'company' => $user->company ?? '—',
                'address' => $user->address,
                'mobileNumber' => $user->mobile_number,
                'tin' => $user->tin,
                'profilePhotoUrl' => $user->profile_photo_url,
                'joinedAt' => $user->created_at?->toISOString(),
                'services' => $user->customerServices()->count(),
                'status' => $user->registration_status === 'rejected'
                    ? 'Rejected'
                    : ($user->registration_status === 'pending'
                        ? 'Pending Approval'
                        : ($user->customerServices()->count() > 0 ? 'Active' : 'Approved')),
                'registrationApproval' => PortalFormatter::registrationApproval($user->fresh('registrationReviewer')),
                'profileUpdateRequest' => PortalFormatter::profileUpdateRequest($user->fresh('latestProfileUpdateRequest.reviewer')->latestProfileUpdateRequest),
            ],
        ]);
    }

    public function updateAdminUser(Request $request, User $user): JsonResponse
    {
        $this->ensureInternalUser($user);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'role' => ['required', Rule::in(self::INTERNAL_USER_ROLES)],
        ]);

        if ((int) $request->user()->id === (int) $user->id && $validated['role'] !== 'admin') {
            return response()->json(['message' => 'You cannot remove your own admin access.'], 422);
        }

        $user->update([
            'name' => $validated['name'],
            'email' => strtolower($validated['email']),
            'role' => $validated['role'],
        ]);

        return response()->json([
            'message' => 'User details updated successfully.',
            'user' => PortalFormatter::adminUser($user->fresh()),
        ]);
    }

    public function resetAdminUserPassword(Request $request, User $user): JsonResponse
    {
        $this->ensureInternalUser($user);

        $validated = $request->validate([
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user->update([
            'password' => Hash::make($validated['password']),
        ]);

        $user->tokens()->delete();

        return response()->json([
            'message' => 'Password reset successfully. The user must sign in again.',
        ]);
    }

    public function toggleAdminUserStatus(Request $request, User $user): JsonResponse
    {
        $this->ensureInternalUser($user);

        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);

        if ((int) $request->user()->id === (int) $user->id && ! $validated['enabled']) {
            return response()->json(['message' => 'You cannot disable your own admin account.'], 422);
        }

        $user->update([
            'is_enabled' => $validated['enabled'],
        ]);

        if (! $validated['enabled']) {
            $user->tokens()->delete();
        }

        return response()->json([
            'message' => $validated['enabled']
                ? 'User account enabled successfully.'
                : 'User account disabled successfully.',
            'user' => PortalFormatter::adminUser($user->fresh()),
        ]);
    }

    public function updateServiceStatus(Request $request, CustomerService $customerService): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'string'],
        ]);

        $mappedStatus = PortalFormatter::ADMIN_STATUS_MAP[$validated['status']] ?? null;

        if (! $mappedStatus) {
            return response()->json(['message' => 'Unsupported service status.'], 400);
        }

        $customerService->update([
            'status' => $mappedStatus,
        ]);

        PortalNotification::create([
            'user_id' => $customerService->user_id,
            'title' => 'Service status updated',
            'message' => $customerService->name.' is now marked as '.$validated['status'].'.',
            'type' => $mappedStatus === 'active' ? 'success' : ($mappedStatus === 'unpaid' ? 'warning' : 'info'),
        ]);

        return response()->json(PortalFormatter::customerService($customerService->fresh()));
    }

    public function requestServiceCancellation(Request $request, CustomerService $customerService): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($customerService->status === 'expired') {
            return response()->json(['message' => 'Expired services cannot be queued for cancellation.'], 422);
        }

        if ($customerService->cancellation_status === 'pending') {
            return response()->json(['message' => 'This service already has a pending cancellation request.'], 422);
        }

        $customerService->update([
            'cancellation_status' => 'pending',
            'cancellation_reason' => $validated['reason'] ?? null,
            'cancellation_requested_at' => now(),
            'cancellation_reviewed_by' => null,
            'cancellation_reviewed_at' => null,
        ]);

        PortalNotification::create([
            'user_id' => $customerService->user_id,
            'title' => 'Service cancellation queued',
            'message' => $customerService->name.' has been queued for admin cancellation review.',
            'type' => 'warning',
        ]);

        return response()->json([
            'message' => 'Service cancellation queued successfully.',
            'service' => [
                ...PortalFormatter::customerService($customerService->fresh('cancellationReviewer')),
                'client' => $customerService->user->name,
                'clientEmail' => $customerService->user->email,
            ],
        ]);
    }

    public function approveServiceCancellation(Request $request, CustomerService $customerService): JsonResponse
    {
        if ($customerService->cancellation_status !== 'pending') {
            return response()->json(['message' => 'Only pending cancellation requests can be approved.'], 422);
        }

        DB::transaction(function () use ($request, $customerService) {
            $customerService->update([
                'status' => 'expired',
                'cancellation_status' => 'approved',
                'cancellation_reviewed_by' => $request->user()->id,
                'cancellation_reviewed_at' => now(),
            ]);

            $customerService->orderItem?->update([
                'provisioning_status' => 'expired',
            ]);

            PortalNotification::create([
                'user_id' => $customerService->user_id,
                'title' => 'Service cancellation approved',
                'message' => $customerService->name.' has been cancelled by the admin team.',
                'type' => 'success',
            ]);
        });

        $customerService->load(['user', 'cancellationReviewer']);

        return response()->json([
            'message' => 'Service cancellation approved successfully.',
            'service' => [
                ...PortalFormatter::customerService($customerService),
                'client' => $customerService->user->name,
                'clientEmail' => $customerService->user->email,
            ],
        ]);
    }

    public function rejectServiceCancellation(Request $request, CustomerService $customerService): JsonResponse
    {
        if ($customerService->cancellation_status !== 'pending') {
            return response()->json(['message' => 'Only pending cancellation requests can be reviewed.'], 422);
        }

        $customerService->update([
            'cancellation_status' => 'rejected',
            'cancellation_reviewed_by' => $request->user()->id,
            'cancellation_reviewed_at' => now(),
        ]);

        PortalNotification::create([
            'user_id' => $customerService->user_id,
            'title' => 'Service cancellation declined',
            'message' => $customerService->name.' will remain active after admin review.',
            'type' => 'info',
        ]);

        $customerService->load(['user', 'cancellationReviewer']);

        return response()->json([
            'message' => 'Service cancellation request declined.',
            'service' => [
                ...PortalFormatter::customerService($customerService),
                'client' => $customerService->user->name,
                'clientEmail' => $customerService->user->email,
            ],
        ]);
    }

    public function approveOrder(PortalOrder $portalOrder): JsonResponse
    {
        if ($portalOrder->status !== 'pending_review') {
            return response()->json(['message' => 'Only pending review orders can be approved.'], 422);
        }

        $portalOrder->loadMissing(['user', 'items.customerService']);

        DB::transaction(function () use ($portalOrder) {
            $portalOrder->update([
                'status' => 'paid',
            ]);

            foreach ($portalOrder->items as $item) {
                if ($item->customerService) {
                    continue;
                }

                CustomerService::create([
                    'user_id' => $portalOrder->user_id,
                    'service_id' => $item->service_id,
                    'order_item_id' => $item->id,
                    'name' => $item->service_name,
                    'category' => $item->category,
                    'plan' => $item->configuration,
                    'status' => 'undergoing_provisioning',
                    'renews_on' => $this->renewalDate($item->billing_cycle),
                ]);
            }

            PortalNotification::create([
                'user_id' => $portalOrder->user_id,
                'title' => 'Order approved',
                'message' => 'Your order '.$portalOrder->order_number.' has been approved and is now being processed.',
                'type' => 'success',
            ]);
        });

        return response()->json([
            'message' => 'Order approved successfully.',
            'order' => [
                ...PortalFormatter::order($portalOrder->fresh('items', 'user')),
                'client' => $portalOrder->user->name,
            ],
        ]);
    }

    public function approveProfileUpdateRequest(Request $request, ProfileUpdateRequest $profileUpdateRequest): JsonResponse
    {
        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($profileUpdateRequest->status !== 'pending') {
            return response()->json(['message' => 'Only pending profile update requests can be approved.'], 422);
        }

        DB::transaction(function () use ($request, $profileUpdateRequest, $validated) {
            $customer = User::query()->lockForUpdate()->findOrFail($profileUpdateRequest->user_id);

            $customer->update([
                'name' => $profileUpdateRequest->name,
                'email' => strtolower($profileUpdateRequest->email),
                'company' => $profileUpdateRequest->company,
                'address' => $profileUpdateRequest->address,
                'mobile_number' => $profileUpdateRequest->mobile_number,
                'profile_photo_url' => $profileUpdateRequest->profile_photo_url,
            ]);

            $profileUpdateRequest->update([
                'status' => 'approved',
                'admin_notes' => $validated['note'] ?? null,
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
            ]);

            $notificationMessage = 'Your profile update request has been approved by the admin.';

            if (! empty($validated['note'])) {
                $notificationMessage .= ' Admin note: '.$validated['note'];
            }

            PortalNotification::create([
                'user_id' => $customer->id,
                'title' => 'Profile update approved',
                'message' => $notificationMessage,
                'type' => 'success',
            ]);
        });

        return response()->json([
            'message' => 'Profile update request approved successfully.',
            'request' => PortalFormatter::profileUpdateRequest($profileUpdateRequest->fresh()->load('reviewer')),
        ]);
    }

    public function approveClientRegistration(Request $request, User $user): JsonResponse
    {
        abort_unless($user->role === 'customer', 422, 'Only customer accounts can be approved from Clients.');

        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($user->registration_status !== 'pending') {
            return response()->json(['message' => 'Only pending registrations can be approved.'], 422);
        }

        DB::transaction(function () use ($request, $user, $validated) {
            $user->update([
                'registration_status' => 'approved',
                'registration_admin_notes' => $validated['note'] ?? null,
                'registration_reviewed_by' => $request->user()->id,
                'registration_reviewed_at' => now(),
                'is_enabled' => true,
            ]);

            $message = 'Your portal registration has been approved by the admin. You can now sign in.';

            if (! empty($validated['note'])) {
                $message .= ' Admin note: '.$validated['note'];
            }

            PortalNotification::create([
                'user_id' => $user->id,
                'title' => 'Registration approved',
                'message' => $message,
                'type' => 'success',
            ]);

            $this->sendRegistrationDecisionEmail($user->fresh('registrationReviewer'), true, $validated['note'] ?? null);
        });

        return response()->json([
            'message' => 'Customer registration approved successfully.',
            'client' => PortalFormatter::sanitizeUser($user->fresh()->load('registrationReviewer', 'latestProfileUpdateRequest.reviewer')),
        ]);
    }

    public function rejectClientRegistration(Request $request, User $user): JsonResponse
    {
        abort_unless($user->role === 'customer', 422, 'Only customer accounts can be rejected from Clients.');

        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($user->registration_status !== 'pending') {
            return response()->json(['message' => 'Only pending registrations can be rejected.'], 422);
        }

        DB::transaction(function () use ($request, $user, $validated) {
            $user->update([
                'registration_status' => 'rejected',
                'registration_admin_notes' => $validated['note'] ?? null,
                'registration_reviewed_by' => $request->user()->id,
                'registration_reviewed_at' => now(),
                'is_enabled' => false,
            ]);

            $message = 'Your portal registration has been rejected by the admin.';

            if (! empty($validated['note'])) {
                $message .= ' Admin note: '.$validated['note'];
            }

            PortalNotification::create([
                'user_id' => $user->id,
                'title' => 'Registration rejected',
                'message' => $message,
                'type' => 'danger',
            ]);

            $this->sendRegistrationDecisionEmail($user->fresh('registrationReviewer'), false, $validated['note'] ?? null);
        });

        return response()->json([
            'message' => 'Customer registration rejected successfully.',
            'client' => PortalFormatter::sanitizeUser($user->fresh()->load('registrationReviewer', 'latestProfileUpdateRequest.reviewer')),
        ]);
    }

    public function rejectProfileUpdateRequest(Request $request, ProfileUpdateRequest $profileUpdateRequest): JsonResponse
    {
        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($profileUpdateRequest->status !== 'pending') {
            return response()->json(['message' => 'Only pending profile update requests can be rejected.'], 422);
        }

        DB::transaction(function () use ($request, $profileUpdateRequest, $validated) {
            $profileUpdateRequest->update([
                'status' => 'rejected',
                'admin_notes' => $validated['note'] ?? null,
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
            ]);

            $notificationMessage = 'Your profile update request has been rejected by the admin. Please review your details and submit again.';

            if (! empty($validated['note'])) {
                $notificationMessage .= ' Admin note: '.$validated['note'];
            }

            PortalNotification::create([
                'user_id' => $profileUpdateRequest->user_id,
                'title' => 'Profile update rejected',
                'message' => $notificationMessage,
                'type' => 'danger',
            ]);
        });

        return response()->json([
            'message' => 'Profile update request rejected successfully.',
            'request' => PortalFormatter::profileUpdateRequest($profileUpdateRequest->fresh()->load('reviewer')),
        ]);
    }

    private function ensureInternalUser(User $user): void
    {
        abort_unless(in_array($user->role, self::INTERNAL_USER_ROLES, true), 422, 'This account is not managed from the Users page.');
    }

    private function validateCatalogServiceRequest(Request $request): array
    {
        return validator($this->prepareCatalogServicePayload($request->all()), [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'category' => ['required', 'string', 'max:255'],
            'price' => ['required', 'numeric', 'min:0'],
            'billingCycle' => ['required', 'string', Rule::in(BillingCycle::values())],
            'addons' => ['required', 'array'],
            'addons.*' => ['required', 'array'],
            'addons.*.label' => ['required', 'string', 'max:255'],
            'addons.*.price' => ['required', 'numeric', 'min:0'],
            'addons.*.billingCycle' => ['nullable', 'string', Rule::in(BillingCycle::values())],
        ])->validate();
    }

    private function prepareCatalogServicePayload(array $payload): array
    {
        $serviceBillingCycle = $this->normalizeBillingCycleInput($this->billingCycleInput($payload));
        $addons = $payload['addons'] ?? [];

        if (is_array($addons)) {
            $fallbackAddonBillingCycle = BillingCycle::normalize($serviceBillingCycle);

            $addons = array_values(array_map(function ($addon) use ($fallbackAddonBillingCycle) {
                if (! is_array($addon)) {
                    return $addon;
                }

                $addonBillingCycle = $this->normalizeBillingCycleInput($this->billingCycleInput($addon));

                if ($addonBillingCycle === null) {
                    $addonBillingCycle = $fallbackAddonBillingCycle;
                }

                return [
                    ...$addon,
                    'billingCycle' => $addonBillingCycle,
                ];
            }, $addons));
        }

        return [
            ...$payload,
            'billingCycle' => $serviceBillingCycle,
            'addons' => $addons,
        ];
    }

    private function syncCatalogServiceConfiguration(Service $service, string $serviceName, ?string $previousName = null): void
    {
        $configurations = $service->configurations()->orderBy('id')->get();

        if ($configurations->isEmpty()) {
            $service->configurations()->create(['label' => $serviceName]);

            return;
        }

        $configuration = $previousName
            ? $configurations->firstWhere('label', $previousName)
            : null;

        if (! $configuration && $configurations->count() === 1) {
            $configuration = $configurations->first();
        }

        if ($configuration) {
            $configuration->update(['label' => $serviceName]);
        }
    }

    private function syncCatalogServiceAddons(Service $service, array $addons, string $serviceBillingCycle): void
    {
        $service->addons()->delete();

        $records = collect($addons)
            ->map(fn (array $addon) => [
                'label' => $addon['label'],
                'extra_price' => $addon['price'],
                'billing_cycle' => BillingCycle::addonValue($addon['billingCycle'] ?? null, $serviceBillingCycle),
            ])
            ->values();

        $service->addons()->createMany($records->isNotEmpty()
            ? $records->all()
            : [[
                'label' => 'No add-on',
                'extra_price' => 0,
                'billing_cycle' => $serviceBillingCycle,
            ]]);
    }

    private function generateUniqueServiceSlug(string $name): string
    {
        $baseSlug = Str::slug($name);

        if ($baseSlug === '') {
            $baseSlug = 'service';
        }

        $slug = $baseSlug;
        $suffix = 2;

        while (Service::query()->where('slug', $slug)->exists()) {
            $slug = $baseSlug.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }

    private function billingCycleInput(array $payload): mixed
    {
        if (array_key_exists('billingCycle', $payload)) {
            return $payload['billingCycle'];
        }

        if (array_key_exists('billing_cycle', $payload)) {
            return $payload['billing_cycle'];
        }

        return null;
    }

    private function normalizeBillingCycleInput(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value) && trim($value) === '') {
            return null;
        }

        return BillingCycle::normalize($value) ?? $value;
    }

    private function sendRegistrationDecisionEmail(User $user, bool $approved, ?string $note = null): void
    {
        try {
            $subject = $approved ? 'WSI Portal registration approved' : 'WSI Portal registration rejected';
            $body = $approved
                ? "Hello {$user->name},\n\nYour WSI portal registration has been approved. You can now sign in using your registered email address."
                : "Hello {$user->name},\n\nYour WSI portal registration has been rejected by the admin.";

            if (! empty($note)) {
                $body .= "\n\nAdmin note: {$note}";
            }

            $body .= "\n\nRegards,\nWSI Portal";

            Mail::raw($body, fn ($message) => $message->to($user->email)->subject($subject));
        } catch (\Throwable) {
        }
    }

    private function renewalDate(string $billingCycle): string
    {
        $date = now();

        return match ($billingCycle) {
            'yearly' => $date->copy()->addYear()->toDateTimeString(),
            'one_time' => $date->copy()->addDays(30)->toDateTimeString(),
            default => $date->copy()->addMonth()->toDateTimeString(),
        };
    }
}
