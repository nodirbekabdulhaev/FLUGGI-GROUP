<?php

namespace App\Http\Controllers\Automation;

use App\Http\Controllers\Controller;
use App\Services\Automation\FollowUpService;
use App\Support\Format;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Повторные продажи (ТЗ §38): follow-up через 30/60/90 дней после завершения проекта. */
class FollowUpController extends Controller
{
    public function index(Request $request): View
    {
        $view = in_array($request->query('view'), ['due', 'pending', 'closed'], true) ? $request->query('view') : 'due';

        return view('automation.follow-ups', ['view' => $view, 'items' => FollowUpService::list(access(), $view)]);
    }

    public function complete(Request $request, string $id): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['DONE', 'SKIPPED'])],
            'result' => ['nullable', 'string', 'max:2000'],
            'createDeal' => ['sometimes', 'boolean'],
        ]);
        $f = FollowUpService::complete(access(), $id, $data['status'], isset($data['result']) ? trim($data['result']) : null, $request->boolean('createDeal'));
        $message = $f->resultDeal ? t('followUps.dealCreated', ['number' => Format::code('D', $f->resultDeal->number)]) : t('followUps.closed');
        if ($request->expectsJson()) {
            return response()->json(['id' => $f->id, 'status' => $f->status, 'result' => $f->result,
                'resultDeal' => $f->resultDeal ? ['id' => $f->resultDeal->id, 'number' => Format::code('D', $f->resultDeal->number)] : null]);
        }

        return back()->with('ok', $message);
    }
}
