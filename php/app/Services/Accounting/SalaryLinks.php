<?php

namespace App\Services\Accounting;

use App\Models\AccPerson;
use App\Models\AccTransaction;
use App\Support\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Привязка выплат зарплаты из выписок к месяцам и людям. При загрузке выписки месяц зарплаты — месяц платежа, а человек не определён,
 * хотя в назначении платежа обычно «аванс за октябрь», «зарплата за сентябрь», а получатель — ФИО. Здесь месяц берётся из назначения,
 * человек — по ФИО получателя (как в паспорте или в карточке) или ПИНФЛ в назначении. Предпросмотр — список изменений, каждое можно снять.
 */
final class SalaryLinks
{
    /** Основы названий месяцев (русский, узбекский латиница и кириллица) → номер. */
    private const MONTHS = [
        1 => ['январ', 'yanvar', 'yanv'], 2 => ['феврал', 'fevral'], 3 => ['март', 'mart'], 4 => ['апрел', 'aprel'], 5 => ['мая', 'май', 'may'],
        6 => ['июн', 'iyun'], 7 => ['июл', 'iyul'], 8 => ['август', 'avgust'], 9 => ['сентябр', 'сентабр', 'sentabr', 'sentyabr'],
        10 => ['октябр', 'октабр', 'oktabr', 'oktyabr'], 11 => ['ноябр', 'noyabr', 'ноябр'], 12 => ['декабр', 'dekabr'],
    ];

    /** Месяц зарплаты из назначения платежа: «за октябрь [2026]», «10.2026»; год — ближайший к дате платежа. */
    public static function month(string $text, string $date): ?string
    {
        $t = mb_strtolower($text);
        $pay = CarbonImmutable::parse($date);
        $num = null;
        if (preg_match('/(?<!\d)(0?[1-9]|1[0-2])[.\/-](20\d{2})(?!\d)/', $t, $m)) {
            return sprintf('%04d-%02d', (int) $m[2], (int) $m[1]);
        }
        // Предпочитаем упоминание после «за» / «uchun» / «учун»
        $found = [];
        foreach (self::MONTHS as $n => $stems) {
            foreach ($stems as $stem) {
                $pos = mb_strpos($t, $stem);
                if ($pos !== false) {
                    $before = mb_substr($t, max(0, $pos - 12), 12);
                    $found[] = ['n' => $n, 'pos' => $pos, 'after' => (bool) preg_match('/(за|uchun|учун|for)\s*(месяц|oy|ой)?\s*$/u', $before)];
                }
            }
        }
        if (! $found) {
            return null;
        }
        usort($found, fn ($a, $b) => [$b['after'], $a['pos']] <=> [$a['after'], $b['pos']]);
        $num = $found[0]['n'];
        $year = (int) preg_match('/\b(20\d{2})\b/', $t, $y) ? (int) $y[1] : null;
        if ($year) {
            return sprintf('%04d-%02d', $year, $num);
        }
        // Год без указания: тот, при котором месяц ближе всего к месяцу платежа
        $best = null;
        foreach ([-1, 0, 1] as $d) {
            $cand = CarbonImmutable::create($pay->year + $d, $num, 1);
            $diff = abs($cand->diffInMonths($pay->startOfMonth(), false));
            if ($best === null || $diff < $best[0]) {
                $best = [$diff, $cand->format('Y-m')];
            }
        }

        return $best[1];
    }

    private static function norm(?string $s): string
    {
        $s = mb_strtoupper(str_replace(['ʻ', 'ʼ', '‘', '’', '`', '´'], "'", (string) $s));

        return trim((string) preg_replace('/\s+/u', ' ', $s));
    }

    /**
     * Что изменится у выплат зарплаты за год: месяц по назначению, человек по получателю.
     *
     * @return array{rows:list<array>,total:int,withMonth:int}
     */
    public static function plan(int $year): array
    {
        $people = AccPerson::all();
        $byName = [];
        $byPinfl = [];
        foreach ($people as $p) {
            foreach ([$p->passport_name, $p->full_name] as $n) {
                if ($n) {
                    $byName[self::norm($n)] = $p;
                }
            }
            if ($p->pinfl) {
                $byPinfl[$p->pinfl] = $p;
            }
        }
        $ops = AccTransaction::query()->join('acc_accounts as a', 'a.id', '=', 'acc_transactions.account_id')
            ->where('acc_transactions.direction', 'OUT')->where('acc_transactions.category', 'SALARY')->whereNull('acc_transactions.payment_id')
            ->whereBetween('acc_transactions.date', [$year.'-01-01', $year.'-12-31'])
            ->orderBy('acc_transactions.date')->get(['acc_transactions.*', 'a.currency']);
        $rows = [];
        $withMonth = 0;
        foreach ($ops as $op) {
            $text = (string) $op->description;
            $period = self::month($text, $op->date->toDateString());
            $withMonth += $period ? 1 : 0;
            $person = $op->person_id ? $people->firstWhere('id', $op->person_id) : null;
            $newPerson = $person;
            if (! $person) {
                $newPerson = $byName[self::norm($op->counterparty)] ?? null;
                if (! $newPerson && preg_match('/(?<!\d)(\d{14})(?!\d)/', $text, $m)) {
                    $newPerson = $byPinfl[$m[1]] ?? null;
                }
            }
            $newPeriod = $period ?? $op->period ?? $op->date->format('Y-m');
            $changePeriod = $newPeriod !== $op->period;
            $changePerson = ! $person && $newPerson;
            if (! $changePeriod && ! $changePerson) {
                continue;
            }
            $rows[] = ['id' => $op->id, 'date' => $op->date->toDateString(), 'amount' => (string) Accounting::dec($op->amount)->toScale(2), 'currency' => $op->currency,
                'counterparty' => (string) $op->counterparty, 'text' => mb_substr($text, 0, 160), 'oldPeriod' => $op->period, 'newPeriod' => $newPeriod, 'fromText' => $period !== null,
                'newPersonId' => $changePerson ? $newPerson->id : null, 'newPerson' => $changePerson ? $newPerson->full_name : null, 'person' => $person?->full_name,
                'changePeriod' => $changePeriod];
        }

        return ['rows' => $rows, 'total' => $ops->count(), 'withMonth' => $withMonth];
    }

    /**
     * Применить выбранные изменения плана.
     *
     * @param  list<string>  $ids
     * @return array{operations:int}
     */
    public static function apply(int $year, array $ids): array
    {
        $plan = collect(self::plan($year)['rows'])->keyBy('id');
        $n = 0;
        DB::transaction(function () use ($plan, $ids, &$n, $year) {
            foreach ($ids as $id) {
                $r = $plan->get($id);
                if (! $r) {
                    continue;
                }
                $op = AccTransaction::lockForUpdate()->find($id);
                if (! $op) {
                    continue;
                }
                $op->forceFill(['period' => $r['newPeriod'], 'person_id' => $op->person_id ?: $r['newPersonId']])->save();
                $n++;
            }
            if ($n) {
                Audit::log('accounting.salary.link', 'acc_transaction', null, ['link' => ['old' => null, 'new' => ['year' => $year, 'operations' => $n]]]);
            }
        });

        return ['operations' => $n];
    }
}
