<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\CustomerService;
use App\Services\ContractService;
use App\Support\PortalFormatter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ContractsController extends Controller
{
    public function indexMine(Request $request, ContractService $contractService): JsonResponse
    {
        $contracts = $contractService
            ->listForUser($request->user())
            ->map(fn (Contract $contract) => PortalFormatter::contract($contract))
            ->values();

        return response()->json([
            'contracts' => $contracts,
        ]);
    }

    public function recordDecision(Request $request, string $contract, ContractService $contractService): JsonResponse
    {
        $contract = $contractService->resolveForUser($request->user(), $contract);

        $validated = $request->validate([
            'decision' => ['required', 'string', Rule::in(['accept', 'reject'])],
            'status' => ['nullable', 'string'],
            'agreementAccepted' => ['nullable', 'boolean'],
            'termsAccepted' => ['nullable', 'boolean'],
            'privacyAccepted' => ['nullable', 'boolean'],
            'decisionAt' => ['nullable', 'date'],
            'decisionBy' => ['nullable', 'string', 'max:255'],
            'source' => ['nullable', 'string', 'max:255'],
        ]);

        $contract = $contractService->recordDecision($contract, $validated, $request->user(), $request);

        return response()->json([
            'message' => $validated['decision'] === 'accept'
                ? 'Agreement accepted successfully.'
                : 'Agreement rejected successfully.',
            'contract' => PortalFormatter::contract($contract),
        ]);
    }

    public function uploadSignedDocument(Request $request, string $contract, ContractService $contractService): JsonResponse
    {
        $contract = $contractService->resolveForUser($request->user(), $contract);

        $validated = $this->validateSignedDocumentUpload($request);

        $contract = $contractService->uploadSignedDocument(
            $contract,
            $validated['signedDocument'],
            $request->user(),
            $request,
            ['source' => 'customer-portal'],
        );

        return response()->json([
            'message' => 'Signed document uploaded successfully.',
            'contract' => PortalFormatter::contract($contract),
        ]);
    }

    public function downloadServiceAgreement(Request $request, CustomerService $customerService, ContractService $contractService)
    {
        $contract = $this->resolveDownloadContract($request, $contractService, 'service-'.$customerService->id);

        return $this->agreementDownloadResponse($contract);
    }

    public function downloadSignedDocument(Request $request, string $contract, ContractService $contractService)
    {
        $contract = $this->resolveDownloadContract($request, $contractService, $contract);

        return $this->signedDocumentDownloadResponse($contract);
    }

    public function download(Request $request, string $contract, ContractService $contractService)
    {
        $contract = $this->resolveDownloadContract($request, $contractService, $contract);

        return $this->agreementDownloadResponse($contract);
    }

    private function resolveDownloadContract(Request $request, ContractService $contractService, string $contract): Contract
    {
        $authenticatedUser = Auth::guard('sanctum')->user();

        if ($authenticatedUser) {
            return $authenticatedUser->role === 'admin'
                ? $contractService->resolveForAdmin($contract)
                : $contractService->resolveForUser($authenticatedUser, $contract);
        }

        if ($request->query('signature')) {
            abort_unless($request->hasValidSignature(), 403, 'Invalid or expired download link.');

            return $contractService->resolveForAdmin($contract);
        }

        abort(401, 'Unauthenticated.');
    }

    private function agreementDownloadResponse(Contract $contract)
    {
        $contract->loadMissing(['order.items', 'customerService']);

        if ($contract->download_path && Storage::disk('public')->exists($contract->download_path)) {
            return response()->download(
                Storage::disk('public')->path($contract->download_path),
                basename($contract->download_path)
            );
        }

        $filename = (Str::slug($contract->external_key) ?: 'contract').'-agreement.txt';
        $content = $this->downloadContent($contract);

        return response()->streamDownload(function () use ($content) {
            echo $content;
        }, $filename, [
            'Content-Type' => 'text/plain; charset=UTF-8',
        ]);
    }

    private function signedDocumentDownloadResponse(Contract $contract)
    {
        $disk = $this->signedDocumentDisk($contract);

        abort_unless(
            $contract->signed_document_path && Storage::disk($disk)->exists($contract->signed_document_path),
            404,
            'Signed document not found.'
        );

        return response()->download(
            Storage::disk($disk)->path($contract->signed_document_path),
            $contract->signed_document_name ?: basename($contract->signed_document_path)
        );
    }

    private function signedDocumentDisk(Contract $contract): string
    {
        foreach (Contract::SIGNED_DOCUMENT_DISKS as $disk) {
            if ($contract->signed_document_path && Storage::disk($disk)->exists($contract->signed_document_path)) {
                return $disk;
            }
        }

        return Contract::PRIMARY_SIGNED_DOCUMENT_DISK;
    }

    private function validateSignedDocumentUpload(Request $request): array
    {
        return $request->validate([
            'signedDocument' => ['required', 'file', 'mimes:pdf,doc,docx,png,jpg,jpeg', 'max:10240'],
        ], [
            'signedDocument.required' => 'A signed document file is required.',
            'signedDocument.file' => 'The signed document upload must be a file.',
            'signedDocument.mimes' => 'The signed document must be a PDF, Word document, or image file (pdf, doc, docx, jpg, jpeg, png).',
            'signedDocument.max' => 'The signed document may not be greater than 10 MB.',
        ]);
    }

    private function downloadContent(Contract $contract): string
    {
        $sections = $contract->document_sections ?: Contract::defaultDocumentSections();
        $lines = [
            $contract->title,
            str_repeat('=', strlen($contract->title)),
            '',
            $contract->description ?: 'WSI portal customer agreement record.',
            '',
            'Status: '.$contract->status,
            'Version: '.($contract->version ?: 'v1.0'),
            'Audit Reference: '.($contract->audit_reference ?: $contract->external_key),
            'Service: '.($contract->service_name ?: 'WSI Service'),
        ];

        if ($contract->order?->order_number) {
            $lines[] = 'Order Number: '.$contract->order->order_number;
        }

        $lines[] = '';
        $lines[] = 'Sections';
        $lines[] = '--------';

        foreach ($sections as $section) {
            $lines[] = '';
            $lines[] = ($section['title'] ?? 'Section');
            $lines[] = $section['description'] ?? '';
        }

        return implode("\n", $lines)."\n";
    }
}
