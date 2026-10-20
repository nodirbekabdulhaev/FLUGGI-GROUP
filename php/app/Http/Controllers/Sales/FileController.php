<?php

namespace App\Http\Controllers\Sales;

use App\Services\Sales\FilesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Файлы сделки, проекта и задач. Права проверяются по записи, к которой относится файл. */
class FileController extends SalesController
{
    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $data = $this->check($request, [
            'file' => ['required', 'file'],
            'deal_id' => ['nullable', 'uuid'],
            'contract_id' => ['nullable', 'uuid'],
            'proposal_id' => ['nullable', 'uuid'],
            'payment_id' => ['nullable', 'uuid'],
            'project_id' => ['nullable', 'uuid'],
            'task_id' => ['nullable', 'uuid'],
            'category' => ['nullable', Rule::in(FilesService::CATEGORIES)],
        ], [], ['file.required' => t('sales.files.errors.noFile'), 'file.file' => t('sales.files.errors.noFile')]);
        $f = FilesService::upload(
            $request->file('file'),
            array_intersect_key($data, array_flip(['deal_id', 'contract_id', 'proposal_id', 'payment_id', 'project_id', 'task_id'])),
            $data['category'] ?? 'DOCUMENT',
        );

        return $this->done($request, t('sales.files.uploaded'), null, ['id' => $f->id, 'originalName' => $f->original_name]);
    }

    public function index(Request $request): JsonResponse
    {
        $q = $this->check($request, ['deal_id' => ['nullable', 'uuid'], 'project_id' => ['nullable', 'uuid'], 'task_id' => ['nullable', 'uuid']]);

        return response()->json(FilesService::list($q)->map(fn ($f) => [
            'id' => $f->id,
            'originalName' => $f->original_name,
            'mimeType' => $f->mime_type,
            'sizeBytes' => $f->size_bytes,
            'category' => $f->category,
            'uploadedBy' => ['id' => $f->uploadedBy?->id, 'name' => $f->uploadedBy?->full_name],
            'createdAt' => $f->created_at?->format('Y-m-d\TH:i:s.v\Z'),
            'url' => route('sales.files.download', $f->id),
        ])->all());
    }

    public function download(string $id): StreamedResponse
    {
        $f = FilesService::open($id);
        $disk = Storage::disk(FilesService::DISK);
        if (! $disk->exists($f->storage_key)) {
            abort(404);
        }

        return $disk->download($f->storage_key, FilesService::safeName($f->original_name), [
            'Content-Type' => $f->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function destroy(Request $request, string $id): RedirectResponse|JsonResponse
    {
        FilesService::remove($id);

        return $this->done($request, t('sales.files.deleted'));
    }
}
