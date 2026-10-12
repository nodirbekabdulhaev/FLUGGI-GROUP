<?php

namespace App\Http\Controllers\People;

use App\Domain\People;
use App\Http\Controllers\Controller;
use App\Services\Finance\Period;
use App\Services\People\KpiService;
use App\Services\People\PeopleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** KPI сотрудников (ТЗ §28–31): таблица по ролям за месяц и цели. */
class KpiController extends Controller
{
    public function index(Request $request): View
    {
        $period = Period::month($request->query('period'));
        $range = People::monthRange($period);
        $rows = KpiService::rows($period, $range['from'], $range['to']);
        $groups = array_values(array_filter(KpiService::GROUPS, fn ($g) => collect($rows)->contains('role', $g)));
        $group = in_array($request->query('group'), $groups, true) ? $request->query('group') : ($groups[0] ?? 'MANAGER');
        $data = ['period' => $period, 'groups' => $groups, 'group' => $group, 'rows' => array_values(array_filter($rows, fn ($r) => $r['role'] === $group))];
        if ($id = $request->query('targets')) {
            $data['editing'] = collect($rows)->first(fn ($r) => $r['user']->id === $id) ?? abort(404);
        }

        return view('people.kpi', $data);
    }

    public function targets(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'uuid'],
            'period' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'targets' => ['array', 'max:5'],
            'targets.*.metric' => ['required', Rule::in(KpiService::METRICS)],
            'targets.*.value' => ['nullable', 'string', 'max:30'],
            'targets.*.currency' => ['nullable', Rule::in(['UZS', 'USD'])],
        ]);
        $targets = array_map(fn ($t) => [
            'metric' => $t['metric'],
            'value' => isset($t['value']) && trim($t['value']) !== '' ? str_replace([' ', "\u{00A0}", ','], ['', '', '.'], $t['value']) : null,
            'currency' => $t['currency'] ?? 'UZS',
        ], array_values($data['targets'] ?? []));
        PeopleService::setTargets($data['user_id'], $data['period'], $targets);

        return redirect('/kpi?period='.$data['period'].'&group='.$request->input('group'))->with('ok', t('kpi.targetsSaved'));
    }
}
