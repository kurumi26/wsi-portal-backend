<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CustomerService;
use App\Models\HelpdeskTicket;
use App\Models\Invoice;
use App\Models\InvoiceProof;
use App\Models\PortalNotification;
use App\Models\PortalOrder;
use App\Models\Service;
use App\Models\User;
use App\Services\ContractService;
use App\Services\HelpdeskService;
use App\Support\PortalFormatter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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

        $notification->forceDelete();

        return response()->json(['message' => 'Notification dismissed.']);
    }

    public function reportServiceIssue(Request $request, CustomerService $customerService, HelpdeskService $helpdeskService): JsonResponse
    {
        abort_unless($customerService->user_id === $request->user()->id, 403);

        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'message' => ['nullable', 'string', 'max:2000'],
            'category' => ['nullable', 'string', 'max:255'],
            'priority' => ['nullable', 'string', Rule::in(HelpdeskTicket::PRIORITIES)],
        ]);

        $ticket = $helpdeskService->createCustomerTicket($customerService, $request->user(), $validated);

        return response()->json([
            'message' => 'Issue reported. Support will review this shortly.',
            'ticket' => PortalFormatter::helpdeskTicket($ticket, true),
        ], 201);
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
            ->withRoles(['admin', 'technical_support'])
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

    public function checkout(Request $request, ContractService $contractService): JsonResponse
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
            return response()->json(['message' => 'Please accept the agreement, terms, and privacy policy before checkout.'], 422);
        }

        // Create pending orders for admin review instead of immediately processing payments
        $orders = DB::transaction(function () use ($request, $validated, $contractService) {
            $serviceIds = collect($validated['cart'])
                ->pluck('serviceId')
                ->map(fn ($id) => (int) $id)
                ->all();

            $serviceMap = Service::query()
                ->withIds($serviceIds)
                ->get()
                ->keyBy('id');

            return collect($validated['cart'])->map(function (array $item) use ($request, $validated, $serviceMap, $contractService) {
                $service = $serviceMap->get((int) $item['serviceId']);

                if (! $service instanceof Service) {
                    throw ValidationException::withMessages([
                        'cart' => ['One or more cart items reference an unavailable service.'],
                    ]);
                }

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

                // Create invoice for this order
                $invoice = Invoice::createPortalInvoice([
                    'invoice_number' => Invoice::generateInvoiceNumber(),
                    'portal_order_id' => $order->id,
                    'user_id' => $request->user()->id,
                    'client_name' => $request->user()->company ?: $request->user()->name,
                    'company_name' => $request->user()->company,
                    'subtotal' => $item['price'],
                    'discounts' => 0,
                    'total_amount' => $item['price'],
                    'status' => 'pending_review',
                    'due_date' => now()->addDays(7)->toDateString(),
                ]);

                // Link order -> invoice
                $order->update(['invoice_id' => $invoice->id]);

                $contractService->createOrderContract($order, $orderItem, $request->user(), $request);

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
            ->withRoles(['admin', 'technical_support', 'sales'])
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

        $file = $validated['proof'];

        if (! $file instanceof UploadedFile) {
            throw ValidationException::withMessages([
                'proof' => ['A valid payment proof file is required.'],
            ]);
        }

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

        // Create an invoice proof record if an invoice exists
        if ($portalOrder->invoice_id) {
            $portalOrder->invoice?->update([
                'status' => 'pending_review',
            ]);

            InvoiceProof::create([
                'invoice_id' => $portalOrder->invoice_id,
                'path' => $path,
                'uploaded_by' => $request->user()->id,
                'uploaded_at' => now(),
                'review_status' => 'pending',
            ]);
        }

        // Notify admins and billing team
        PortalNotification::create([
            'user_id' => $request->user()->id,
            'title' => 'Payment proof uploaded',
            'message' => 'We received your proof of payment for order '.$portalOrder->order_number.'. Billing will review and confirm.',
            'type' => 'info',
        ]);

        User::query()
            ->withRoles(['admin', 'billing', 'technical_support'])
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

    private function generateOrderNumber(): string
    {
        do {
            $number = 'WSI-'.random_int(100000, 999999);
        } while (PortalOrder::orderNumberExists($number));

        return $number;
    }
}
