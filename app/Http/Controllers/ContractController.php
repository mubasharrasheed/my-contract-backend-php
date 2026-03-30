<?php

namespace App\Http\Controllers;

use App\Models\Contract;
use App\Models\Document;
use App\Services\PdfExtractionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Smalot\PdfParser\Parser;

class ContractController extends Controller
{
    public function __construct(
        protected PdfExtractionService $extractionService
    ) {}

    /**
     * Upload a PDF, extract data into contract and document tables.
     */
    public function create(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:pdf', 'max:15360'],
        ]);

        $uploadedFile = $request->file('file');
        $originalName = $uploadedFile->getClientOriginalName();
        $path = $uploadedFile->store(
            'documents/' . $request->user()->id,
            'local'
        );

        if ($path === false) {
            throw ValidationException::withMessages(['file' => ['Failed to store file.']]);
        }

        $fullPath = storage_path('app/' . $path);
        $rawText = null;

        try {
            $parser = new Parser();
            $pdf = $parser->parseFile($fullPath);
            $rawText = $pdf->getText();
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Storage::disk('local')->delete($path);
            throw ValidationException::withMessages([
                'file' => ['Could not read PDF: ' . $e->getMessage()],
            ]);
        }

        $contractData = $this->extractionService->extractForContract($rawText ?? '');
        $contract = $request->user()->contracts()->create($contractData);

        $document = $request->user()->documents()->create([
            'contract_id' => $contract->id,
            'original_filename' => $originalName,
            'storage_path' => $path,
        ]);

        return response()->json([
            'message' => 'Contract created from PDF.',
            'contract' => $contract->fresh(),
            'document' => $document->load('contract'),
        ], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $contracts = $request->user()
            ->contracts()
            ->with('documents')
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['contracts' => $contracts]);
    }

    public function show(Request $request, Contract $contract): JsonResponse
    {
        if ($contract->user_id !== $request->user()->id) {
            abort(404);
        }

        return response()->json(['contract' => $contract->load('documents')]);
    }
}
