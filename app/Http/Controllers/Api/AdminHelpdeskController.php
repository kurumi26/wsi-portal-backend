<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\HelpdeskTicket;
use App\Services\HelpdeskService;
use App\Support\PortalFormatter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminHelpdeskController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = validator($this->filterPayload($request), [
            'status' => ['nullable', 'string', Rule::in(HelpdeskTicket::STATUSES)],
            'assigned_to_user_id' => ['nullable', 'integer'],
            'category' => ['nullable', 'string', 'max:255'],
            'customer_id' => ['nullable', 'integer'],
            'service_id' => ['nullable', 'integer'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'search' => ['nullable', 'string', 'max:255'],
        ])->validate();

        $tickets = HelpdeskTicket::query()
            ->with(['customer', 'customerService', 'assignedTo'])
            ->when(isset($validated['status']), fn ($query) => $query->where('status', $validated['status']))
            ->when(array_key_exists('assigned_to_user_id', $validated), fn ($query) => $query->where('assigned_to_user_id', $validated['assigned_to_user_id']))
            ->when(isset($validated['category']), fn ($query) => $query->where('category', $validated['category']))
            ->when(isset($validated['customer_id']), fn ($query) => $query->where('customer_id', $validated['customer_id']))
            ->when(isset($validated['service_id']), fn ($query) => $query->where('service_id', $validated['service_id']))
            ->when(isset($validated['date_from']), fn ($query) => $query->whereDate('created_at', '>=', $validated['date_from']))
            ->when(isset($validated['date_to']), fn ($query) => $query->whereDate('created_at', '<=', $validated['date_to']))
            ->when(isset($validated['search']), function ($query) use ($validated) {
                $term = '%'.$validated['search'].'%';

                $query->where(function ($searchQuery) use ($term) {
                    $searchQuery
                        ->where('reference_number', 'like', $term)
                        ->orWhere('title', 'like', $term)
                        ->orWhere('message', 'like', $term)
                        ->orWhereHas('customer', function ($customerQuery) use ($term) {
                            $customerQuery
                                ->where('name', 'like', $term)
                                ->orWhere('email', 'like', $term);
                        })
                        ->orWhereHas('customerService', fn ($serviceQuery) => $serviceQuery->where('name', 'like', $term));
                });
            })
            ->latest()
            ->get()
            ->map(fn (HelpdeskTicket $ticket) => PortalFormatter::helpdeskTicket($ticket))
            ->values();

        return response()->json($tickets);
    }

    public function show(HelpdeskTicket $helpdeskTicket): JsonResponse
    {
        return response()->json(
            PortalFormatter::helpdeskTicket($helpdeskTicket->load(['customer', 'customerService', 'assignedTo', 'activities.actor']), true)
        );
    }

    public function update(Request $request, HelpdeskTicket $helpdeskTicket, HelpdeskService $helpdeskService): JsonResponse
    {
        $payload = $this->updatePayload($request);

        $validator = validator($payload, [
            'assigned_to_user_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('users', 'id')->where(fn ($query) => $query
                    ->where('is_enabled', true)
                    ->whereIn('role', HelpdeskTicket::ASSIGNABLE_ROLES)),
            ],
            'status' => ['sometimes', 'string', Rule::in(HelpdeskTicket::STATUSES)],
            'priority' => ['sometimes', 'string', Rule::in(HelpdeskTicket::PRIORITIES)],
            'internal_note' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ]);

        $validator->after(function ($validator) use ($payload): void {
            if ($payload === []) {
                $validator->errors()->add('payload', 'Provide at least one field to update.');
            }
        });

        $validated = $validator->validate();

        $ticket = $helpdeskService->updateTicket($helpdeskTicket, $validated, $request->user());

        return response()->json([
            'message' => 'Helpdesk ticket updated successfully.',
            'ticket' => PortalFormatter::helpdeskTicket($ticket, true),
        ]);
    }

    private function filterPayload(Request $request): array
    {
        return array_filter([
            'status' => $request->query('status'),
            'assigned_to_user_id' => $request->query('assigned_to_user_id', $request->query('assignedToUserId')),
            'category' => $request->query('category'),
            'customer_id' => $request->query('customer_id', $request->query('customerId')),
            'service_id' => $request->query('service_id', $request->query('serviceId')),
            'date_from' => $request->query('date_from', $request->query('dateFrom')),
            'date_to' => $request->query('date_to', $request->query('dateTo')),
            'search' => $request->query('search'),
        ], static fn ($value) => $value !== null && $value !== '');
    }

    private function updatePayload(Request $request): array
    {
        $data = $request->all();
        $payload = [];

        if (array_key_exists('assigned_to_user_id', $data) || array_key_exists('assignedToUserId', $data)) {
            $payload['assigned_to_user_id'] = $data['assigned_to_user_id'] ?? $data['assignedToUserId'] ?? null;
        }

        if (array_key_exists('status', $data)) {
            $payload['status'] = $data['status'];
        }

        if (array_key_exists('priority', $data)) {
            $payload['priority'] = $data['priority'];
        }

        if (array_key_exists('internal_note', $data) || array_key_exists('internalNote', $data)) {
            $payload['internal_note'] = $data['internal_note'] ?? $data['internalNote'] ?? null;
        }

        return $payload;
    }
}
