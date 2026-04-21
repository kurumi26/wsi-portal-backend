<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ContractService;
use App\Support\PortalFormatter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminContractsController extends Controller
{
    public function index(ContractService $contractService): JsonResponse
    {
        $contracts = $contractService
            ->listForAdmin()
            ->map(fn ($contract) => PortalFormatter::contract($contract))
            ->values();

        return response()->json([
            'contracts' => $contracts,
        ]);
    }

    public function verify(Request $request, string $contract, ContractService $contractService): JsonResponse
    {
        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:2000'],
            'source' => ['nullable', 'string', 'max:255'],
        ]);

        $contract = $contractService->resolveForAdmin($contract);
        $contract = $contractService->verify($contract, $request->user(), $request, $validated);

        return response()->json([
            'message' => 'Contract verified successfully.',
            'contract' => PortalFormatter::contract($contract),
        ]);
    }

    public function uploadSignedDocument(Request $request, string $contract, ContractService $contractService): JsonResponse
    {
        $validated = $request->validate([
            'signedDocument' => ['required', 'file', 'mimes:pdf,doc,docx,png,jpg,jpeg', 'max:10240'],
        ], [
            'signedDocument.required' => 'A signed document file is required.',
            'signedDocument.file' => 'The signed document upload must be a file.',
            'signedDocument.mimes' => 'The signed document must be a PDF, Word document, or image file (pdf, doc, docx, jpg, jpeg, png).',
            'signedDocument.max' => 'The signed document may not be greater than 10 MB.',
        ]);

        $contract = $contractService->resolveForAdmin($contract);
        $contract = $contractService->uploadSignedDocument(
            $contract,
            $validated['signedDocument'],
            $request->user(),
            $request,
            ['source' => 'admin-portal'],
        );

        return response()->json([
            'message' => 'Signed document uploaded successfully.',
            'contract' => PortalFormatter::contract($contract),
        ]);
    }
}
