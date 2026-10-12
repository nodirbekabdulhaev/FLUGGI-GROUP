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

    /** Реквизиты клиента для договора: ИНН — 9 цифр или ПИНФЛ — 14, МФО — 5, р/с — 20. */
    public function saveRequisites(Request $request, string $id): RedirectResponse|JsonResponse
    {
        $text = fn (int $max) => ['nullable', 'string', 'max:'.$max];
        $data = $this->check($request, [
            'legalName' => $text(300),
            'inn' => ['nullable', 'regex:/^(\d{9}|\d{14})$/'],
            'director' => $text(120),
            'directorPosition' => $text(120),
            'signerGenitive' => $text(200),
            'basis' => $text(200),
            'address' => $text(300),
            'phone' => $text(60),
            'bank' => $text(200),
            'mfo' => ['nullable', 'regex:/^\d{5}$/'],
            'account' => ['nullable', 'regex:/^\d{20}$/'],
            'oked' => $text(10),
            'vatCode' => $text(20),
        ], [], [
            'inn.regex' => t('requisites.errors.inn'),
            'mfo.regex' => t('requisites.errors.mfo'),
            'account.regex' => t('requisites.errors.account'),
        ]);
        $value = DocumentsService::saveClientRequisites($id, $data);

        return $this->done($request, t('requisites.saved'), null, ['requisites' => $value]);
    }
}
