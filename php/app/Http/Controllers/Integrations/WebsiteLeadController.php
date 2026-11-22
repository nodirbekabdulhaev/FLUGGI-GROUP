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
            'candidate' => ['nullable', 'array'],
            'candidate.photo_url' => ['nullable', 'url:https', 'max:500'],
            'candidate.about' => ['nullable', 'string', 'max:3000'],
            'candidate.city' => ['nullable', 'string', 'max:100'],
            'candidate.birth_year' => ['nullable', 'integer', 'min:1940', 'max:2020'],
            'candidate.experience_years' => ['nullable', 'numeric', 'min:0', 'max:60'],
            'candidate.education' => ['nullable', 'array', 'max:20'],
            'candidate.skills' => ['nullable', 'array', 'max:30'],
            'candidate.skills.*.name' => ['required_with:candidate.skills', 'string', 'max:60'],
            'candidate.skills.*.level' => ['nullable', 'numeric', 'min:1', 'max:5'],
            'candidate.languages' => ['nullable', 'array', 'max:10'],
            'candidate.languages.*.code' => ['required_with:candidate.languages', 'string', 'max:5'],
            'candidate.languages.*.level' => ['nullable', 'string', 'max:10'],
            'candidate.experience' => ['nullable', 'array', 'max:20'],
            'candidate.links' => ['nullable', 'array', 'max:5'],
            'candidate.links.*' => ['url:http,https', 'max:500'],
            'assessment' => ['nullable', 'array'],
            'assessment.score' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'assessment.grade' => ['nullable', 'string', 'max:2'],
            'assessment.recommendation' => ['nullable', 'string', 'max:30'],
            'assessment.archetype' => ['nullable', 'string', 'max:40'],
            'assessment.level' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'assessment.badges' => ['nullable', 'array', 'max:20'],
            'assessment.badges.*' => ['string', 'max:40'],
            'assessment.flags' => ['nullable', 'array', 'max:20'],
            'assessment.flags.*' => ['string', 'max:40'],
            'assessment.experience' => ['nullable', 'string', 'max:40'],
            'assessment.skills' => ['nullable', 'array', 'max:30'],
            'assessment.skills.*' => ['string', 'max:60'],
            'assessment.motivators' => ['nullable', 'array', 'max:20'],
            'assessment.motivators.*' => ['string', 'max:60'],
            'assessment.city' => ['nullable', 'string', 'max:100'],
            'assessment.link' => ['nullable', 'string', 'max:500'],
            'assessment.parts' => ['nullable', 'array', 'max:10'],
            'assessment.parts.*' => ['numeric', 'min:0', 'max:100'],
            'assessment.test_name' => ['nullable', 'string', 'max:100'],
            'assessment.duration_seconds' => ['nullable', 'integer', 'min:0'],
            'assessment.traits' => ['nullable', 'array', 'max:20'],
            'assessment.traits.*' => ['numeric', 'min:0', 'max:100'],
            'utm' => ['nullable', 'array'],
            'utm_last' => ['nullable', 'array'],
            'created_at' => ['nullable', 'string', 'max:40'],
        ]);
        if ($v->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $v->errors()], 422);
        }
        $data = $v->validated();
        // Образование и опыт: ключи записей у сайта могут быть любыми — сохраняем только скалярные значения, длину ограничиваем
        foreach (['education', 'experience'] as $k) {
            if (isset($data['candidate'][$k]) && is_array($data['candidate'][$k])) {
                $data['candidate'][$k] = collect($data['candidate'][$k])->filter(fn ($x) => is_array($x))->take(20)->map(fn (array $row) => collect($row)->filter(fn ($x) => is_scalar($x) && $x !== '')
                    ->map(fn ($x) => is_string($x) ? mb_substr($x, 0, 300) : $x)->take(12)->all())->values()->all();
            }
        }
        foreach (['utm', 'utm_last'] as $k) {
            $data[$k] = is_array($data[$k] ?? null) ? array_map(fn ($x) => is_scalar($x) ? mb_substr((string) $x, 0, 300) : null, array_slice($data[$k], 0, 10, true)) : null;
        }
        $res = WebsiteLeads::receive($data);

        return response()->json(['id' => $res['id'], 'duplicate' => $res['duplicate']], $res['existing'] ? 409 : 201);
    }
}
