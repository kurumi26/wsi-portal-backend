<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CustomerService;
use App\Models\PortalNotification;
use App\Models\PortalOrder;
use App\Models\Service;
use App\Support\PortalFormatter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Models\User;

class CustomerPortalController extends Controller
{
    public function orders(Request $request): JsonResponse
    {
        $orders = PortalOrder::query()
            ->where('user_id', $request->user()->id)
            ->with('items')
            ->latest()
            ->get()
            ->map(fn (PortalOrder $order) => PortalFormatter::order($order))
            ->values();

        return response()->json($orders);
    }

    public function services(Request $request): JsonResponse
    {
        $services = CustomerService::query()
            ->where('user_id', $request->user()->id)
            ->latest()
            ->get()
            ->map(fn (CustomerService $service) => PortalFormatter::customerService($service))
            ->values();

        return response()->json($services);
    }

    public function notifications(Request $request): JsonResponse
    {
        $notifications = PortalNotification::query()
            ->where('user_id', $request->user()->id)
            ->latest()
            ->get()
            ->map(fn (PortalNotification $notification) => PortalFormatter::notification($notification))
            ->values();

        return response()->json($notifications);
    }

    public function updateNotification(Request $request, PortalNotification $notification): JsonResponse
    {
        abort_unless($notification->user_id === $request->user()->id, 403);

        $validated = $request->validate([
            'isRead' => ['required', 'boolean'],
        ]);

        $notification->update([
            'is_read' => $validated['isRead'],
        ]);

        return response()->json(PortalFormatter::notification($notification->fresh()));
    }

    public function markAllNotificationsRead(Request $request): JsonResponse
    {
        PortalNotification::query()
            ->where('user_id', $request->user()->id)
            ->update(['is_read' => true]);

        return response()->json(['message' => 'All notifications marked as read.']);
    }

    public function destroyNotification(Request $request, PortalNotification $notification): JsonResponse
    {
        abort_unless($notification->user_id === $request->user()->id, 403);

        $notification->delete();

        return response()->json(['message' => 'Notification dismissed.']);
    }

    public function reportServiceIssue(Request $request, CustomerService $customerService): JsonResponse
    {
        abort_unless($customerService->user_id === $request->user()->id, 403);

        $validated = $request->validate([
            'message' => ['nullable', 'string', 'max:2000'],
        ]);

        $message = $validated['message'] ?? 'Customer reports the service is not functioning.';

        // Notify the customer that their report was received
        PortalNotification::create([
            'user_id' => $request->user()->id,
            'title' => 'Service issue reported',
            'message' => 'We received your report: '.$message,
            'type' => 'info',
        ]);

        // Notify admins and technical support users
        User::query()
            ->whereIn('role', ['admin', 'technical_support'])
            ->get()
            ->each(function (User $admin) use ($customerService, $request, $message) {
                PortalNotification::create([
                    'user_id' => $admin->id,
                    'title' => 'Service reported as not functioning',
                    'message' => $request->user()->name.' reported an issue for service "'.$customerService->name.'": '.$message,
                    'type' => 'danger',
                ]);
            });

        return response()->json(['message' => 'Issue reported. Support will review this shortly.']);
    }

    public function requestServiceCancellation(Request $request, CustomerService $customerService): JsonResponse
    {
        abort_unless($customerService->user_id === $request->user()->id, 403);

        if ($customerService->cancellation_status === 'pending') {
            return response()->json(['message' => 'A cancellation request is already pending for this service.'], 400);
        }

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $reason = $validated['reason'] ?? null;

        $customerService->update([
            'cancellation_status' => 'pending',
            'cancellation_reason' => $reason,
            'cancellation_requested_at' => now(),
        ]);

        // Notify customer that their request was received
        PortalNotification::create([
            'user_id' => $request->user()->id,
            'title' => 'Cancellation requested',
            'message' => 'Your cancellation request for "'.$customerService->name.'" has been submitted and is pending admin approval.',
            'type' => 'info',
        ]);

        // Notify admins and technical support
        User::query()
            ->whereIn('role', ['admin', 'technical_support'])
            ->get()
            ->each(function (User $admin) use ($customerService, $request, $reason) {
                PortalNotification::create([
                    'user_id' => $admin->id,
                    'title' => 'Cancellation request submitted',
                    'message' => $request->user()->name.' requested cancellation for "'.$customerService->name.'"'.($reason ? ': '.$reason : ''),
                    'type' => 'info',
                ]);
            });

        return response()->json(['message' => 'Cancellation request submitted.']);
    }

    public function checkout(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'cart' => ['required', 'array', 'min:1'],
            'cart.*.serviceId' => ['required', 'integer', 'exists:services,id'],
            'cart.*.serviceName' => ['required', 'string'],
            'cart.*.category' => ['required', 'string'],
            'cart.*.configuration' => ['required', 'string'],
            'cart.*.addon' => ['nullable', 'string'],
            'cart.*.note' => ['nullable', 'string', 'max:1000'],
            'cart.*.price' => ['required', 'numeric'],
            'paymentMethod' => ['required', 'string'],
            'note' => ['nullable', 'string', 'max:1000'],
            'agreementAccepted' => ['required', 'boolean'],
        ]);

        if (! $validated['agreementAccepted']) {
            return response()->json(['message' => 'Please accept the agreement, terms, and privacy policy.'], 400);
        }

        // Create pending orders for admin review instead of immediately processing payments
        $orders = DB::transaction(function () use ($request, $validated) {
            $serviceMap = Service::query()
                ->whereIn('id', collect($validated['cart'])->pluck('serviceId'))
                ->get()
                ->keyBy('id');

            return collect($validated['cart'])->map(function (array $item) use ($request, $validated, $serviceMap) {
                $service = $serviceMap->get($item['serviceId']);
                $customerNote = $item['note'] ?? $validated['note'] ?? null;

                $order = PortalOrder::create([
                    'order_number' => $this->generateOrderNumber(),
                    'user_id' => $request->user()->id,
                    'total_amount' => $item['price'],
                    'payment_method' => $validated['paymentMethod'],
                    'customer_note' => $customerNote,
                    'agreement_accepted' => true,
                    'terms_accepted' => true,
                    'privacy_accepted' => true,
                    // mark as pending review so admins can approve
                    'status' => 'pending_review',
                ]);

                $orderItem = $order->items()->create([
                    'service_id' => $service->id,
                    'service_name' => $item['serviceName'],
                    'category' => $item['category'],
                    'configuration' => $item['configuration'],
                    'addon' => $item['addon'] ?? null,
                    'customer_note' => $customerNote,
                    'price' => $item['price'],
                    'billing_cycle' => $service->billing_cycle,
                    'provisioning_status' => 'pending_review',
                ]);

                return $order->load('items');
            });
        });

        // Notify customer that order was submitted for review
        PortalNotification::create([
            'user_id' => $request->user()->id,
            'title' => 'Order submitted',
            'message' => $orders->count().' order(s) submitted for admin review.',
            'type' => 'info',
        ]);

        // Notify admins to review the new orders
        User::query()
            ->whereIn('role', ['admin', 'technical_support', 'sales'])
            ->get()
            ->each(function (User $admin) use ($request, $orders) {
                PortalNotification::create([
                    'user_id' => $admin->id,
                    'title' => 'New customer order submitted',
                    'message' => $request->user()->name.' submitted '.$orders->count().' new order(s) requiring review.',
                    'type' => 'info',
                ]);
            });

        return response()->json([
            'message' => 'Order submitted for admin review.',
            'orders' => $orders->map(fn (PortalOrder $order) => PortalFormatter::order($order))->values(),
        ], 201);
    }

    public function uploadPaymentProof(Request $request, PortalOrder $portalOrder): JsonResponse
    {
        abort_unless($portalOrder->user_id === $request->user()->id, 403);

        $validated = $request->validate([
            'proof' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:10240'],
        ]);

        $file = $request->file('proof');
        $path = $file->store('payment_proofs', 'public');

        // Create a payment record referencing the uploaded proof
        // Use 'pending' status for uploaded proofs so it matches the payments enum
        $payment = \App\Models\Payment::create([
            'portal_order_id' => $portalOrder->id,
            'amount' => $portalOrder->total_amount,
            'method' => 'bank_transfer',
            'status' => 'pending',
            'transaction_ref' => $path,
        ]);

        // Notify admins and billing team
        PortalNotification::create([
            'user_id' => $request->user()->id,
            'title' => 'Payment proof uploaded',
            'message' => 'We received your proof of payment for order '.$portalOrder->order_number.'. Billing will review and confirm.',
            'type' => 'info',
        ]);

        User::query()
            ->whereIn('role', ['admin', 'billing', 'technical_support'])
            ->get()
            ->each(function (User $admin) use ($portalOrder, $request) {
                PortalNotification::create([
                    'user_id' => $admin->id,
                    'title' => 'Payment proof submitted',
                    'message' => $request->user()->name.' uploaded proof for order '.$portalOrder->order_number.'. Please review and accept.',
                    'type' => 'info',
                ]);
            });

        // Mark the order as pending review so provisioning does not proceed until admin approves
        $portalOrder->update(['status' => 'pending_review']);

        return response()->json(['message' => 'Proof uploaded.', 'payment' => $payment]);
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

    private function generateOrderNumber(): string
    {
        do {
            $number = 'WSI-'.random_int(100000, 999999);
        } while (PortalOrder::where('order_number', $number)->exists());

        return $number;
    }
}
