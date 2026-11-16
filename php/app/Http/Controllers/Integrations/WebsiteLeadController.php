<?php

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Services\Integrations\WebsiteLeads;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** POST /hooks/website-leads — заявки с сайта fluggi.uz (JSON, Authorization: Bearer <WEBSITE_LEADS_TOKEN>). */
class WebsiteLeadController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $token = (string) config('fluggi.website.leads_token');
        $given = (string) $request->bearerToken();
        if ($token === '' || $given === '' || ! hash_equals($token, $given)) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }
        $body = $request->json()->all();
        // Ключ защиты от дублей: external_id, а при его отсутствии — заголовок Idempotency-Key
        if (empty($body['external_id']) && $request->header('Idempotency-Key')) {
            $body['external_id'] = (string) $request->header('Idempotency-Key');
        }
        $v = Validator::make($body, [
            'external_id' => ['required', 'string', 'max:191'],
            'source' => ['nullable', 'string', 'max:100'],
            'type' => ['required', Rule::in(WebsiteLeads::TYPES)],
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'phone' => ['required', 'string', 'regex:/^\+\d{7,15}$/'],
            'email' => ['nullable', 'email', 'max:191'],
            'company' => ['nullable', 'string', 'max:191'],
            'message' => ['nullable', 'string', 'max:2000'],
            'product' => ['nullable', 'array'],
            'product.kind' => ['nullable', 'string', 'max:32'],
            'product.slug' => ['nullable', 'string', 'max:191'],
            'product.name' => ['nullable', 'string', 'max:191'],
            'context' => ['nullable', 'array'],
            'context.page_url' => ['nullable', 'string', 'max:1000'],
            'context.locale' => ['nullable', 'string', 'max:5'],
            'context.demo_seconds' => ['nullable', 'integer', 'min:0'],
            'utm' => ['nullable', 'array'],
            'utm_last' => ['nullable', 'array'],
            'created_at' => ['nullable', 'string', 'max:40'],
        ]);
        if ($v->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $v->errors()], 422);
        }
        $data = $v->validated();
        foreach (['utm', 'utm_last'] as $k) {
            $data[$k] = is_array($data[$k] ?? null) ? array_map(fn ($x) => is_scalar($x) ? mb_substr((string) $x, 0, 300) : null, array_slice($data[$k], 0, 10, true)) : null;
        }
        $res = WebsiteLeads::receive($data);

        return response()->json(['id' => $res['id'], 'duplicate' => $res['duplicate']], $res['existing'] ? 409 : 201);
    }
}
