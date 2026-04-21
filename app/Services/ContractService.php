<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\ContractAuditLog;
use App\Models\CustomerService;
use App\Models\OrderItem;
use App\Models\PortalOrder;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ContractService
{
    public function listForUser(User $user): Collection
    {
        CustomerService::query()
            ->where('user_id', $user->id)
            ->with(['orderItem.order'])
            ->get()
            ->each(fn (CustomerService $customerService) => $this->ensureServiceContract($customerService));

        return Contract::query()
            ->where('user_id', $user->id)
            ->with(['user', 'order.items', 'customerService.service', 'verifiedBy', 'signedDocumentUploader'])
            ->latest()
            ->get();
    }

    public function listForAdmin(): Collection
    {
        CustomerService::query()
            ->with(['orderItem.order'])
            ->get()
            ->each(fn (CustomerService $customerService) => $this->ensureServiceContract($customerService));

        return Contract::query()
            ->with(['user', 'order.items', 'customerService.service', 'verifiedBy', 'signedDocumentUploader'])
            ->latest()
            ->get();
    }

    public function resolveForUser(User $user, string $contractKey): Contract
    {
        $contract = $this->resolveContractKey($contractKey);

        abort_unless($contract->user_id === $user->id, 403);

        return $contract;
    }

    public function resolveForAdmin(string $contractKey): Contract
    {
        return $this->resolveContractKey($contractKey);
    }

    public function createOrderContract(PortalOrder $order, OrderItem $item, User $actor, Request $request): Contract
    {
        $timestamp = now();
        $contract = Contract::query()->firstOrNew([
            'external_key' => $this->orderExternalKey($order),
        ]);

        $status = $order->agreement_accepted
            ? Contract::STATUS_ACCEPTED
            : Contract::STATUS_PENDING_REVIEW;

        $contract->fill([
            'user_id' => $order->user_id,
            'order_id' => $order->id,
            'customer_service_id' => $item->customerService?->id,
            'scope' => Contract::SCOPE_ORDER,
            'title' => $item->service_name.' Agreement',
            'description' => 'Customer agreement bundle attached to the order record.',
            'service_name' => $item->service_name,
            'version' => 'v1.0',
            'status' => $status,
            'agreement_accepted' => (bool) $order->agreement_accepted,
            'terms_accepted' => (bool) $order->terms_accepted,
            'privacy_accepted' => (bool) $order->privacy_accepted,
            'requires_signed_document' => $this->requiresSignedDocument($order),
            'audit_reference' => 'ORDER-'.$order->order_number,
            'decision_by' => $actor->name,
            'decision_at' => $order->agreement_accepted ? $timestamp : null,
            'verification_status' => Contract::VERIFICATION_PENDING,
            'accepted_at' => $order->agreement_accepted ? $timestamp : null,
            'rejected_at' => null,
            'document_sections' => Contract::defaultDocumentSections(),
        ]);

        $created = ! $contract->exists;
        $contract->save();

        if ($created) {
            $this->audit(
                $contract,
                $actor,
                ContractAuditLog::ACTION_CREATED,
                null,
                $contract->status,
                [
                    'source' => 'customer-portal-checkout',
                    'orderNumber' => $order->order_number,
                    'paymentMethod' => $order->payment_method,
                    'agreementAccepted' => (bool) $order->agreement_accepted,
                    'termsAccepted' => (bool) $order->terms_accepted,
                    'privacyAccepted' => (bool) $order->privacy_accepted,
                ],
                $request,
            );
        }

        return $this->freshContract($contract);
    }

    public function ensureServiceContract(CustomerService $customerService): Contract
    {
        $customerService->loadMissing(['orderItem.order']);

        $serviceContract = Contract::query()->firstOrNew([
            'external_key' => $this->serviceExternalKey($customerService),
        ]);

        $sourceContract = $this->sourceContractForService($customerService);
        $order = $customerService->orderItem?->order;
        $verifiedAt = $serviceContract->verified_at ?? $sourceContract?->verified_at;
        $verificationStatus = $serviceContract->verification_status
            ?? $sourceContract?->verification_status
            ?? ($verifiedAt ? Contract::VERIFICATION_VERIFIED : Contract::VERIFICATION_PENDING);
        $status = $serviceContract->status
            ?? $sourceContract?->status
            ?? (($order?->agreement_accepted ?? false) ? Contract::STATUS_ACCEPTED : Contract::STATUS_PENDING_REVIEW);

        $serviceContract->fill([
            'user_id' => $customerService->user_id,
            'order_id' => $serviceContract->order_id ?? $sourceContract?->order_id ?? $order?->id,
            'customer_service_id' => $customerService->id,
            'scope' => Contract::SCOPE_SERVICE,
            'title' => $serviceContract->title ?? $customerService->name.' Agreement',
            'description' => $serviceContract->description ?? 'Customer agreement bundle attached to the service record.',
            'service_name' => $customerService->name,
            'version' => $serviceContract->version ?? $sourceContract?->version ?? 'v1.0',
            'status' => $status,
            'agreement_accepted' => $serviceContract->agreement_accepted ?? $sourceContract?->agreement_accepted ?? (($order?->agreement_accepted ?? false) && $status === Contract::STATUS_ACCEPTED),
            'terms_accepted' => $serviceContract->terms_accepted ?? $sourceContract?->terms_accepted ?? (($order?->terms_accepted ?? false) && $status === Contract::STATUS_ACCEPTED),
            'privacy_accepted' => $serviceContract->privacy_accepted ?? $sourceContract?->privacy_accepted ?? (($order?->privacy_accepted ?? false) && $status === Contract::STATUS_ACCEPTED),
            'requires_signed_document' => $serviceContract->requires_signed_document ?? $sourceContract?->requires_signed_document ?? ($order ? $this->requiresSignedDocument($order) : false),
            'signed_document_name' => $serviceContract->signed_document_name ?? $sourceContract?->signed_document_name,
            'signed_document_path' => $serviceContract->signed_document_path ?? $sourceContract?->signed_document_path,
            'signed_document_uploaded_at' => $serviceContract->signed_document_uploaded_at ?? $sourceContract?->signed_document_uploaded_at,
            'signed_document_uploaded_by' => $serviceContract->signed_document_uploaded_by ?? $sourceContract?->signed_document_uploaded_by,
            'download_path' => $serviceContract->download_path ?? $sourceContract?->download_path,
            'audit_reference' => 'SERVICE-'.$customerService->id,
            'decision_by' => $serviceContract->decision_by ?? $sourceContract?->decision_by,
            'decision_at' => $serviceContract->decision_at ?? $sourceContract?->decision_at,
            'verified_by' => $serviceContract->verified_by ?? $sourceContract?->verified_by,
            'verified_at' => $verifiedAt,
            'verification_status' => $verificationStatus,
            'accepted_at' => $serviceContract->accepted_at ?? $sourceContract?->accepted_at,
            'rejected_at' => $serviceContract->rejected_at ?? $sourceContract?->rejected_at,
            'document_sections' => $serviceContract->document_sections ?? $sourceContract?->document_sections ?? Contract::defaultDocumentSections(),
        ]);

        if ($serviceContract->isDirty()) {
            $serviceContract->save();
        }

        $this->syncRelatedContracts($serviceContract, $this->syncableContractState($serviceContract));

        return $this->freshContract($serviceContract);
    }

    public function recordDecision(Contract $contract, array $attributes, User $actor, Request $request): Contract
    {
        return DB::transaction(function () use ($contract, $attributes, $actor, $request) {
            $contract = Contract::query()->lockForUpdate()->findOrFail($contract->id);

            $decision = $attributes['decision'];
            $decisionAt = now();
            $decisionBy = $this->decisionBy($actor, $attributes['decisionBy'] ?? null);
            $oldStatus = $contract->status;

            if ($decision === 'accept') {
                $contract->fill([
                    'status' => Contract::STATUS_ACCEPTED,
                    'agreement_accepted' => true,
                    'terms_accepted' => true,
                    'privacy_accepted' => true,
                    'accepted_at' => $decisionAt,
                    'rejected_at' => null,
                    'decision_at' => $decisionAt,
                    'decision_by' => $decisionBy,
                ]);
            } else {
                $contract->fill([
                    'status' => Contract::STATUS_REJECTED,
                    'agreement_accepted' => false,
                    'terms_accepted' => false,
                    'privacy_accepted' => false,
                    'accepted_at' => null,
                    'rejected_at' => $decisionAt,
                    'decision_at' => $decisionAt,
                    'decision_by' => $decisionBy,
                ]);
            }

            $contract->save();

            $this->syncRelatedContracts($contract, $this->syncableDecisionState($contract));

            $this->audit(
                $contract,
                $actor,
                $decision === 'accept' ? ContractAuditLog::ACTION_ACCEPTED : ContractAuditLog::ACTION_REJECTED,
                $oldStatus,
                $contract->status,
                [
                    'source' => $attributes['source'] ?? 'customer-portal',
                    'agreementAccepted' => (bool) $contract->agreement_accepted,
                    'termsAccepted' => (bool) $contract->terms_accepted,
                    'privacyAccepted' => (bool) $contract->privacy_accepted,
                    'requestedDecisionAt' => $attributes['decisionAt'] ?? null,
                    'decisionBy' => $decisionBy,
                ],
                $request,
            );

            return $this->freshContract($contract);
        });
    }

    public function verify(Contract $contract, User $actor, Request $request, array $attributes = []): Contract
    {
        return DB::transaction(function () use ($contract, $actor, $request, $attributes) {
            $contract = Contract::query()->lockForUpdate()->findOrFail($contract->id);
            $verifiedAt = now();

            $contract->fill([
                'verified_by' => $actor->id,
                'verified_at' => $verifiedAt,
                'verification_status' => Contract::VERIFICATION_VERIFIED,
            ]);

            $contract->save();

            $this->syncRelatedContracts($contract, $this->syncableVerificationState($contract));

            $this->audit(
                $contract,
                $actor,
                ContractAuditLog::ACTION_VERIFIED,
                $contract->status,
                $contract->status,
                [
                    'source' => $attributes['source'] ?? 'admin-portal',
                    'note' => $attributes['note'] ?? null,
                    'verifiedBy' => $actor->name,
                    'verifiedAt' => $verifiedAt->toISOString(),
                ],
                $request,
            );

            return $this->freshContract($contract);
        });
    }

    public function uploadSignedDocument(Contract $contract, UploadedFile $file, User $actor, Request $request, array $attributes = []): Contract
    {
        return DB::transaction(function () use ($contract, $file, $actor, $request, $attributes) {
            $contract = Contract::query()->lockForUpdate()->findOrFail($contract->id);

            $this->deleteSignedDocument($contract->signed_document_path);

            $originalName = $file->getClientOriginalName();
            $extension = strtolower((string) $file->getClientOriginalExtension());
            $baseName = Str::slug(pathinfo($originalName, PATHINFO_FILENAME));
            $storedName = ($baseName !== '' ? $baseName : 'signed-document').'-'.Str::random(8).'.'.$extension;
            $path = $file->storeAs('contracts/signed/'.$contract->external_key, $storedName, Contract::PRIMARY_SIGNED_DOCUMENT_DISK);
            $uploadedAt = now();

            $previousDocumentName = $contract->signed_document_name;

            $contract->fill([
                'signed_document_name' => $originalName,
                'signed_document_path' => $path,
                'signed_document_uploaded_at' => $uploadedAt,
                'signed_document_uploaded_by' => $actor->id,
            ]);

            $contract->save();

            $this->syncRelatedContracts($contract, $this->syncableSignedDocumentState($contract));

            $this->audit(
                $contract,
                $actor,
                ContractAuditLog::ACTION_SIGNED_DOCUMENT_UPLOADED,
                $contract->status,
                $contract->status,
                [
                    'source' => $attributes['source'] ?? 'customer-portal',
                    'previousSignedDocumentName' => $previousDocumentName,
                    'signedDocumentName' => $originalName,
                    'signedDocumentUploadedAt' => $uploadedAt->toISOString(),
                    'signedDocumentUploadedBy' => $actor->name,
                ],
                $request,
            );

            return $this->freshContract($contract);
        });
    }

    public function attachCustomerService(PortalOrder $order, CustomerService $customerService): void
    {
        $contract = Contract::query()
            ->where('order_id', $order->id)
            ->whereNull('customer_service_id')
            ->latest('id')
            ->first();

        if (! $contract) {
            return;
        }

        $contract->update([
            'customer_service_id' => $customerService->id,
            'service_name' => $contract->service_name ?? $customerService->name,
        ]);

        $this->ensureServiceContract($customerService);
    }

    private function freshContract(Contract $contract): Contract
    {
        return $contract->fresh()->load(['user', 'order.items', 'customerService.service', 'verifiedBy', 'signedDocumentUploader']);
    }

    private function syncRelatedContracts(Contract $contract, array $attributes): void
    {
        if (! $contract->customer_service_id || $attributes === []) {
            return;
        }

        Contract::query()
            ->where('customer_service_id', $contract->customer_service_id)
            ->where('id', '!=', $contract->id)
            ->get()
            ->each(function (Contract $relatedContract) use ($attributes): void {
                $relatedContract->fill($attributes);

                if ($relatedContract->isDirty()) {
                    $relatedContract->save();
                }
            });
    }

    private function syncableDecisionState(Contract $contract): array
    {
        return [
            'status' => $contract->status,
            'agreement_accepted' => $contract->agreement_accepted,
            'terms_accepted' => $contract->terms_accepted,
            'privacy_accepted' => $contract->privacy_accepted,
            'decision_by' => $contract->decision_by,
            'decision_at' => $contract->decision_at,
            'accepted_at' => $contract->accepted_at,
            'rejected_at' => $contract->rejected_at,
        ];
    }

    private function syncableVerificationState(Contract $contract): array
    {
        return [
            'verified_by' => $contract->verified_by,
            'verified_at' => $contract->verified_at,
            'verification_status' => $contract->verification_status,
        ];
    }

    private function syncableSignedDocumentState(Contract $contract): array
    {
        return [
            'signed_document_name' => $contract->signed_document_name,
            'signed_document_path' => $contract->signed_document_path,
            'signed_document_uploaded_at' => $contract->signed_document_uploaded_at,
            'signed_document_uploaded_by' => $contract->signed_document_uploaded_by,
        ];
    }

    private function syncableContractState(Contract $contract): array
    {
        return array_merge(
            $this->syncableDecisionState($contract),
            $this->syncableVerificationState($contract),
            $this->syncableSignedDocumentState($contract),
            [
                'requires_signed_document' => $contract->requires_signed_document,
            ],
        );
    }

    private function deleteSignedDocument(?string $path): void
    {
        if (! $path) {
            return;
        }

        foreach (Contract::SIGNED_DOCUMENT_DISKS as $disk) {
            if (Storage::disk($disk)->exists($path)) {
                Storage::disk($disk)->delete($path);
            }
        }
    }

    private function audit(
        Contract $contract,
        ?User $actor,
        string $action,
        ?string $oldStatus,
        ?string $newStatus,
        array $metadata,
        Request $request,
    ): void {
        $contract->auditLogs()->create([
            'user_id' => $actor?->id,
            'action' => $action,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'metadata' => $metadata,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);
    }

    private function orderExternalKey(PortalOrder $order): string
    {
        return 'order-'.$order->order_number;
    }

    private function serviceExternalKey(CustomerService $customerService): string
    {
        return 'service-'.$customerService->id;
    }

    private function sourceContractForService(CustomerService $customerService): ?Contract
    {
        $orderId = $customerService->orderItem?->portal_order_id;

        return Contract::query()
            ->where('user_id', $customerService->user_id)
            ->where('scope', '!=', Contract::SCOPE_SERVICE)
            ->where(function ($query) use ($customerService, $orderId) {
                $query->where('customer_service_id', $customerService->id);

                if ($orderId) {
                    $query->orWhere('order_id', $orderId);
                }
            })
            ->latest('id')
            ->first();
    }

    private function resolveContractKey(string $contractKey): Contract
    {
        $contract = Contract::query()->where('external_key', $contractKey)->first();

        if ($contract) {
            return $this->freshContract($contract);
        }

        if (preg_match('/^service-(\d+)$/', $contractKey, $matches) === 1) {
            $customerService = CustomerService::query()
                ->with(['orderItem.order'])
                ->findOrFail((int) $matches[1]);

            return $this->ensureServiceContract($customerService);
        }

        throw (new ModelNotFoundException())->setModel(Contract::class, [$contractKey]);
    }

    private function decisionBy(User $actor, ?string $provided): string
    {
        $provided = trim((string) $provided);

        if ($provided !== '') {
            return $provided;
        }

        return trim((string) $actor->name) !== '' ? $actor->name : 'Customer';
    }

    private function requiresSignedDocument(PortalOrder $order): bool
    {
        return strtolower((string) $order->payment_method) === 'bank_transfer';
    }
}
