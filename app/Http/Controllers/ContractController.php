<?php

namespace App\Http\Controllers;

use App\Models\Contract;
use App\Services\ContractFileTextExtractor;
use App\Services\PdfExtractionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\ValidationException;

class ContractController extends Controller
{
    public function __construct(
        protected PdfExtractionService $extractionService,
        protected ContractFileTextExtractor $fileTextExtractor
    ) {}

    /**
     * Upload a PDF or Word document (.doc / .docx), extract data into contract and document tables.
     */
    public function create(Request $request): JsonResponse
    {
        $request->validate([
            'file' => [
                'required',
                File::types(['pdf', 'doc', 'docx'])->max(15 * 1024),
            ],
        ]);

        $uploadedFile = $request->file('file');
        $originalName = $uploadedFile->getClientOriginalName();
        $name = pathinfo(rawurldecode($originalName), PATHINFO_FILENAME);
        $path = $uploadedFile->store(
            'documents/'.$request->user()->id,
            'local'
        );

        if ($path === false) {
            throw ValidationException::withMessages(['file' => ['Failed to store file.']]);
        }

        $fullPath = storage_path('app/'.$path);
        $extension = $this->uploadExtension($uploadedFile);

        try {
            $rawText = $this->fileTextExtractor->extract($fullPath, $extension);
        } catch (\Exception $e) {
            Storage::disk('local')->delete($path);
            throw ValidationException::withMessages([
                'file' => ['Could not read document: '.$e->getMessage()],
            ]);
        }

        $contractData = $this->extractionService->extractForContract($rawText ?? '', $name);
        $contract = $request->user()->contracts()->create(array_merge($contractData, [
            'status' => Contract::STATUS_IN_PROGRESS,
        ]));

        $document = $request->user()->documents()->create([
            'contract_id' => $contract->id,
            'original_filename' => $originalName,
            'storage_path' => $path,
        ]);

        return response()->json([
            'message' => 'Contract created from uploaded document.',
            'contract' => $contract->fresh(),
            'document' => $document->load('contract'),
        ], 201);
    }

    /**
     * Replace contract data from a new PDF or Word document and save the new file.
     */
    public function update(Request $request, Contract $contract): JsonResponse
    {
        if ($contract->user_id !== $request->user()->id) {
            abort(404);
        }

        $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'file' => [
                'nullable',
                File::types(['pdf', 'doc', 'docx'])->max(15 * 1024),
            ],
        ]);

        if ($request->has('name')) {
            $contract->update([
                'name' => $request->input('name'),
            ]);
        }

        if ($request->has('file')) {

            $uploadedFile = $request->file('file');
            $originalName = $uploadedFile->getClientOriginalName();
            $path = $uploadedFile->store(
                'documents/'.$request->user()->id,
                'local'
            );

            if ($path === false) {
                throw ValidationException::withMessages(['file' => ['Failed to store file.']]);
            }

            $fullPath = storage_path('app/'.$path);
            $extension = $this->uploadExtension($uploadedFile);

            try {
                $rawText = $this->fileTextExtractor->extract($fullPath, $extension);
            } catch (\Exception $e) {
                Storage::disk('local')->delete($path);
                throw ValidationException::withMessages([
                    'file' => ['Could not read document: '.$e->getMessage()],
                ]);
            }

            $nameHint = pathinfo(rawurldecode($originalName), PATHINFO_FILENAME);
            $contractData = $this->extractionService->extractForContract($rawText ?? '', $nameHint);
            // dd($contractData);
            $contract->update($contractData);

            $document = $contract->documents()->latest('id')->first();
            if ($document !== null && $document->storage_path) {
                Storage::disk('local')->delete($document->storage_path);
                $document->update([
                    'original_filename' => $originalName,
                    'storage_path' => $path,
                ]);
            } else {
                $document = $request->user()->documents()->create([
                    'contract_id' => $contract->id,
                    'original_filename' => $originalName,
                    'storage_path' => $path,
                ]);
            }
        }

        return response()->json([
            'message' => 'Contract updated from uploaded document.',
            'contract' => @$contract->fresh(),
            'document' => @$document ? $document->fresh()->load('contract') : null,
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['nullable', 'in:in_progress,completed'],
            'search' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $mycontracts = $request->user()->contracts()->where('status', Contract::STATUS_IN_PROGRESS)->get();

        foreach ($mycontracts as $contract) {
            $contract->refreshStatusFromExpiry();
        }

        $query = $request->user()
            ->contracts()
            ->with('documents');

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($search = trim((string) $request->input('search'))) {
            $like = '%'.$search.'%';
            $query->where(function ($q) use ($like) {
                $q->where('name', 'like', $like)
                    ->orWhere('agreement_number', 'like', $like)
                    ->orWhere('recipient_name', 'like', $like)
                    ->orWhere('company_name', 'like', $like);
            });
        }

        $contracts = $query->orderByDesc('created_at')
            ->paginate((int) $request->input('per_page', 15))
            ->withQueryString();

        return response()->json($contracts);
    }

    public function show(Request $request, Contract $contract): JsonResponse
    {
        if ($contract->user_id !== $request->user()->id) {
            abort(404);
        }

        $contract->refreshStatusFromExpiry();

        return response()->json(['contract' => $contract->fresh()->load('documents')]);
    }

    protected function uploadExtension(UploadedFile $file): string
    {
        $ext = strtolower($file->getClientOriginalExtension());
        if ($ext !== '') {
            return $ext;
        }

        return match ($file->getMimeType()) {
            'application/pdf' => 'pdf',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            default => '',
        };
    }
}
