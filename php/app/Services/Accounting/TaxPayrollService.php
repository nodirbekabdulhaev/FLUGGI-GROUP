<?php

namespace App\Services\Accounting;

use App\Exceptions\BusinessRule;
use App\Models\AccPayroll;
use App\Models\AccPerson;
use App\Models\AccPersonSalary;
use App\Models\AccTransaction;
use App\Support\Audit;
use App\Support\Format;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Люди, оформленные для налоговой, и их зарплата за месяц: начислено (gross), НДФЛ, ИНПС, на руки.
 * НДФЛ = 12 % × (gross − необлагаемая часть по льготам); с льготой IT Park (37,5 % не облагается) это 7,5 % от gross —
 * те же суммы, что в отчёте в налоговую (TaxReport). ИНПС 0,1 % удерживается отдельно: на руки = gross − НДФЛ − ИНПС
 * (настройка inpsWithinNdfl: ИНПС в составе НДФЛ — на руки = gross − НДФЛ).
 * Оклад — по истории окладов (acc_person_salaries): изменение с месяца действует с этого месяца и далее.
 */
final class TaxPayrollService
{
    private const PERSON_FIELDS = ['full_name', 'passport_name', 'pinfl', 'position', 'resident_type', 'contract_type', 'work_rate', 'workplace_type', 'benefits',
        'monthly_salary', 'user_id', 'hired_at', 'fired_at', 'is_active', 'comment'];

    // ─────────────────────────── Люди ───────────────────────────

    public static function people(): Collection
    {
        return AccPerson::with('user:id,full_name')->orderByDesc('is_active')->orderBy('full_name')->get();
    }

    /**
     * Создать или изменить человека. Оклад пишется в историю с месяца salary_from (по умолчанию — текущий месяц,
     * для нового человека — месяц приёма): прошлые месяцы считаются по прежнему окладу.
     */
    public static function savePerson(?string $id, array $data): AccPerson
    {
        $data['monthly_salary'] = (string) Accounting::dec($data['monthly_salary'] ?? '0')->toScale(2);
        foreach (['passport_name', 'pinfl', 'position', 'user_id', 'hired_at', 'fired_at', 'comment'] as $k) {
            $data[$k] = isset($data[$k]) && trim((string) $data[$k]) !== '' ? trim((string) $data[$k]) : null;
        }
        if ($data['passport_name'] !== null) {
            $data['passport_name'] = mb_strtoupper(preg_replace('/\s+/u', ' ', $data['passport_name']));
        }
        foreach (['resident_type', 'contract_type', 'workplace_type'] as $k) {
            $data[$k] = (int) ($data[$k] ?? 1) ?: 1;
        }
        $data['work_rate'] = (string) Accounting::dec($data['work_rate'] ?? '1')->toScale(2);
        $data['benefits'] = array_key_exists('benefits', $data) && $data['benefits'] !== null ? array_values($data['benefits']) : null;
        $salaryFrom = $data['salary_from'] ?? null;
        unset($data['salary_from']);
        if ($data['hired_at'] && $data['fired_at'] && $data['fired_at'] < $data['hired_at']) {
            throw new BusinessRule(t('accounting.errors.firedBeforeHired'), ['fired_at' => t('accounting.errors.firedBeforeHired')]);
        }

        return DB::transaction(function () use ($id, $data, $salaryFrom) {
            if ($data['pinfl'] && AccPerson::where('pinfl', $data['pinfl'])->when($id, fn ($q) => $q->whereKeyNot($id))->exists()) {
                throw new BusinessRule(t('accounting.errors.pinflTaken'), ['pinfl' => t('accounting.errors.pinflTaken')]);
            }
            $before = $id ? AccPerson::lockForUpdate()->findOrFail($id) : null;
            $old = $before ? $before->only(self::PERSON_FIELDS) + ['hired_at' => $before->hired_at?->toDateString(), 'fired_at' => $before->fired_at?->toDateString()] : [];
            if ($before) {
                $from = $salaryFrom ?: Format::today()->format('Y-m');
                $current = TaxReport::salaryFor($before, $from);
                $data['monthly_salary'] = self::recordSalary($before, $from, $data['monthly_salary'], $current);
                $p = tap($before)->update($data);
            } else {
                $p = AccPerson::create($data);
                $from = $salaryFrom ?: ($data['hired_at'] ? substr($data['hired_at'], 0, 7) : Format::today()->format('Y-m'));
                AccPersonSalary::create(['person_id' => $p->id, 'effective_from' => $from, 'amount' => $data['monthly_salary'], 'created_by_id' => access(false)?->id()]);
            }
            $changes = Audit::diff($old, $data, self::PERSON_FIELDS);
            if ($before && isset($from) && ! empty($changes['monthly_salary'])) {
                $changes['salary_from'] = ['old' => null, 'new' => $from];
            }
            Audit::log($before ? 'accounting.person.update' : 'accounting.person.create', 'acc_person', $p->id, $changes);

            return $p;
        });
    }

    /**
     * Записать оклад с месяца: если он отличается от действующего в этом месяце. Без истории сначала сохраняется прежний
     * оклад (с месяца приёма), чтобы прошлые месяцы не изменились. Возвращает оклад карточки — последний по истории.
     */
    private static function recordSalary(AccPerson $p, string $from, string $amount, BigDecimal $current): string
    {
        if (! $current->isEqualTo($amount)) {
            if (! $p->salaries()->exists()) {
                $base = $p->hired_at && $p->hired_at->format('Y-m') < $from ? $p->hired_at->format('Y-m') : '2000-01';
                AccPersonSalary::create(['person_id' => $p->id, 'effective_from' => $base, 'amount' => (string) Accounting::dec($p->monthly_salary)->toScale(2), 'created_by_id' => access(false)?->id()]);
            }
            AccPersonSalary::updateOrCreate(['person_id' => $p->id, 'effective_from' => $from], ['amount' => $amount, 'created_by_id' => access(false)?->id()]);
        }
        $last = $p->salaries()->reorder()->orderByDesc('effective_from')->value('amount');
        $p->unsetRelation('salaries');

        return $last === null ? $amount : (string) Accounting::dec($last)->toScale(2);
    }

    /** Удалить запись истории окладов (ошибочное изменение). Последнюю запись удалить нельзя. */
    public static function deleteSalary(string $personId, string $salaryId): void
    {
        DB::transaction(function () use ($personId, $salaryId) {
            $p = AccPerson::lockForUpdate()->findOrFail($personId);
            $row = AccPersonSalary::where('person_id', $personId)->findOrFail($salaryId);
            if ($p->salaries()->count() < 2) {
                throw new BusinessRule(t('accounting.errors.lastSalary'));
            }
            $row->delete();
            $last = (string) Accounting::dec($p->salaries()->reorder()->orderByDesc('effective_from')->value('amount'))->toScale(2);
            $p->update(['monthly_salary' => $last]);
            Audit::log('accounting.person.salary_delete', 'acc_person', $personId, [
                'salary' => ['old' => ['from' => $row->effective_from, 'amount' => (string) $row->amount], 'new' => null],
                'monthly_salary' => ['old' => null, 'new' => $last],
            ]);
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
     * НДФЛ (по льготам человека; null — льгота компании), ИНПС и «на руки» от начисленного.
     *
     * @return array{gross:string,ndfl_rate:string,ndfl:string,inps_rate:string,inps:string,net:string}
     */
    public static function amounts(BigDecimal $gross, array $s, ?array $benefits = null): array
    {
        $gross = Accounting::round($gross);
        $ndfl = TaxReport::split($gross, $benefits ?? TaxReport::benefits(null, $s), $s)['ndfl'];
        $ndflRate = $gross->isPositive() ? $ndfl->multipliedBy(100)->dividedBy($gross, 3, RoundingMode::HalfUp) : BigDecimal::of($s['ndflRate'])->toScale(3);
        $inps = Accounting::pct($gross, $s['inpsRate']);
        $net = $gross->minus($ndfl);
        if (! $s['inpsWithinNdfl']) {
            $net = $net->minus($inps);
        }

        return ['gross' => (string) $gross, 'ndfl_rate' => (string) $ndflRate, 'ndfl' => (string) $ndfl,
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
            $people = AccPerson::with('salaries')->where('is_active', true)
                ->where(fn ($q) => $q->whereNull('hired_at')->orWhere('hired_at', '<', $b))
                ->where(fn ($q) => $q->whereNull('fired_at')->orWhere('fired_at', '>=', $a))->get();
            foreach ($people as $p) {
                $gross = TaxReport::accrued($p, $period);
                if ($gross === null) {
                    continue;
                }
                $eligible[] = $p->id;
                $row = $existing[$p->id] ?? null;
                if ($row?->status === 'APPROVED') {
                    $res['approved']++;

                    continue;
                }
                $data = self::amounts($gross, $s, TaxReport::benefits($p->benefits, $s));
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
            $row = AccPayroll::with('person')->lockForUpdate()->findOrFail($id);
            if ($row->status !== 'DRAFT') {
                throw new BusinessRule(t('accounting.errors.payrollApproved'));
            }
            $s = Accounting::settings();
            $data = self::amounts(Accounting::dec($gross), $s, TaxReport::benefits($row->person?->benefits, $s));
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
