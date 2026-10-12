<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Общее для контроллеров продаж: валидация с понятными текстами, деньги из формы, ответ форме или JS. */
abstract class SalesController extends Controller
{
    /** Деньги: строка-decimal с 2 знаками, не отрицательная (как moneySchema прежней версии). */
    public const MONEY = ['regex:/^\d{1,16}(\.\d{1,2})?$/'];

    public const CURRENCIES = ['UZS', 'USD'];

    /**
     * Валидация: общие тексты ошибок + свои; суммы и числа приводятся к виду 1234.56
     * («9 000 000,50» → «9000000.50»). В $numbers допускается «items.*.unit_price».
     */
    protected function check(Request $request, array $rules, array $numbers = [], array $messages = []): array
    {
        $all = $request->input();
        foreach ($numbers as $path) {
            $all = $this->normalize($all, explode('.', $path));
        }
        $request->replace($all);
        $generic = [];
        foreach (['required', 'string', 'max', 'min', 'numeric', 'integer', 'in', 'uuid', 'date_format', 'regex', 'boolean', 'array', 'gt', 'exists', 'file', 'required_if', 'month'] as $rule) {
            $generic[$rule] = t('sales.validation.'.$rule);
        }

        return $request->validate($rules, $messages + $generic);
    }

    private function normalize(mixed $data, array $path): mixed
    {
        if (! $path) {
            if (is_string($data) || is_int($data) || is_float($data)) {
                $v = str_replace([' ', "\u{00A0}", "\u{202F}", ','], ['', '', '', '.'], (string) $data);

                return $v === '' ? null : $v;
            }

            return $data;
        }
        $key = array_shift($path);
        if (! is_array($data)) {
            return $data;
        }
        if ($key === '*') {
            foreach ($data as $k => $v) {
                $data[$k] = $this->normalize($v, $path);
            }
        } elseif (array_key_exists($key, $data)) {
            $data[$key] = $this->normalize($data[$key], $path);
        }

        return $data;
    }

    /** Пустые строки → null. */
    protected function nulls(array $data, array $keys): array
    {
        foreach ($keys as $k) {
            if (array_key_exists($k, $data) && $data[$k] === '') {
                $data[$k] = null;
            }
        }

        return $data;
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
