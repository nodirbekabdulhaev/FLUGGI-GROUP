<?php

namespace App\Http\Controllers\Sales;

use App\Services\Sales\DocumentRenderer;
use App\Services\Sales\DocumentsService;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/** Генерация КП и договора (HTML, PDF, Word, TXT), шаблоны документов, реквизиты клиента. */
class DocumentController extends SalesController
{
    /** Документ формирует сервер с проверкой прав на КП/договор. Неизвестный формат — 422. */
    public function show(Request $request, string $kind, string $id): Response
    {
        $format = (string) $request->query('format', 'html');
        if (! in_array($format, DocumentsService::FORMATS, true) || ! in_array((string) $request->query('download', 'false'), ['true', 'false'], true)) {
            abort(422);
        }
        access()->authorize($kind === 'contracts' ? 'contract.read' : 'proposal.read');
        $model = DocumentsService::model($kind, $id);
        $name = DocumentRenderer::asciiName($model['fileName'].'.'.$format);

        return response(DocumentRenderer::render($model, $format), 200, [
            'Content-Type' => DocumentRenderer::TYPES[$format],
            'Content-Disposition' => ($request->query('download') === 'true' ? 'attachment' : 'inline').'; filename="'.$name.'"',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function missing(string $kind, string $id): JsonResponse
    {
        access()->authorize($kind === 'contracts' ? 'contract.read' : 'proposal.read');

        return response()->json(['missing' => DocumentsService::check($kind, $id)]);
    }

    public function settings(): View
    {
        return view('sales.documents.settings', ['settings' => DocumentsService::settings(), 'company' => Settings::company()]);
    }

    public function saveSettings(Request $request): RedirectResponse|JsonResponse
    {
        $data = $this->check($request, [
            'city' => ['required', 'string', 'min:1', 'max:60'],
            'contractTemplate' => ['required', 'string', 'max:50000'],
            'proposalIntro' => ['nullable', 'string', 'max:4000'],
            'proposalNote' => ['nullable', 'string', 'max:4000'],
        ]);
        DocumentsService::saveSettings([
            'city' => trim($data['city']),
            'contractTemplate' => $data['contractTemplate'],
            'proposalIntro' => $data['proposalIntro'] ?? '',
            'proposalNote' => $data['proposalNote'] ?? '',
        ]);

        return $this->done($request, t('documents.saved'));
    }
}
