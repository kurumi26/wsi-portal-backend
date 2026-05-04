<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\InvoiceProof;
use App\Models\Payment;
use App\Support\PortalFormatter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InvoiceController extends Controller
{
    public function mine(Request $request): JsonResponse
    {
        $invoices = Invoice::query()
            ->where('user_id', $request->user()->id)
            ->with(['order', 'proofs'])
            ->latest()
            ->get()
            ->map(fn (Invoice $i) => PortalFormatter::invoice($i))
            ->values();

        return response()->json($invoices);
    }

    public function show(Request $request, Invoice $invoice): JsonResponse
    {
        abort_unless($request->user()->id === $invoice->user_id || $request->user()->role !== 'customer', 403);

        $invoice->load(['order', 'proofs']);

        return response()->json(PortalFormatter::invoice($invoice));
    }

    public function index(Request $request): JsonResponse
    {
        $invoices = Invoice::query()
            ->with(['order', 'proofs', 'user'])
            ->latest()
            ->get()
            ->map(fn (Invoice $i) => PortalFormatter::invoice($i))
            ->values();

        return response()->json($invoices);
    }

    public function markPaid(Request $request, Invoice $invoice): JsonResponse
    {
        $validated = $request->validate([
            'paymentReference' => ['nullable', 'string', 'max:255'],
            'paidAt' => ['nullable', 'date'],
            'method' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        DB::transaction(function () use ($invoice, $validated, $request) {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);

            if ($invoice->paid_at) {
                return;
            }

            // create payment record if linked order exists
            if ($invoice->portal_order_id) {
                Payment::create([
                    'portal_order_id' => $invoice->portal_order_id,
                    'amount' => $invoice->total_amount,
                    'method' => $validated['method'] ?? 'manual',
                    'status' => 'success',
                    'transaction_ref' => $validated['paymentReference'] ?? null,
                ]);
            }

            $invoice->update([
                'status' => 'paid',
                'paid_at' => $validated['paidAt'] ?? now(),
                'paid_by' => $request->user()->id,
                'payment_reference' => $validated['paymentReference'] ?? null,
                'internal_note' => $validated['note'] ?? null,
            ]);
        });

        $invoice->load(['order', 'proofs']);

        return response()->json(PortalFormatter::invoice($invoice->fresh()));
    }

    public function uploadProof(Request $request, Invoice $invoice): JsonResponse
    {
        $validated = $request->validate([
            'proof' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:10240'],
        ]);

        $file = $request->file('proof');
        $path = $file->store('invoice_proofs', 'public');

        $proof = InvoiceProof::create([
            'invoice_id' => $invoice->id,
            'path' => $path,
            'uploaded_by' => $request->user()->id,
            'uploaded_at' => now(),
            'review_status' => 'pending',
        ]);

        // notify admins
        \App\Models\User::query()
            ->whereIn('role', ['admin', 'billing', 'technical_support'])
            ->get()
            ->each(function ($admin) use ($invoice, $request) {
                \App\Models\PortalNotification::create([
                    'user_id' => $admin->id,
                    'title' => 'Invoice proof uploaded',
                    'message' => $request->user()->name.' uploaded proof for invoice '.$invoice->invoice_number,
                    'type' => 'info',
                ]);
            });

        return response()->json(['message' => 'Proof uploaded.', 'proof' => $proof], 201);
    }
}
