<?php

namespace App\Http\Controllers\Integrations;

use App\Exceptions\BusinessRule;
use App\Http\Controllers\Controller;
use App\Services\Integrations\FormService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response as BaseResponse;

/**
 * Публичная форма заявки: страница f/{key} (без входа, встраивается iframe на сайт) и приём
 * заявок hooks/intake/{key} — JSON { data, utm, page } (наша форма) или обычная HTML-форма /
 * webhook плагина WordPress (поля плоско: name=…&phone=…&utm_source=…). CORS открыт.
 */
class PublicFormController extends Controller
{
    private const CORS = [
        'Access-Control-Allow-Origin' => '*',
        'Access-Control-Allow-Methods' => 'POST, OPTIONS',
        'Access-Control-Allow-Headers' => 'Content-Type, Accept',
        'Access-Control-Max-Age' => '86400',
    ];

    public function show(string $key): View
    {
        $form = FormService::active($key);

        return view('integrations.public-form', ['form' => $form]);
    }

    public function preflight(): Response
    {
        return response('', 204, self::CORS);
    }

    public function submit(Request $request, string $key): BaseResponse
    {
        $raw = $request->all();
        $flat = ! is_array($raw['data'] ?? null);
        $input = $flat ? [
            'data' => array_filter($raw, fn ($v, $k) => is_string($v) && ! str_starts_with($k, 'utm_') && ! in_array($k, ['website', 'page', '_token'], true), ARRAY_FILTER_USE_BOTH),
            'utm' => array_filter($raw, fn ($v, $k) => is_string($v) && str_starts_with($k, 'utm_'), ARRAY_FILTER_USE_BOTH),
            'page' => is_string($raw['page'] ?? null) ? $raw['page'] : $request->header('referer'),
            'website' => is_string($raw['website'] ?? null) ? $raw['website'] : null,
        ] : [
            'data' => array_filter($raw['data'], 'is_string'),
            'utm' => is_array($raw['utm'] ?? null) ? array_filter($raw['utm'], 'is_string') : null,
            'page' => is_string($raw['page'] ?? null) ? $raw['page'] : null,
            'website' => is_string($raw['website'] ?? null) ? $raw['website'] : null,
            'renderedAt' => is_numeric($raw['renderedAt'] ?? null) ? (float) $raw['renderedAt'] : null,
        ];
        $input['data'] = array_map(fn ($v) => mb_substr($v, 0, 4000), array_slice($input['data'], 0, 40, true));
        $html = $flat && ! $request->expectsJson() && $request->isMethod('post') && str_contains((string) $request->header('Content-Type'), 'form');
        try {
            $message = FormService::submit($key, $input, $request->ip());
        } catch (BusinessRule $e) {
            if ($html) {
                return response()->view('integrations.public-result', ['message' => $e->getMessage(), 'errors' => $e->fields, 'back' => $request->header('referer')], 422, self::CORS);
            }

            return response()->json(['message' => $e->getMessage(), 'errors' => array_map(fn ($m) => [$m], $e->fields)], 422, self::CORS);
        }
        // Обычная HTML-форма без JavaScript — страница «Спасибо»
        if ($html) {
            return response()->view('integrations.public-result', ['message' => $message, 'errors' => [], 'back' => $request->header('referer')], 200, self::CORS);
        }

        return response()->json(['ok' => true, 'message' => $message], 200, self::CORS);
    }
}
