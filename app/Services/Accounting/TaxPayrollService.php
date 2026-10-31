<?php

namespace App\Services\Accounting;

use App\Exceptions\BusinessRule;
use App\Models\AccPayroll;
use App\Models\AccPerson;
use App\Models\AccTransaction;
use App\Support\Audit;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Люди, оформленные для налоговой, и их зарплата за месяц: начислено (gross), НДФЛ, ИНПС, на руки.
 * По умолчанию НДФЛ 7,5 % и ИНПС 0,1 % удерживаются отдельно: на руки = gross − НДФЛ − ИНПС
 * (настройка inpsWithinNdfl: ИНПС в составе НДФЛ — на руки = gross − НДФЛ).
 */
final class TaxPayrollService
{
    private const PERSON_FIELDS = ['full_name', 'pinfl', 'position', 'monthly_salary', 'user_id', 'hired_at', 'fired_at', 'is_active', 'comment'];

    // ─────────────────────────── Люди ───────────────────────────

    public static function people(): Collection
    {
        return AccPerson::with('user:id,full_name')->orderByDesc('is_active')->orderBy('full_name')->get();
    }

    public static function savePerson(?string $id, array $data): AccPerson
    {
        $data['monthly_salary'] = (string) Accounting::dec($data['monthly_salary'] ?? '0')->toScale(2);
        foreach (['pinfl', 'position', 'user_id', 'hired_at', 'fired_at', 'comment'] as $k) {
            $data[$k] = isset($data[$k]) && trim((string) $data[$k]) !== '' ? trim((string) $data[$k]) : null;
        }
        if ($data['hired_at'] && $data['fired_at'] && $data['fired_at'] < $data['hired_at']) {
            throw new BusinessRule(t('accounting.errors.firedBeforeHired'), ['fired_at' => t('accounting.errors.firedBeforeHired')]);
        }

        return DB::transaction(function () use ($id, $data) {
            if ($data['pinfl'] && AccPerson::where('pinfl', $data['pinfl'])->when($id, fn ($q) => $q->whereKeyNot($id))->exists()) {
                throw new BusinessRule(t('accounting.errors.pinflTaken'), ['pinfl' => t('accounting.errors.pinflTaken')]);
            }
            $before = $id ? AccPerson::lockForUpdate()->findOrFail($id) : null;
            $old = $before ? $before->only(self::PERSON_FIELDS) + ['hired_at' => $before->hired_at?->toDateString(), 'fired_at' => $before->fired_at?->toDateString()] : [];
            $p = $before ? tap($before)->update($data) : AccPerson::create($data);
            Audit::log($before ? 'accounting.person.update' : 'accounting.person.create', 'acc_person', $p->id, Audit::diff($old, $data, self::PERSON_FIELDS));

            return $p;
        });
    }

    /** Удалить можно человека без начислений и выплат; иначе — сделать неактивным. */
    public static function deletePerson(string $id): void
    {
        DB::transaction(function () use ($id) {
            $p = AccPerson::lockForUpdate()->findOrFail($id);
            if (AccPayroll::where('person_id', $id)->exists() || AccTransaction::withTrashed()->where('person_id', $id)->exists()) {
                throw new BusinessRule(t('accounting.errors.personHasRecords'));
            }
            Audit::log('accounting.person.delete', 'acc_person', $id, ['full_name' => ['old' => $p->full_name, 'new' => null]]);
            $p->delete();
        });
    }

    // ─────────────────────────── Зарплата ───────────────────────────

    /** Доля месяца, когда человек был оформлен: [дней, дней в месяце]; null — не работал. */
    public static function employedDays(?string $hired, ?string $fired, string $period): ?array
    {
        $start = CarbonImmutable::createFromFormat('!Y-m', $period);
        $end = $start->endOfMonth()->startOfDay();
        $from = $hired && $hired > $start->toDateString() ? CarbonImmutable::parse($hired) : $start;
        $to = $fired && $fired < $end->toDateString() ? CarbonImmutable::parse($fired) : $end;
        if ($from > $to) {
            return null;
        }

        return [(int) $from->diffInDays($to) + 1, $start->daysInMonth];
    }

    /**
     * НДФЛ, ИНПС и «на руки» от начисленного.
     *
     * @return array{gross:string,ndfl_rate:string,ndfl:string,inps_rate:string,inps:string,net:string}
     */
    public static function amounts(BigDecimal $gross, array $s): array
    {
        $gross = Accounting::round($gross);
        $ndfl = Accounting::pct($gross, $s['ndflRate']);
        $inps = Accounting::pct($gross, $s['inpsRate']);
        $net = $gross->minus($ndfl);
        if (! $s['inpsWithinNdfl']) {
            $net = $net->minus($inps);
        }

        return ['gross' => (string) $gross, 'ndfl_rate' => (string) BigDecimal::of($s['ndflRate'])->toScale(3), 'ndfl' => (string) $ndfl,
            'inps_rate' => (string) BigDecimal::of($s['inpsRate'])->toScale(3), 'inps' => (string) $inps, 'net' => (string) $net->toScale(2)];
    }

    /**
     * «Рассчитать месяц»: черновики для каждого активного человека, оформленного в этом месяце
     * (оклад пропорционально календарным дням при приёме/увольнении в месяце). Утверждённые не меняются;
     * черновики тех, кто в месяце не работал, удаляются.
     *
     * @return array{created:int,updated:int,removed:int,approved:int}
     */
    public static function calculate(string $period): array
    {
        $s = Accounting::settings();
        [$a, $b] = Accounting::monthDates($period);

        return DB::transaction(function () use ($period, $s, $a, $b) {
            $res = ['created' => 0, 'updated' => 0, 'removed' => 0, 'approved' => 0];
            $existing = AccPayroll::where('period', $period)->lockForUpdate()->get()->keyBy('person_id');
            $eligible = [];
            $people = AccPerson::where('is_active', true)
                ->where(fn ($q) => $q->whereNull('hired_at')->orWhere('hired_at', '<', $b))
                ->where(fn ($q) => $q->whereNull('fired_at')->orWhere('fired_at', '>=', $a))->get();
            foreach ($people as $p) {
                $days = self::employedDays($p->hired_at?->toDateString(), $p->fired_at?->toDateString(), $period);
                if (! $days) {
                    continue;
                }
                $eligible[] = $p->id;
                $row = $existing[$p->id] ?? null;
                if ($row?->status === 'APPROVED') {
                    $res['approved']++;

                    continue;
                }
                $gross = Accounting::dec($p->monthly_salary);
                if ($days[0] < $days[1]) {
                    $gross = $gross->multipliedBy($days[0])->dividedBy($days[1], 2, RoundingMode::HalfUp);
                }
                $data = self::amounts($gross, $s);
                if ($row) {
                    $changes = Audit::diff($row, $data, array_keys($data));
                    $row->update($data);
                    if ($changes) {
                        $res['updated']++;
                        Audit::log('accounting.payroll.recalculate', 'acc_payroll', $row->id, $changes);
                    }
                } else {
                    $row = AccPayroll::create(['person_id' => $p->id, 'period' => $period, 'status' => 'DRAFT'] + $data);
                    $res['created']++;
                    Audit::log('accounting.payroll.create', 'acc_payroll', $row->id, ['period' => ['old' => null, 'new' => $period], 'gross' => ['old' => null, 'new' => $data['gross']]]);
                }
            }
            foreach ($existing as $personId => $row) {
                if ($row->status === 'DRAFT' && ! in_array($personId, $eligible, true)) {
                    Audit::log('accounting.payroll.remove', 'acc_payroll', $row->id, ['gross' => ['old' => (string) $row->gross, 'new' => null]]);
                    $row->delete();
                    $res['removed']++;
                }
            }

            return $res;
        });
    }

    /** Правка начисленного в черновике: налоги и «на руки» пересчитываются. */
    public static function updateGross(string $id, string $gross): AccPayroll
    {
        return DB::transaction(function () use ($id, $gross) {
            $row = AccPayroll::lockForUpdate()->findOrFail($id);
            if ($row->status !== 'DRAFT') {
                throw new BusinessRule(t('accounting.errors.payrollApproved'));
            }
            $data = self::amounts(Accounting::dec($gross), Accounting::settings());
            $changes = Audit::diff($row, $data, array_keys($data));
            $row->update($data);
            Audit::log('accounting.payroll.update', 'acc_payroll', $id, $changes);

            return $row;
        });
    }

    /** Утвердить черновики месяца (все или выбранные). После утверждения строки не меняются. */
    public static function approve(string $period, ?array $ids = null): int
    {
        return DB::transaction(function () use ($period, $ids) {
            $rows = AccPayroll::where('period', $period)->where('status', 'DRAFT')
                ->when($ids, fn ($q) => $q->whereIn('id', $ids))->lockForUpdate()->get();
            foreach ($rows as $row) {
                $row->update(['status' => 'APPROVED', 'approved_by_id' => access()->id(), 'approved_at' => now()]);
                Audit::log('accounting.payroll.approve', 'acc_payroll', $row->id, ['status' => ['old' => 'DRAFT', 'new' => 'APPROVED']]);
            }

            return $rows->count();
        });
    }

    /** Выплачено людям за месяц (расходы SALARY с этим периодом), UZS: [person_id => сумма]. */
    public static function paid(string $period): array
    {
        $out = [];
        $rows = AccTransaction::query()->join('acc_accounts as a', 'a.id', '=', 'acc_transactions.account_id')
            ->where('acc_transactions.direction', 'OUT')->where('acc_transactions.category', 'SALARY')->where('acc_transactions.period', $period)
            ->whereNotNull('acc_transactions.person_id')
            ->get(['acc_transactions.person_id', 'acc_transactions.amount', 'acc_transactions.date', 'a.currency']);
        foreach ($rows as $r) {
            $uzs = Accounting::toUzs((string) $r->amount, $r->currency, $r->date->toDateString()) ?? BigDecimal::zero();
            $out[$r->person_id] = ($out[$r->person_id] ?? BigDecimal::zero())->plus($uzs);
        }

        return array_map(fn (BigDecimal $v) => (string) $v->toScale(2), $out);
    }

    /**
     * Ведомость месяца: строки с выплачено/остаток и итоги.
     *
     * @return array{rows:Collection,totals:array<string,string>,paidOnly:Collection}
     */
    public static function sheet(string $period): array
    {
        $rows = AccPayroll::with('person')->where('period', $period)->get()->sortBy(fn ($r) => $r->person?->full_name)->values();
        $paid = self::paid($period);
        $totals = array_fill_keys(['gross', 'ndfl', 'inps', 'net', 'paid', 'remaining'], BigDecimal::zero());
        foreach ($rows as $r) {
            $p = Accounting::dec($paid[$r->person_id] ?? '0');
            $r->setAttribute('paid', (string) $p->toScale(2));
            $r->setAttribute('remaining', (string) Accounting::dec($r->net)->minus($p)->toScale(2));
            foreach (['gross', 'ndfl', 'inps', 'net', 'paid', 'remaining'] as $k) {
                $totals[$k] = $totals[$k]->plus($r->getAttribute($k));
            }
        }
        // Выплаты людям без начисления за этот месяц — тоже показываем
        $withRow = $rows->pluck('person_id')->all();
        $paidOnly = AccPerson::whereIn('id', array_diff(array_keys($paid), $withRow))->get()
            ->each(fn ($p) => $p->setAttribute('paid', $paid[$p->id]));

        return ['rows' => $rows, 'totals' => array_map(fn ($v) => (string) $v->toScale(2), $totals), 'paidOnly' => $paidOnly];
    }
}
