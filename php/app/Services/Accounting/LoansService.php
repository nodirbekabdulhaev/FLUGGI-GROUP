<?php

namespace App\Services\Accounting;

use App\Domain\People;
use App\Domain\Tasks;
use App\Exceptions\BusinessRule;
use App\Models\AccLoan;
use App\Models\AccLoanPayment;
use App\Models\AccTransaction;
use App\Models\Todo;
use App\Services\Finance\Input;
use App\Services\Notifications\Notifier;
use App\Support\Audit;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as XlDate;

/**
 * Кредиты: договор + график платежей с отметкой «оплачено» по месяцам, импорт готового графика (xlsx/xls/csv),
 * напоминание за N дней до платежа — делом в «Делах» и уведомлением (планировщик, задача loan-reminders).
 */
final class LoansService
{
    public const CURRENCIES = ['UZS', 'USD'];

    public const EXTENSIONS = ['xlsx', 'xls', 'csv'];

    public const MAX_ROWS = 600;

    /** Время срока дела — конец рабочего дня по Ташкенту. */
    private const DUE_TIME = '18:00';

    public static function list(): array
    {
        $out = [];
        foreach (AccLoan::with(['payments', 'owner:id,full_name'])->orderByDesc('is_active')->orderBy('created_at')->get() as $loan) {
            $out[] = ['loan' => $loan] + self::stats($loan);
        }

        return $out;
    }

    /** Остатки по графику: что выплачено, сколько осталось основного долга и всего, ближайший платёж, просрочка. */
    public static function stats(AccLoan $loan): array
    {
        $today = Tasks::companyDate(now());
        $z = BigDecimal::zero();
        $s = ['paidTotal' => $z, 'paidPrincipal' => $z, 'leftTotal' => $z, 'leftPrincipal' => $z, 'leftInterest' => $z, 'scheduleTotal' => $z,
            'next' => null, 'overdue' => 0, 'paidCount' => 0, 'count' => $loan->payments->count()];
        foreach ($loan->payments as $p) {
            $s['scheduleTotal'] = $s['scheduleTotal']->plus($p->total);
            if ($p->paid_at) {
                $s['paidCount']++;
                $s['paidTotal'] = $s['paidTotal']->plus($p->paid_amount ?? $p->total);
                $s['paidPrincipal'] = $s['paidPrincipal']->plus($p->principal);

                continue;
            }
            $s['leftTotal'] = $s['leftTotal']->plus($p->total);
            $s['leftPrincipal'] = $s['leftPrincipal']->plus($p->principal);
            $s['leftInterest'] = $s['leftInterest']->plus($p->interest);
            if ($p->due_date->toDateString() < $today) {
                $s['overdue']++;
            }
            $s['next'] ??= $p;
        }
        // Если в графике основной долг не расписан — остаток считаем от суммы кредита
        $s['balance'] = $s['paidPrincipal']->isZero() && $s['leftPrincipal']->isZero() ? Accounting::dec($loan->principal) : Accounting::dec($loan->principal)->minus($s['paidPrincipal']);

        return $s;
    }

    /** Итоги по всем действующим кредитам в разрезе валют: остаток платежей и просрочка. */
    public static function totals(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            if (! $r['loan']->is_active) {
                continue;
            }
            $c = $r['loan']->currency;
            $out[$c] ??= ['left' => BigDecimal::zero(), 'balance' => BigDecimal::zero(), 'overdue' => 0];
            $out[$c]['left'] = $out[$c]['left']->plus($r['leftTotal']);
            $out[$c]['balance'] = $out[$c]['balance']->plus($r['balance']);
            $out[$c]['overdue'] += $r['overdue'];
        }

        return $out;
    }

    private static function normalize(array $d): array
    {
        $d['name'] = trim((string) $d['name']);
        $d['currency'] = in_array($d['currency'] ?? 'UZS', self::CURRENCIES, true) ? $d['currency'] : 'UZS';
        $d['lender'] = trim((string) ($d['lender'] ?? '')) ?: null;
        $d['note'] = trim((string) ($d['note'] ?? '')) ?: null;
        $d['rate'] = ($d['rate'] ?? '') === '' ? null : $d['rate'];
        $d['start_date'] = ($d['start_date'] ?? null) ?: null;
        $d['end_date'] = ($d['end_date'] ?? null) ?: null;
        $d['remind_days'] = max(0, min(60, (int) ($d['remind_days'] ?? 7)));
        $d['owner_id'] = ($d['owner_id'] ?? null) ?: access()->id();
        $d['is_active'] = (bool) ($d['is_active'] ?? true);

        return $d;
    }

    private const FIELDS = ['name', 'lender', 'currency', 'principal', 'rate', 'start_date', 'end_date', 'note', 'owner_id', 'remind_days', 'is_active'];

    /** @param  list<array{due_date:string,principal:string,interest:string,total?:?string}>  $rows */
    public static function create(array $d, array $rows = []): AccLoan
    {
        $d = self::normalize($d);

        return DB::transaction(function () use ($d, $rows) {
            $loan = AccLoan::create(array_intersect_key($d, array_flip(self::FIELDS)) + ['created_by_id' => access()->id()]);
            self::insertRows($loan, self::cleanRows($rows));
            Audit::log('accounting.loan.create', 'acc_loan', $loan->id, ['name' => ['old' => null, 'new' => $loan->name], 'principal' => ['old' => null, 'new' => (string) $loan->principal]]);

            return $loan;
        });
    }

    public static function update(string $id, array $d): AccLoan
    {
        $d = self::normalize($d);

        return DB::transaction(function () use ($id, $d) {
            $loan = AccLoan::lockForUpdate()->findOrFail($id);
            $loan->update(array_intersect_key($d, array_flip(self::FIELDS)));
            Audit::log('accounting.loan.update', 'acc_loan', $loan->id, ['name' => ['old' => null, 'new' => $loan->name]]);

            return $loan;
        });
    }

    public static function delete(string $id): void
    {
        DB::transaction(function () use ($id) {
            $loan = AccLoan::lockForUpdate()->findOrFail($id);
            $loan->delete();
            Audit::log('accounting.loan.delete', 'acc_loan', $loan->id, ['name' => ['old' => $loan->name, 'new' => null]]);
        });
    }

    /** Приводит строки графика к виду {due_date, principal, interest, total}; пустые строки пропускаются. */
    public static function cleanRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $date = trim((string) ($r['due_date'] ?? ''));
            $p = Accounting::dec(self::num($r['principal'] ?? null));
            $i = Accounting::dec(self::num($r['interest'] ?? null));
            $tot = self::num($r['total'] ?? null);
            if ($date === '' && $p->isZero() && $i->isZero() && $tot === null) {
                continue;
            }
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || ! checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))) {
                throw new BusinessRule(t('accounting.loans.errors.date'), ['rows' => t('accounting.loans.errors.date')]);
            }
            $total = $tot !== null ? Accounting::dec($tot) : $p->plus($i);
            if ($total->isZero() || $total->isNegative()) {
                throw new BusinessRule(t('accounting.loans.errors.amount'), ['rows' => t('accounting.loans.errors.amount')]);
            }
            $out[] = ['due_date' => $date, 'principal' => (string) $p->toScale(2), 'interest' => (string) $i->toScale(2), 'total' => (string) $total->toScale(2)];
        }
        if (count($out) > self::MAX_ROWS) {
            throw new BusinessRule(t('accounting.loans.errors.tooMany', ['max' => self::MAX_ROWS]), ['rows' => t('accounting.loans.errors.tooMany', ['max' => self::MAX_ROWS])]);
        }

        return $out;
    }

    /** Число из ячейки/поля: «1 500 000,5» → «1500000.5»; пусто и не число → null. */
    private static function num(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        $s = is_float($v) || is_int($v) ? (string) round((float) $v, 2) : (string) Input::money($v);
        $s = str_replace(',', '.', $s);

        return preg_match('/^-?\d+(\.\d+)?$/', $s) ? $s : null;
    }

    private static function insertRows(AccLoan $loan, array $rows): int
    {
        foreach ($rows as $r) {
            AccLoanPayment::create($r + ['loan_id' => $loan->id]);
        }

        return count($rows);
    }

    /**
     * Ввести/загрузить график. replace = true: неоплаченные строки заменяются новыми (оплаченные остаются как есть);
     * иначе новые строки добавляются к существующим.
     */
    public static function addSchedule(string $loanId, array $rows, bool $replace): int
    {
        $rows = self::cleanRows($rows);
        if (! $rows) {
            throw new BusinessRule(t('accounting.loans.errors.noRows'), ['rows' => t('accounting.loans.errors.noRows')]);
        }

        return DB::transaction(function () use ($loanId, $rows, $replace) {
            $loan = AccLoan::lockForUpdate()->findOrFail($loanId);
            if ($replace) {
                $loan->payments()->whereNull('paid_at')->delete();
            }
            $n = self::insertRows($loan, $rows);
            Audit::log('accounting.loan.schedule', 'acc_loan', $loan->id, ['rows' => ['old' => null, 'new' => $n.($replace ? ' (replace)' : '')]]);

            return $n;
        });
    }

    public static function updatePayment(string $id, array $d): AccLoanPayment
    {
        $row = self::cleanRows([$d])[0] ?? throw new BusinessRule(t('accounting.loans.errors.noRows'), ['due_date' => t('accounting.loans.errors.noRows')]);

        return DB::transaction(function () use ($id, $row) {
            $p = AccLoanPayment::lockForUpdate()->findOrFail($id);
            $p->update($row);
            Audit::log('accounting.loan.payment.update', 'acc_loan', $p->loan_id, ['due_date' => ['old' => null, 'new' => $row['due_date']], 'total' => ['old' => null, 'new' => $row['total']]]);

            return $p;
        });
    }

    public static function deletePayment(string $id): void
    {
        DB::transaction(function () use ($id) {
            $p = AccLoanPayment::lockForUpdate()->findOrFail($id);
            $p->delete();
            Audit::log('accounting.loan.payment.delete', 'acc_loan', $p->loan_id, ['due_date' => ['old' => $p->due_date->toDateString(), 'new' => null]]);
        });
    }

    /** Отметить платёж оплаченным (дата и фактическая сумма) или снять отметку; связанное дело закрывается/не трогается. */
    public static function setPaid(string $id, bool $paid, ?string $date = null, ?string $amount = null): AccLoanPayment
    {
        return DB::transaction(function () use ($id, $paid, $date, $amount) {
            $p = AccLoanPayment::lockForUpdate()->findOrFail($id);
            if ($paid) {
                $date = $date ?: Tasks::companyDate(now());
                $p->update(['paid_at' => $date, 'paid_amount' => (string) Accounting::dec($amount ?: $p->total)->toScale(2)]);
                if ($p->todo_id) {
                    Todo::where('id', $p->todo_id)->where('status', 'OPEN')->update(['status' => 'DONE', 'completed_at' => now()]);
                }
            } else {
                $p->update(['paid_at' => null, 'paid_amount' => null]);
            }
            Audit::log($paid ? 'accounting.loan.paid' : 'accounting.loan.unpaid', 'acc_loan', $p->loan_id, ['due_date' => ['old' => null, 'new' => $p->due_date->toDateString()]]);

            return $p;
        });
    }

    // ── Импорт готового графика ─────────────────────────────────────────────

    /**
     * Читает xlsx/xls/csv: заголовки ищутся по словам (дата, основной долг, проценты, итого/платёж).
     * Без заголовков: A — дата, B — основной долг, C — проценты, D — итого; при двух колонках — дата и сумма платежа.
     *
     * @return list<array{due_date:string,principal:string,interest:string,total:?string}>
     */
    public static function parseFile(string $path, string $name): array
    {
        try {
            $reader = IOFactory::createReaderForFile($path);
            if (method_exists($reader, 'setReadDataOnly')) {
                $reader->setReadDataOnly(true);
            }
            $grid = $reader->load($path)->getSheet(0)->toArray(null, false, false, false);
        } catch (\Throwable) {
            throw new BusinessRule(t('accounting.loans.errors.file', ['name' => $name]), ['file' => t('accounting.loans.errors.file', ['name' => $name])]);
        }
        $cols = ['date' => 0, 'principal' => 1, 'interest' => 2, 'total' => 3];
        $start = 0;
        $headers = ['date' => ['дата', 'срок', 'date', 'sana', 'muddat'], 'principal' => ['основн', 'principal', 'asosiy', 'тело', 'погашение долга'],
            'interest' => ['процент', 'interest', 'foiz', '%'], 'total' => ['итого', 'всего', 'платеж', 'платёж', 'total', 'jami', 'tolov', 'to‘lov', 'сумма платежа']];
        foreach (array_slice($grid, 0, 15, true) as $i => $row) {
            $found = [];
            foreach ($row as $c => $cell) {
                $text = mb_strtolower(trim((string) $cell));
                if ($text === '' || is_numeric($text)) {
                    continue;
                }
                // Одна колонка — одна роль («Дата платежа» — это дата, а не сумма платежа)
                foreach ($headers as $key => $words) {
                    if (! isset($found[$key]) && collect($words)->contains(fn ($w) => str_contains($text, $w))) {
                        $found[$key] = $c;

                        break;
                    }
                }
            }
            if (isset($found['date']) && count($found) >= 2) {
                $cols = $found + ['principal' => null, 'interest' => null, 'total' => null];
                $start = $i + 1;
                break;
            }
        }
        $two = $start === 0 && isset($grid[0]) && count(array_filter($grid[0], fn ($v) => $v !== null && $v !== '')) === 2;
        if ($two) {
            $cols = ['date' => 0, 'principal' => null, 'interest' => null, 'total' => 1];
        }
        $rows = [];
        foreach (array_slice($grid, $start, null, true) as $row) {
            $date = self::cellDate($row[$cols['date']] ?? null);
            if ($date === null) {
                continue;
            }
            $get = fn ($k) => $cols[$k] === null ? null : self::num($row[$cols[$k]] ?? null);
            $p = $get('principal');
            $i = $get('interest');
            $t = $get('total');
            if ($p === null && $i === null && $t === null) {
                continue;
            }
            $rows[] = ['due_date' => $date, 'principal' => $p ?? '0', 'interest' => $i ?? '0', 'total' => $t];
        }
        if (! $rows) {
            throw new BusinessRule(t('accounting.loans.errors.noRows'), ['file' => t('accounting.loans.errors.noRows')]);
        }

        return $rows;
    }

    private static function cellDate(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_numeric($v) && (float) $v > 20000 && (float) $v < 80000) {
            return CarbonImmutable::instance(XlDate::excelToDateTimeObject((float) $v))->format('Y-m-d');
        }
        $s = trim((string) $v);
        if (preg_match('/^(\d{1,2})[.\/-](\d{1,2})[.\/-](\d{4})/', $s, $m) && checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
            return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $s, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return "$m[1]-$m[2]-$m[3]";
        }

        return null;
    }

    // ── В отчётность и сверка с операциями ───────────────────────────────────

    private static function uzs(string $amount, string $currency, string $date): BigDecimal
    {
        return Accounting::toUzs($amount, $currency, $date) ?? BigDecimal::zero();
    }

    /**
     * Долг по кредитам на дату (UZS) для строк баланса: «краткосрочные» — основной долг к уплате в ближайшие 12 месяцев, «долгосрочные» — остальное.
     * Остаток = сумма кредита − основной долг по платежам, оплаченным к дате; кредиты, выданные позже даты, не учитываются.
     *
     * @return array{short:BigDecimal,long:BigDecimal}
     */
    public static function balancesAt(string $end): array
    {
        $short = $long = BigDecimal::zero();
        $limit = CarbonImmutable::parse($end)->addYear()->toDateString();
        foreach (AccLoan::with('payments')->get() as $loan) {
            if ($loan->start_date && $loan->start_date->toDateString() > $end) {
                continue;
            }
            $paid = $due = BigDecimal::zero();
            foreach ($loan->payments as $p) {
                if ($p->paid_at && $p->paid_at->toDateString() <= $end) {
                    $paid = $paid->plus($p->principal);
                } elseif ($p->due_date->toDateString() <= $limit) {
                    $due = $due->plus($p->principal);
                }
            }
            $left = BigDecimal::max(Accounting::dec($loan->principal)->minus($paid), BigDecimal::zero());
            $cur = BigDecimal::min($left, $due);
            $short = $short->plus(self::uzs((string) $cur, $loan->currency, $end));
            $long = $long->plus(self::uzs((string) $left->minus($cur), $loan->currency, $end));
        }

        return ['short' => $short, 'long' => $long];
    }

    /** Расходы в виде процентов за год (UZS): проценты платежей графика, срок которых уже наступил (не позже сегодня и конца года). */
    public static function interestFor(int $year): BigDecimal
    {
        $to = min($year.'-12-31', Tasks::companyDate(now()));
        $sum = BigDecimal::zero();
        foreach (AccLoan::with('payments')->get() as $loan) {
            foreach ($loan->payments as $p) {
                $d = $p->due_date->toDateString();
                if ($d >= $year.'-01-01' && $d <= $to && (! $loan->start_date || $d >= $loan->start_date->toDateString())) {
                    $sum = $sum->plus(self::uzs((string) $p->interest, $loan->currency, $d));
                }
            }
        }

        return $sum;
    }

    /** Категории, которые можно перенести в «Кредит» автоматически (не налоги, не зарплата, не оплаты клиентов). */
    private const MATCH_OUT = ['OTHER', 'BANK_FEE', 'SUPPLIER', 'LOAN_PAYMENT'];

    private const MATCH_IN = ['OTHER_INCOME', 'CLIENT_PAYMENT', 'LOAN_RECEIPT'];

    /**
     * Находит в банковских операциях оплату платежей графика и поступление кредита: расход на сумму платежа (±1) с датой около срока
     * отмечает платёж оплаченным и получает статью «Погашение кредита»; приход на сумму кредита около даты выдачи — «Получение кредита».
     *
     * @return array{paid:int,received:int}
     */
    public static function autoMatch(?string $loanId = null): array
    {
        $paid = $received = 0;
        $loans = AccLoan::with(['payments' => fn ($q) => $q->orderBy('due_date')])->where('is_active', true)
            ->when($loanId, fn ($q) => $q->whereKey($loanId))->get();
        if ($loans->isEmpty()) {
            return ['paid' => 0, 'received' => 0];
        }
        $taken = AccLoanPayment::whereNotNull('transaction_id')->pluck('transaction_id')->flip();
        $base = fn (string $dir, array $cats, string $currency) => AccTransaction::query()->join('acc_accounts as a', 'a.id', '=', 'acc_transactions.account_id')
            ->where('a.kind', 'BANK')->where('a.currency', $currency)->where('acc_transactions.direction', $dir)->whereIn('acc_transactions.category', $cats)
            ->whereNull('acc_transactions.payment_id')->whereNull('acc_transactions.transfer_group')->whereNull('acc_transactions.category_id');
        foreach ($loans as $loan) {
            foreach ($loan->payments as $p) {
                if ($p->paid_at || $p->transaction_id) {
                    continue;
                }
                $due = $p->due_date;
                $op = $base('OUT', self::MATCH_OUT, $loan->currency)
                    ->whereBetween('acc_transactions.date', [$due->subDays(7)->toDateString(), $due->addDays(14)->toDateString()])
                    ->whereBetween('acc_transactions.amount', [(string) Accounting::dec($p->total)->minus(1), (string) Accounting::dec($p->total)->plus(1)])
                    ->get(['acc_transactions.*'])->reject(fn ($o) => $taken->has($o->id))
                    ->sortBy(fn ($o) => abs($o->date->diffInDays($due)))->first();
                if (! $op) {
                    continue;
                }
                $taken->put($op->id, 0);
                DB::transaction(function () use ($p, $op) {
                    $p->update(['paid_at' => $op->date->toDateString(), 'paid_amount' => (string) $op->amount, 'transaction_id' => $op->id]);
                    if ($op->category !== 'LOAN_PAYMENT') {
                        AccTransaction::whereKey($op->id)->update(['category' => 'LOAN_PAYMENT', 'period' => null]);
                    }
                    if ($p->todo_id) {
                        Todo::where('id', $p->todo_id)->where('status', 'OPEN')->update(['status' => 'DONE', 'completed_at' => now()]);
                    }
                    Audit::log('accounting.loan.matched', 'acc_loan', $p->loan_id, ['due_date' => ['old' => null, 'new' => $p->due_date->toDateString()], 'transaction' => ['old' => null, 'new' => $op->id]]);
                });
                $paid++;
            }
            if ($loan->start_date) {
                $in = $base('IN', self::MATCH_IN, $loan->currency)
                    ->whereBetween('acc_transactions.date', [$loan->start_date->subDays(10)->toDateString(), $loan->start_date->addDays(10)->toDateString()])
                    ->whereBetween('acc_transactions.amount', [(string) Accounting::dec($loan->principal)->minus(1), (string) Accounting::dec($loan->principal)->plus(1)])
                    ->where('acc_transactions.category', '<>', 'LOAN_RECEIPT')->get(['acc_transactions.id']);
                foreach ($in as $row) {
                    AccTransaction::whereKey($row->id)->update(['category' => 'LOAN_RECEIPT']);
                    Audit::log('accounting.loan.received', 'acc_loan', $loan->id, ['transaction' => ['old' => null, 'new' => $row->id]]);
                    $received++;
                }
            }
        }

        return ['paid' => $paid, 'received' => $received];
    }

    // ── Напоминания ─────────────────────────────────────────────────────────

    /** Задача планировщика: за remind_days дней до срока неоплаченного платежа создаёт дело ответственному (один раз на платёж). */
    public static function remind(?\DateTimeInterface $now = null): array
    {
        self::autoMatch();
        $today = Tasks::companyDate($now ?? now());
        $created = 0;
        $rows = AccLoanPayment::query()->join('acc_loans as l', 'l.id', '=', 'acc_loan_payments.loan_id')
            ->whereNull('l.deleted_at')->where('l.is_active', true)->whereNotNull('l.owner_id')
            ->whereNull('acc_loan_payments.paid_at')->whereNull('acc_loan_payments.todo_id')
            ->whereRaw('acc_loan_payments.due_date <= DATE_ADD(?, INTERVAL l.remind_days DAY)', [$today])
            ->orderBy('acc_loan_payments.due_date')
            ->get(['acc_loan_payments.*', 'l.name as loan_name', 'l.owner_id as loan_owner', 'l.currency as loan_currency', 'l.created_by_id as loan_creator']);
        foreach ($rows as $p) {
            $due = $p->due_date->toDateString();
            $label = implode('.', array_reverse(explode('-', $due)));
            $title = t('accounting.loans.todoTitle', ['name' => $p->loan_name, 'date' => $label, 'sum' => money((string) $p->total, $p->loan_currency)]);
            $id = (string) Str::uuid();
            $stamp = now()->format('Y-m-d H:i:s.v');
            DB::table('todos')->insert([
                'id' => $id, 'title' => $title, 'description' => t('accounting.loans.todoBody'), 'kind' => 'PAYMENT', 'priority' => $due < $today ? 'URGENT' : 'HIGH',
                'due_at' => People::atTashkent($due, self::DUE_TIME)->utc()->format('Y-m-d H:i:s.v'),
                'owner_id' => $p->loan_owner, 'creator_id' => $p->loan_creator ?: $p->loan_owner, 'created_at' => $stamp, 'updated_at' => $stamp,
            ]);
            AccLoanPayment::whereKey($p->id)->update(['todo_id' => $id]);
            $created++;
            Notifier::notify([$p->loan_owner], 'todo.recurring', fn () => ['💳 '.$title, t('accounting.loans.notifyBody', ['date' => $label])], '/accounting/loans/'.$p->loan_id);
        }

        return ['created' => $created];
    }
}
