<?php

namespace App\Http\Controllers\Analytics;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Analytics\AnalyticsService;
use App\Services\Analytics\ClientInsights;
use App\Services\Analytics\Period;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Аналитика (ТЗ §39–43). Вкладка и период — в адресе (?tab=…&period=…). */
class AnalyticsController extends Controller
{
    public const TABS = ['sales', 'funnel', 'sources', 'services', 'losses', 'forecast', 'clients'];

    public function index(Request $request): View
    {
        $a = access();
        $tab = in_array($request->query('tab'), self::TABS, true) ? $request->query('tab') : 'sales';
        $period = in_array($request->query('period'), Period::PRESETS, true) ? $request->query('period') : 'month';
        [$from, $to] = Period::resolve($period, $request->query('from'), $request->query('to'));
        $wide = $a->scope('analytics.read') !== 'OWN';
        $userId = $request->query('user');
        $users = $wide && $a->can('employee.read')
            ? User::where('status', 'ACTIVE')->whereHas('role', fn ($q) => $q->where('code', 'MANAGER'))->orderBy('full_name')->pluck('full_name', 'id')->all()
            : [];
        $userId = is_string($userId) && preg_match('/^[0-9a-f-]{36}$/i', $userId) ? $userId : null;
        $svc = new AnalyticsService(null, $userId);
        $data = match ($tab) {
            'sales' => $svc->sales($from, $to),
            'funnel' => $svc->funnel($from, $to),
            'sources' => $svc->breakdown($from, $to, 'source'),
            'services' => $svc->breakdown($from, $to, 'service'),
            'losses' => $svc->losses($from, $to),
            'forecast' => $svc->forecast(),
            'clients' => ClientInsights::list(
                in_array($request->query('sort'), ['ltv', 'health', 'lastPayment'], true) ? $request->query('sort') : 'ltv',
                in_array($request->query('health'), ClientInsights::LEVELS, true) ? $request->query('health') : null,
                max(1, (int) $request->query('page', 1)),
            ),
        };

        $qualification = $tab === 'losses' ? $svc->qualification($from, $to) : null;

        return view('analytics.index', compact('tab', 'period', 'from', 'to', 'users', 'userId', 'data', 'qualification') + [
            'fromInput' => $request->query('from'), 'toInput' => $request->query('to'),
        ]);
    }

    /** LTV и здоровье одного клиента — для карточки клиента (чужой — 404). */
    public function insight(string $id): JsonResponse
    {
        $m = ClientInsights::one($id);
        $m['health']['reasons'] = ClientInsights::reasonTexts($m['health']['reasons']);
        foreach (['firstPaymentAt', 'lastPaymentAt', 'lastContactAt'] as $k) {
            $m[$k] = $m[$k]?->toIso8601ZuluString('millisecond');
        }

        return response()->json($m);
    }
}
