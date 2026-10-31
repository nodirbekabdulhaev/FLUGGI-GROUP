<?php

namespace App\Services\Analytics;

use App\Domain\Analytics;
use App\Domain\Tasks;
use App\Models\Client;
use App\Services\Automation\ReportService;
use App\Services\Crm\CrmAccess;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/** LTV и «здоровье» клиентов (ТЗ §43). Считается по факту: оплаты, контакты, просрочки, проекты. */
final class ClientInsights
{
    public const LEVELS = ['HEALTHY', 'ATTENTION', 'RISK', 'LOST'];

    private const LEVEL_ORDER = ['RISK' => 0, 'ATTENTION' => 1, 'LOST' => 2, 'HEALTHY' => 3];

    /**
     * Показатели для набора клиентов.
     *
     * @return array<string,array{id:string,ltv:string,paidDeals:int,avgCheck:string,firstPaymentAt:?CarbonImmutable,lastPaymentAt:?CarbonImmutable,lastContactAt:?CarbonImmutable,health:array}>
     */
    public static function compute(array $clientIds, ?DateTimeInterface $now = null): array
    {
        $out = [];
        if (! $clientIds) {
            return $out;
        }
        $now = CarbonImmutable::instance($now ?? now())->utc();
        $today = Tasks::companyDate($now);
        $out = [];
        foreach (array_chunk($clientIds, 1000) as $ids) {
            $out += self::chunk($ids, $now, $today);
        }

        return $out;
    }

    private static function chunk(array $ids, CarbonImmutable $now, string $today): array
    {
        $clients = DB::table('clients')->whereIn('id', $ids)->get(['id', 'created_at']);
        $payments = DB::table('payments')->whereIn('client_id', $ids)->where('status', 'PAID')
            ->groupBy('client_id', 'type')->selectRaw('client_id, type, SUM(amount_uzs) AS s, MIN(paid_at) AS mn, MAX(paid_at) AS mx')->get()->groupBy('client_id');
        $paidDeals = DB::table('payments')->whereIn('client_id', $ids)->where('status', 'PAID')->where('type', '<>', 'REFUND')
            ->groupBy('client_id')->selectRaw('client_id, COUNT(DISTINCT deal_id) AS n')->pluck('n', 'client_id');
        // Последний контакт: активность по клиенту, его сделкам и лидам, проведённая встреча или оплата
        $in = implode(',', array_fill(0, count($ids), '?'));
        $contacts = collect(DB::select("SELECT client_id, MAX(at) AS at FROM (
                SELECT COALESCE(a.client_id, d.client_id, l.client_id) AS client_id, a.created_at AS at
                  FROM activities a LEFT JOIN deals d ON d.id = a.deal_id LEFT JOIN leads l ON l.id = a.lead_id
                UNION ALL
                SELECT COALESCE(m.client_id, d.client_id), m.starts_at
                  FROM meetings m LEFT JOIN deals d ON d.id = m.deal_id WHERE m.status = 'DONE'
                UNION ALL
                SELECT client_id, paid_at FROM payments WHERE status = 'PAID'
            ) x WHERE client_id IN ($in) GROUP BY client_id", $ids))->pluck('at', 'client_id');
        $overdue = DB::table('payments')->whereIn('client_id', $ids)->where('status', 'PENDING')->where('type', '<>', 'REFUND')
            ->where('due_date', '<', $today)->groupBy('client_id')->selectRaw('client_id, COUNT(*) AS n')->pluck('n', 'client_id');
        $projects = DB::table('projects')->whereIn('client_id', $ids)->whereNull('deleted_at')
            ->whereIn('status', ReportService::ACTIVE_PROJECT)->get(['client_id', 'deadline'])->groupBy('client_id');
        $openDeals = DB::table('deals')->whereIn('client_id', $ids)->whereNull('deleted_at')->where('status', 'OPEN')
            ->groupBy('client_id')->selectRaw('client_id, COUNT(*) AS n')->pluck('n', 'client_id');
        $lost = DB::table('deals')->whereIn('client_id', $ids)->where('status', 'LOST')
            ->where('closed_at', '>=', Tasks::addDays($today, -90))->groupBy('client_id')->selectRaw('client_id, COUNT(*) AS n')->pluck('n', 'client_id');

        $daysSince = fn ($d) => $d ? max(0, intdiv($now->getTimestamp() - CarbonImmutable::parse($d, 'UTC')->getTimestamp(), 86400)) : null;
        $out = [];
        foreach ($clients as $c) {
            $mine = $payments->get($c->id, collect());
            $ltv = BigDecimal::zero();
            $first = null;
            $last = null;
            foreach ($mine as $p) {
                $s = BigDecimal::of((string) $p->s);
                $ltv = $p->type === 'REFUND' ? $ltv->minus($s) : $ltv->plus($s);
                if ($p->type !== 'REFUND') {
                    $first = $first === null || ($p->mn && $p->mn < $first) ? $p->mn : $first;
                    $last = $last === null || ($p->mx && $p->mx > $last) ? $p->mx : $last;
                }
            }
            $deals = (int) ($paidDeals[$c->id] ?? 0);
            $contact = $contacts[$c->id] ?? null;
            $active = $projects->get($c->id, collect());
            $health = Analytics::clientHealth([
                'daysSinceContact' => $daysSince($contact),
                'ageDays' => $daysSince($c->created_at) ?? 0,
                'overduePayments' => (int) ($overdue[$c->id] ?? 0),
                'overdueProjects' => $active->filter(fn ($p) => $p->deadline && $p->deadline < $today)->count(),
                'activeProjects' => $active->count(),
                'openDeals' => (int) ($openDeals[$c->id] ?? 0),
                'paidDeals' => $deals,
                'recentLostDeals' => (int) ($lost[$c->id] ?? 0),
            ]);
            $out[$c->id] = [
                'id' => $c->id,
                'ltv' => (string) $ltv->toScale(2),
                'paidDeals' => $deals,
                'avgCheck' => (string) ($deals ? $ltv->dividedBy($deals, 2, RoundingMode::HalfUp) : BigDecimal::zero()->toScale(2)),
                'firstPaymentAt' => $first ? CarbonImmutable::parse($first, 'UTC') : null,
                'lastPaymentAt' => $last ? CarbonImmutable::parse($last, 'UTC') : null,
                'lastContactAt' => $contact ? CarbonImmutable::parse($contact, 'UTC') : null,
                'health' => $health,
            ];
        }

        return $out;
    }

    /** Причины здоровья — на языке интерфейса (домен формулирует их по-русски). */
    public static function reasonTexts(array $reasons): array
    {
        $map = [
            '/^Нет контакта (\d+) дн\., ничего в работе$/u' => 'silentIdle',
            '/^Нет контакта (\d+) дн\.$/u' => 'silent',
            '/^Просрочено оплат: (\d+)$/u' => 'overduePayments',
            '/^Просрочен проект$/u' => 'overdueProject',
            '/^Проиграна сделка за 90 дней$/u' => 'lostDeal',
            '/^Повторные покупки: (\d+)$/u' => 'repeat',
            '/^Проектов в работе: (\d+)$/u' => 'activeProjects',
        ];

        return array_map(function (string $r) use ($map) {
            foreach ($map as $re => $key) {
                if (preg_match($re, $r, $m)) {
                    return t('analytics.reasons.'.$key, ['n' => $m[1] ?? '']);
                }
            }

            return $r;
        }, $reasons);
    }

    /**
     * Список клиентов с LTV и здоровьем в области analytics.read.
     *
     * @return array{items:list<array>,total:int,page:int,perPage:int,summary:array}
     */
    public static function list(string $sort, ?string $health, int $page, int $perPage = 25): array
    {
        $clients = CrmAccess::clients('analytics.read')->with('owner:id,full_name')->limit(5000)->get(['id', 'name', 'owner_id']);
        $metrics = self::compute($clients->pluck('id')->all());
        $all = $clients->map(fn ($c) => $metrics[$c->id] + ['name' => $c->name, 'owner' => $c->owner?->full_name])->values()->all();
        $byHealth = array_fill_keys(self::LEVELS, 0);
        foreach ($all as $c) {
            $byHealth[$c['health']['level']]++;
        }
        $paying = array_values(array_filter($all, fn ($c) => $c['paidDeals'] > 0));
        $filtered = array_values(array_filter($all, fn ($c) => ! $health || $c['health']['level'] === $health));
        usort($filtered, match ($sort) {
            'health' => fn ($a, $b) => (self::LEVEL_ORDER[$a['health']['level']] <=> self::LEVEL_ORDER[$b['health']['level']]) ?: ($a['health']['score'] <=> $b['health']['score']),
            'lastPayment' => fn ($a, $b) => strcmp((string) $b['lastPaymentAt']?->toIso8601String(), (string) $a['lastPaymentAt']?->toIso8601String()),
            default => fn ($a, $b) => BigDecimal::of($b['ltv'])->compareTo(BigDecimal::of($a['ltv'])),
        });
        $sum = array_reduce($paying, fn ($s, $c) => $s->plus($c['ltv']), BigDecimal::zero());

        return [
            'items' => array_slice($filtered, ($page - 1) * $perPage, $perPage),
            'total' => count($filtered),
            'page' => $page,
            'perPage' => $perPage,
            'summary' => [
                'clients' => count($all),
                'avgLtv' => (string) ($paying ? $sum->dividedBy(count($paying), 2, RoundingMode::HalfUp) : BigDecimal::zero()->toScale(2)),
                'repeatRate' => $paying ? round(count(array_filter($paying, fn ($c) => $c['paidDeals'] >= 2)) / count($paying) * 1000) / 10 : 0,
                'byHealth' => $byHealth,
            ],
        ];
    }

    /** Показатели одного клиента (карточка клиента): чужой — 404. */
    public static function one(string $clientId): array
    {
        $c = CrmAccess::client($clientId);

        return self::compute([$c->id])[$c->id];
    }

    /**
     * Пересчёт уровня здоровья всех клиентов (задача планировщика). Возвращает клиентов,
     * которые только что перешли в «Риск», — их менеджеру уходит уведомление.
     */
    public static function refreshAll(?DateTimeInterface $now = null): array
    {
        $clients = Client::get(['id', 'name', 'owner_id', 'health']);
        $metrics = self::compute($clients->pluck('id')->all(), $now);
        $toRisk = [];
        $changed = 0;
        foreach ($clients as $c) {
            $h = $metrics[$c->id]['health'];
            if ($h['level'] === $c->health) {
                continue;
            }
            Client::whereKey($c->id)->update(['health' => $h['level']]);
            $changed++;
            if ($h['level'] === 'RISK') {
                $toRisk[] = ['id' => $c->id, 'name' => $c->name, 'ownerId' => $c->owner_id, 'reasons' => $h['reasons']];
            }
        }

        return ['changed' => $changed, 'toRisk' => $toRisk];
    }
}
