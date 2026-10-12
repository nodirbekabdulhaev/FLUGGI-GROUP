<?php

namespace App\Http\Controllers\Automation;

use App\Http\Controllers\Controller;
use App\Models\JobRun;
use App\Services\Automation\FollowUpService;
use App\Services\Automation\ReportService;
use App\Services\Automation\Scheduler;
use App\Support\Audit;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Автоматизация (ТЗ §38, §41, §54–57): настройки, планировщик, отчёты, реквизиты компании. */
class AutomationController extends Controller
{
    public const TAX_REGIMES = ['IT_PARK', 'TURNOVER', 'GENERAL', 'OTHER'];

    public function index(Request $request): View
    {
        $runs = JobRun::orderByDesc('started_at')->limit(300)->get();
        $jobs = array_map(fn ($name) => ['name' => $name, 'last' => $runs->firstWhere('job', $name)], Scheduler::names());
        $kind = $request->query('report') === 'weekly' ? 'weekly' : 'daily';

        return view('automation.settings', [
            'settings' => Settings::automation(),
            'company' => Settings::company(),
            'jobs' => $jobs,
            'kind' => $kind,
            'preview' => strip_tags($kind === 'weekly' ? ReportService::weekly(null) : ReportService::daily(null)),
        ]);
    }

    public function save(Request $request): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'largeAmountUzs' => ['required', 'integer', 'min:0', 'max:10000000000000'],
            'followUps' => ['nullable', 'array', 'max:10'],
            'followUps.*.days' => ['required', 'integer', 'min:1', 'max:730'],
            'followUps.*.kind' => ['required', Rule::in(FollowUpService::KINDS)],
            'dailyReport' => ['sometimes', 'boolean'],
            'weeklyReport' => ['sometimes', 'boolean'],
        ]);
        $value = [
            'largeAmountUzs' => (int) $data['largeAmountUzs'],
            'followUps' => array_values(array_map(fn ($f) => ['days' => (int) $f['days'], 'kind' => $f['kind']], $data['followUps'] ?? [])),
            'dailyReport' => $request->boolean('dailyReport'),
            'weeklyReport' => $request->boolean('weeklyReport'),
        ];
        $after = DB::transaction(function () use ($value) {
            $before = Settings::automation();
            $after = Settings::save('automation', $value);
            Audit::log('settings.automation', 'setting', null, ['automation' => ['old' => $before, 'new' => $after]]);

            return $after;
        });

        return $request->expectsJson() ? response()->json($after) : back()->with('ok', t('automation.saved'));
    }

    /** Реквизиты компании — в КП, договорах и пакете для бухгалтера. */
    public function saveCompany(Request $request): JsonResponse|RedirectResponse
    {
        $s = fn (int $max) => ['nullable', 'string', 'max:'.$max];
        $data = $request->validate([
            'name' => $s(200), 'inn' => ['nullable', 'regex:/^(\d{9})?$/'], 'director' => $s(120), 'accountant' => $s(120),
            'taxRegime' => ['required', Rule::in(self::TAX_REGIMES)], 'legalName' => $s(300), 'directorPosition' => $s(120),
            'signerGenitive' => $s(200), 'basis' => $s(200), 'address' => $s(300), 'phone' => $s(60), 'email' => $s(120),
            'website' => $s(120), 'bank' => $s(200), 'mfo' => ['nullable', 'regex:/^(\d{5})?$/'], 'account' => ['nullable', 'regex:/^(\d{20})?$/'],
            'oked' => $s(10), 'vatCode' => $s(20),
        ], ['inn.regex' => t('company.errors.inn'), 'mfo.regex' => t('company.errors.mfo'), 'account.regex' => t('company.errors.account')]);
        $value = array_replace(Settings::DEFAULTS['company'], array_map(fn ($v) => trim((string) $v), array_intersect_key($data, Settings::DEFAULTS['company'])));
        $after = DB::transaction(function () use ($value) {
            $before = Settings::company();
            $after = Settings::save('company', $value);
            Audit::log('settings.company', 'setting', null, ['company' => ['old' => $before, 'new' => $after]]);

            return $after;
        });

        return $request->expectsJson() ? response()->json($after) : back()->with('ok', t('company.saved'));
    }

    /** Ручной запуск задачи планировщика (отдельный слот). */
    public function run(Request $request, string $name): JsonResponse|RedirectResponse
    {
        $r = Scheduler::runNow($name);
        abort_if(! $r, 404);
        $label = t('automation.jobs.'.$name.'.label');
        if ($request->expectsJson()) {
            return response()->json(['job' => $r->job, 'error' => $r->error, 'result' => $r->result]);
        }

        return $r->error ? back()->with('error', $r->error) : back()->with('ok', t('automation.done', ['name' => $label]));
    }

    /** Предпросмотр отчёта: CEO — компания, РОП — свой отдел. */
    public function preview(Request $request): JsonResponse
    {
        $a = access();
        $ceo = $a->can('dashboard.ceo', 'ALL');
        abort_unless($ceo || ($a->roleCode === 'ROP' && $a->headedTeamIds), 403);
        $teams = $ceo ? null : $a->headedTeamIds;
        $text = $request->query('kind') === 'weekly' ? ReportService::weekly($teams) : ReportService::daily($teams);

        return response()->json(['text' => $text]);
    }
}
