<?php

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Models\LeadSource;
use App\Models\Service;
use App\Models\SmmPublication;
use App\Models\Team;
use App\Models\User;
use App\Services\Integrations\FormService;
use App\Services\Integrations\IntakeService;
use App\Services\Integrations\MetaService;
use App\Services\Smm\InstagramApi;
use App\Services\Smm\SmmMedia;
use App\Support\Audit;
use App\Support\Format;
use App\Support\Lang;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Настройки → «Интеграции»: формы для сайта, Instagram/Facebook и таргет (только CEO). */
class IntegrationController extends Controller
{
    private static function name(object $r): string
    {
        return Lang::name($r);
    }

    public function index(): View
    {
        return view('integrations.settings', [
            'forms' => FormService::list(),
            'meta' => MetaService::status(),
            'instagram' => InstagramApi::settings() + [
                'tokenSet' => (bool) InstagramApi::token(),
                'diskUsage' => SmmMedia::human(SmmMedia::diskUsage()),
                'scheduled' => SmmPublication::whereIn('status', ['SCHEDULED', 'PUBLISHING'])->count(),
                'published' => SmmPublication::where('status', 'PUBLISHED')->count(),
            ],
            'settings' => IntakeService::settings(),
            'services' => Service::where('is_active', true)->orderBy('sort')->get()->mapWithKeys(fn ($s) => [$s->id => self::name($s)])->all(),
            'sources' => LeadSource::where('is_active', true)->orderBy('sort')->get()->mapWithKeys(fn ($s) => [$s->id => self::name($s)])->all(),
            'owners' => User::where('status', 'ACTIVE')->whereHas('role', fn ($q) => $q->whereIn('code', ['MANAGER', 'ROP']))->orderBy('full_name')->pluck('full_name', 'id')->all(),
            'teams' => Team::orderBy('name')->pluck('name', 'id')->all(),
            'defaultFields' => FormService::DEFAULT_FIELDS,
        ]);
    }

    private function formInput(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:1000'],
            'button_text' => ['nullable', 'string', 'max:60'],
            'success_message' => ['nullable', 'string', 'max:500'],
            'fields' => ['required', 'array', 'min:1', 'max:20'],
            'fields.*.key' => ['required', 'string', 'max:40', 'regex:/^[a-z][a-z0-9_]*$/'],
            'fields.*.label' => ['required', 'string', 'max:120'],
            'fields.*.type' => ['required', Rule::in(FormService::FIELD_TYPES)],
            'fields.*.required' => ['sometimes', 'boolean'],
            'fields.*.options' => ['nullable'],
            'service_id' => ['nullable', 'uuid', 'exists:services,id'],
            'source_id' => ['nullable', 'uuid', 'exists:lead_sources,id'],
            'owner_id' => ['nullable', 'uuid', 'exists:users,id'],
            'team_id' => ['nullable', 'uuid', 'exists:teams,id'],
            'is_active' => ['sometimes', 'boolean'],
        ], ['fields.*.key.regex' => t('integrations.errors.keyFormat')]) + ['is_active' => $request->boolean('is_active')];
    }

    public function storeForm(Request $request): JsonResponse|RedirectResponse
    {
        $f = FormService::save(null, $this->formInput($request));

        return $request->expectsJson() ? response()->json(['id' => $f->id, 'key' => $f->key], 201) : back()->with('ok', t('integrations.formSaved'));
    }

    public function updateForm(Request $request, string $id): JsonResponse|RedirectResponse
    {
        $f = FormService::save($id, $this->formInput($request));

        return $request->expectsJson() ? response()->json(['id' => $f->id, 'key' => $f->key]) : back()->with('ok', t('integrations.formSaved'));
    }

    public function destroyForm(Request $request, string $id): JsonResponse|RedirectResponse
    {
        FormService::remove($id);

        return $request->expectsJson() ? response()->json(null, 204) : back()->with('ok', t('integrations.formDeleted'));
    }

    public function submissions(string $id): JsonResponse
    {
        return response()->json(FormService::submissions($id)->map(fn ($s) => [
            'id' => $s->id, 'data' => $s->data, 'utm' => $s->utm, 'page' => $s->page, 'result' => $s->result,
            'resultLabel' => t('integrations.results.'.$s->result),
            'lead' => $s->lead ? ['id' => $s->lead->id, 'number' => Format::code('L', $s->lead->number)] : null,
            'createdAt' => dt($s->created_at),
        ])->all());
    }

    /** «Проверить доступ к публикации»: аккаунт Instagram, права токена, лимит публикаций (запрос к Meta). */
    public function checkInstagram(Request $request): JsonResponse|RedirectResponse
    {
        $r = InstagramApi::check();
        Audit::log('integrations.instagram_checked', 'setting', InstagramApi::SETTINGS_KEY, ['result' => ['old' => null, 'new' => $r['ok'] ? 'ok' : $r['error']]]);
        if ($request->expectsJson()) {
            return response()->json($r);
        }

        return $r['ok']
            ? back()->with('ok', t('smm.ig.checkOk', ['username' => $r['username'] ?? '—', 'usage' => $r['quotaUsage'] ?? 0, 'total' => $r['quotaTotal'] ?? 0]))
            : back()->with('error', $r['error']);
    }

    public function saveMeta(Request $request): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'ownerId' => ['nullable', 'uuid', 'exists:users,id'],
            'teamId' => ['nullable', 'uuid', 'exists:teams,id'],
            'autoLeadFromDirect' => ['sometimes', 'boolean'],
            'autoLeadFromComments' => ['required', Rule::in(['off', 'keywords', 'all'])],
            'commentKeywords' => ['nullable'],
            'serviceId' => ['nullable', 'uuid', 'exists:services,id'],
        ]);
        $kw = is_array($data['commentKeywords'] ?? null) ? $data['commentKeywords'] : explode(',', (string) ($data['commentKeywords'] ?? ''));
        $kw = array_slice(array_values(array_filter(array_map(fn ($k) => mb_substr(trim((string) $k), 0, 40), $kw), fn ($k) => mb_strlen($k) >= 2)), 0, 50);
        $settings = IntakeService::saveSettings([
            'ownerId' => $data['ownerId'] ?? null,
            'teamId' => $data['teamId'] ?? null,
            'autoLeadFromDirect' => $request->boolean('autoLeadFromDirect'),
            'autoLeadFromComments' => $data['autoLeadFromComments'],
            'commentKeywords' => $kw,
            'serviceId' => $data['serviceId'] ?? null,
        ], access()->id());

        return $request->expectsJson() ? response()->json($settings) : back()->with('ok', t('integrations.settingsSaved'));
    }
}
