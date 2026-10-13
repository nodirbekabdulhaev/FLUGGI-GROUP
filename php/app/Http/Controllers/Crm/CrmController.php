<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Services\Finance\Input;
use App\Support\Format;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Общее для контроллеров CRM: валидация с понятными текстами, деньги из формы, ответ форме или JS. */
abstract class CrmController extends Controller
{
    /** Деньги: «9 000 000,50» → «9000000.50», не отрицательные, до 2 знаков. */
    public const MONEY = ['regex:/^\d{1,16}(\.\d{1,2})?$/'];

    public const PHONE = ['nullable', 'string', 'max:30', 'regex:/^[+0-9\s\-()]*$/'];

    /** Валидация: общие тексты ошибок + свои; суммы приводятся к виду 1234.56. */
    protected function check(Request $request, array $rules, array $messages = [], array $money = []): array
    {
        foreach ($money as $field) {
            $v = $request->input($field);
            if (is_string($v) || is_numeric($v)) {
                $request->merge([$field => Input::money($v)]);
            }
        }
        $generic = [];
        foreach (['required', 'string', 'max', 'min', 'numeric', 'integer', 'in', 'uuid', 'email', 'date', 'date_format', 'regex', 'boolean', 'between', 'array', 'required_without', 'gt'] as $rule) {
            $generic[$rule] = t('crm.validation.'.$rule);
        }

        return $request->validate($rules, $messages + $generic);
    }

    /** datetime-local (Ташкент) → UTC. */
    protected function localDateTime(?string $v): ?string
    {
        return $v ? Format::fromLocalInput($v)->format('Y-m-d H:i:s.v') : null;
    }

    /** Форме — редирект с сообщением, JavaScript — JSON. */
    protected function done(Request $request, string $message, ?string $redirect = null, array $json = []): RedirectResponse|JsonResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'message' => $message] + $json);
        }

        return ($redirect ? redirect($redirect) : back())->with('ok', $message);
    }
}
