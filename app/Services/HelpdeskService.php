<?php

namespace App\Services;

use App\Models\CustomerService;
use App\Models\HelpdeskTicket;
use App\Models\HelpdeskTicketActivity;
use App\Models\PortalNotification;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class HelpdeskService
{
    public function createCustomerTicket(CustomerService $customerService, User $customer, array $attributes): HelpdeskTicket
    {
        $message = trim((string) ($attributes['message'] ?? ''));
        $message = $message !== '' ? $message : 'Customer reports the service is not functioning.';

        $title = trim((string) ($attributes['title'] ?? ''));
        $title = $title !== '' ? $title : 'Issue with '.$customerService->name;

        $category = trim((string) ($attributes['category'] ?? ''));
        $category = $category !== '' ? $category : 'Technical';

        $priority = $attributes['priority'] ?? 'Normal';

        return DB::transaction(function () use ($customerService, $customer, $message, $title, $category, $priority) {
            $ticket = HelpdeskTicket::query()->create([
                'service_id' => $customerService->id,
                'customer_id' => $customer->id,
                'title' => $title,
                'message' => $message,
                'category' => $category,
                'status' => 'Open',
                'assigned_to_user_id' => null,
                'priority' => $priority,
                'source' => 'customer_portal',
                'reference_number' => null,
            ]);

            $ticket->update([
                'reference_number' => $this->referenceNumber($ticket->id),
            ]);

            $ticket->refresh();

            $this->recordActivity(
                $ticket,
                $customer,
                HelpdeskTicketActivity::ACTION_TICKET_CREATED,
                null,
                [
                    'reference' => $ticket->reference_number,
                    'status' => $ticket->status,
                    'priority' => $ticket->priority,
                ],
            );

            $this->notifyCustomerTicketCreated($ticket, $customer, $customerService);
            $this->notifyInternalUsersTicketCreated($ticket, $customer, $customerService);

            return $this->freshTicket($ticket, true);
        });
    }

    public function updateTicket(HelpdeskTicket $ticket, array $attributes, User $actor): HelpdeskTicket
    {
        return DB::transaction(function () use ($ticket, $attributes, $actor) {
            $ticket->loadMissing(['customer', 'customerService', 'assignedTo']);

            $assignmentChanged = false;
            $statusChanged = false;
            $priorityChanged = false;
            $previousAssignedUser = $ticket->assignedTo;
            $previousStatus = $ticket->status;
            $previousPriority = $ticket->priority;

            if (array_key_exists('assigned_to_user_id', $attributes)) {
                $newAssignedUserId = $attributes['assigned_to_user_id'];

                if ((string) ($ticket->assigned_to_user_id ?? '') !== (string) ($newAssignedUserId ?? '')) {
                    $ticket->assigned_to_user_id = $newAssignedUserId;
                    $assignmentChanged = true;
                }
            }

            if (array_key_exists('status', $attributes) && $ticket->status !== $attributes['status']) {
                $ticket->status = $attributes['status'];
                $this->applyStatusTimestamps($ticket);
                $statusChanged = true;
            }

            if (array_key_exists('priority', $attributes) && $ticket->priority !== $attributes['priority']) {
                $ticket->priority = $attributes['priority'];
                $priorityChanged = true;
            }

            if ($ticket->isDirty()) {
                $ticket->save();
            } else {
                $ticket->touch();
            }

            $ticket->load(['customer', 'customerService', 'assignedTo']);

            if ($assignmentChanged) {
                $this->recordActivity(
                    $ticket,
                    $actor,
                    HelpdeskTicketActivity::ACTION_ASSIGNMENT_CHANGED,
                    $this->userPayload($previousAssignedUser),
                    $this->userPayload($ticket->assignedTo),
                );
            }

            if ($statusChanged) {
                $this->recordActivity(
                    $ticket,
                    $actor,
                    HelpdeskTicketActivity::ACTION_STATUS_CHANGED,
                    ['status' => $previousStatus],
                    ['status' => $ticket->status],
                );
            }

            if ($priorityChanged) {
                $this->recordActivity(
                    $ticket,
                    $actor,
                    HelpdeskTicketActivity::ACTION_PRIORITY_CHANGED,
                    ['priority' => $previousPriority],
                    ['priority' => $ticket->priority],
                );
            }

            $internalNote = trim((string) ($attributes['internal_note'] ?? ''));

            if ($internalNote !== '') {
                $this->recordActivity(
                    $ticket,
                    $actor,
                    HelpdeskTicketActivity::ACTION_NOTE_ADDED,
                    null,
                    null,
                    $internalNote,
                );
            }

            if ($assignmentChanged && $ticket->assignedTo) {
                $this->notifyAssignedUser($ticket, $ticket->assignedTo, $actor);
            }

            if ($statusChanged) {
                $this->notifyCustomerStatusChanged($ticket);
            }

            return $this->freshTicket($ticket, true);
        });
    }

    private function freshTicket(HelpdeskTicket $ticket, bool $withActivities = false): HelpdeskTicket
    {
        $relations = ['customer', 'customerService', 'assignedTo'];

        if ($withActivities) {
            $relations[] = 'activities.actor';
        }

        return $ticket->fresh()->load($relations);
    }

    private function referenceNumber(int $ticketId): string
    {
        return 'T-'.str_pad((string) $ticketId, 6, '0', STR_PAD_LEFT);
    }

    private function applyStatusTimestamps(HelpdeskTicket $ticket): void
    {
        if ($ticket->status === 'Resolved') {
            $ticket->resolved_at = now();
            $ticket->closed_at = null;

            return;
        }

        if ($ticket->status === 'Closed') {
            $ticket->closed_at = now();

            return;
        }

        $ticket->resolved_at = null;
        $ticket->closed_at = null;
    }

    private function recordActivity(
        HelpdeskTicket $ticket,
        ?User $actor,
        string $action,
        ?array $oldValue = null,
        ?array $newValue = null,
        ?string $note = null,
    ): void {
        $ticket->activities()->create([
            'actor_user_id' => $actor?->id,
            'action' => $action,
            'old_value' => $oldValue,
            'new_value' => $newValue,
            'note' => $note,
            'created_at' => now(),
        ]);
    }

    private function notifyCustomerTicketCreated(HelpdeskTicket $ticket, User $customer, CustomerService $customerService): void
    {
        PortalNotification::query()->create([
            'user_id' => $customer->id,
            'title' => 'Support ticket created',
            'message' => 'Your ticket '.$ticket->reference_number.' for "'.$customerService->name.'" has been created and is now Open.',
            'type' => 'info',
        ]);
    }

    private function notifyInternalUsersTicketCreated(HelpdeskTicket $ticket, User $customer, CustomerService $customerService): void
    {
        User::query()
            ->where('is_enabled', true)
            ->whereIn('role', HelpdeskTicket::ASSIGNABLE_ROLES)
            ->get()
            ->each(function (User $user) use ($ticket, $customer, $customerService) {
                PortalNotification::query()->create([
                    'user_id' => $user->id,
                    'title' => 'New helpdesk ticket',
                    'message' => $customer->name.' created '.$ticket->reference_number.' for "'.$customerService->name.'": '.$ticket->title,
                    'type' => 'danger',
                ]);
            });
    }

    private function notifyAssignedUser(HelpdeskTicket $ticket, User $assignedUser, User $actor): void
    {
        PortalNotification::query()->create([
            'user_id' => $assignedUser->id,
            'title' => 'Helpdesk ticket assigned',
            'message' => $actor->name.' assigned '.$ticket->reference_number.' to you.',
            'type' => 'info',
        ]);
    }

    private function notifyCustomerStatusChanged(HelpdeskTicket $ticket): void
    {
        $type = in_array($ticket->status, ['Resolved', 'Closed'], true) ? 'success' : 'info';

        PortalNotification::query()->create([
            'user_id' => $ticket->customer_id,
            'title' => 'Support ticket updated',
            'message' => 'Your ticket '.$ticket->reference_number.' is now '.$ticket->status.'.',
            'type' => $type,
        ]);
    }

    private function userPayload(?User $user): ?array
    {
        if (! $user) {
            return null;
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'role' => $user->role,
        ];
    }
}
