<?php

namespace App\Services\Finance;

use App\Domain\Finance;
use App\Domain\Tasks;
use App\Support\Audit;
use App\Support\Settings;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Накладные расходы (аренда, офис — категории с отметкой «накладные») делятся на проекты,
 * бывшие в работе в этом месяце, или на заданный в настройках делитель. Это управленческая
 * аналитика проекта: в расходах компании накладные остаются как есть.
 */
final class OverheadService
{
    private const KEY = 'finance';

    /** @return array{overheadDivisor:?int} */
    public static function settings(): array
    {
        $d = Settings::get(self::KEY)['overheadDivisor'] ?? null;

        return ['overheadDivisor' => is_numeric($d) && (int) $d >= 1 && (int) $d <= 1000 ? (int) $d : null];
    }

    public static function saveSettings(?int $divisor): array
    {
        DB::transaction(function () use ($divisor) {
            $before = self::settings();
            Settings::save(self::KEY, ['overheadDivisor' => $divisor]);
            Audit::log('settings.finance', 'setting', null, ['overheadDivisor' => ['old' => $before['overheadDivisor'], 'new' => $divisor]]);
        });

        return self::settings();
    }

    /** Месяцы YYYY-MM от from до to включительно. */
    public static function monthsBetween(string $from, string $to): array
    {
        $out = [];
        [$y, $m] = array_map('intval', explode('-', $from));
        [$ty, $tm] = array_map('intval', explode('-', $to));
        while ($y < $ty || ($y === $ty && $m <= $tm)) {
            $out[] = sprintf('%04d-%02d', $y, $m);
            if (++$m > 12) {
                $m = 1;
                $y++;
            }
        }

        return $out;
    }

    /** Накладные по месяцам, UZS. */
    private static function overheadByMonth(array $months): array
    {
        if (! $months) {
            return [];
        }
        $first = $months[0].'-01';
        $last = CarbonImmutable::createFromFormat('!Y-m', end($months))->addMonthNoOverflow()->format('Y-m-d');
        $rows = DB::table('expenses as e')->join('finance_categories as c', 'c.code', '=', 'e.category')
            ->whereNull('e.deleted_at')->where('e.scope', 'COMPANY')->where('c.is_overhead', true)
            ->where('e.expense_date', '>=', $first)->where('e.expense_date', '<', $last)
            ->select(DB::raw("DATE_FORMAT(e.expense_date, '%Y-%m') AS m"), DB::raw('SUM(e.amount_uzs) AS s'))->groupBy('m')->get();

        return $rows->pluck('s', 'm')->map(fn ($v) => (string) $v)->all();
    }

    /** Число проектов в работе по месяцам. */
    private static function activeByMonth(array $months): array
    {
        if (! $months) {
            return [];
        }
        $range = fn (string $m) => [
            CarbonImmutable::createFromFormat('!Y-m', $m, Period::TZ)->utc(),
            CarbonImmutable::createFromFormat('!Y-m', $m, Period::TZ)->addMonthNoOverflow()->utc(),
        ];
        $firstFrom = $range($months[0])[0];
        $lastTo = $range(end($months))[1];
        $projects = DB::table('projects')->whereNull('deleted_at')->where('status', '<>', 'CANCELLED')->where('created_at', '<', $lastTo)
            ->where(fn ($q) => $q->whereNull('completed_at')->orWhere('completed_at', '>=', $firstFrom))->get(['created_at', 'completed_at']);
        $out = [];
        foreach ($months as $m) {
            [$from, $to] = $range($m);
            $out[$m] = $projects->filter(fn ($p) => CarbonImmutable::parse($p->created_at, 'UTC') < $to
                && (! $p->completed_at || CarbonImmutable::parse($p->completed_at, 'UTC') >= $from))->count();
        }

        return $out;
    }

    /**
     * Доля накладных для каждого проекта за все месяцы его работы (до сегодня).
     *
     * @param  iterable<object{id:string,created_at:mixed,completed_at:mixed,status:string}>  $projects
     * @return array<string,BigDecimal>
     */
    public static function forProjects(iterable $projects, ?CarbonImmutable $now = null): array
    {
        $today = substr(Tasks::companyDate($now ?? CarbonImmutable::now()), 0, 7);
        $spans = [];
        foreach ($projects as $p) {
            $spans[$p->id] = $p->status === 'CANCELLED' ? [] : self::monthsBetween(
                substr(Tasks::companyDate(CarbonImmutable::parse($p->created_at, 'UTC')), 0, 7),
                $p->completed_at ? substr(Tasks::companyDate(CarbonImmutable::parse($p->completed_at, 'UTC')), 0, 7) : $today,
            );
        }
        if (! $spans) {
            return [];
        }
        $all = array_values(array_unique(array_merge(...array_values($spans))));
        sort($all);
        $overhead = self::overheadByMonth($all);
        $active = self::activeByMonth($all);
        $divisor = self::settings()['overheadDivisor'];
        $out = [];
        foreach ($spans as $id => $months) {
            $acc = BigDecimal::zero();
            foreach ($months as $m) {
                $acc = $acc->plus(Finance::overheadShare($overhead[$m] ?? '0', $active[$m] ?? 1, $divisor));
            }
            $out[$id] = $acc;
        }

        return $out;
    }

    /** Оценка накладных на один проект в месяц — по последнему полному месяцу (для экономики тарифа). */
    public static function monthlyShareEstimate(?CarbonImmutable $now = null): string
    {
        $m = CarbonImmutable::createFromFormat('!Y-m', substr(Tasks::companyDate($now ?? CarbonImmutable::now()), 0, 7))->subMonthNoOverflow()->format('Y-m');

        return Finance::overheadShare(self::overheadByMonth([$m])[$m] ?? '0', self::activeByMonth([$m])[$m] ?? 1, self::settings()['overheadDivisor']);
    }
}
