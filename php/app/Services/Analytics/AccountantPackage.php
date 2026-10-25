<?php

namespace App\Services\Analytics;

use App\Domain\Tasks;
use App\Models\Commission;
use App\Models\Contract;
use App\Models\Expense;
use App\Models\OtherIncome;
use App\Models\Payment;
use App\Models\PayrollEntry;
use App\Models\Project;
use App\Support\Audit;
use App\Support\Format;
use App\Support\Settings;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Годовой пакет для бухгалтера: всё, что CRM знает о деньгах компании за год, одним Excel-файлом.
 * Баланс (форма №1) и отчёт о финансовых результатах (форма №2) составляет бухгалтер.
 * Документ предназначен бухгалтерии и формируется на русском языке (как в прежней версии).
 */
final class AccountantPackage
{
    private const MONTHS = ['Январь', 'Февраль', 'Март', 'Апрель', 'Май', 'Июнь', 'Июль', 'Август', 'Сентябрь', 'Октябрь', 'Ноябрь', 'Декабрь'];

    private const TAX_REGIMES = ['IT_PARK' => 'Резидент IT-Park', 'TURNOVER' => 'Налог с оборота', 'GENERAL' => 'Общий режим (НДС и налог на прибыль)', 'OTHER' => 'Другой'];

    private const PAYMENT_TYPE = ['PREPAYMENT' => 'Предоплата', 'PARTIAL' => 'Частичная', 'FULL' => 'Полная', 'FINAL' => 'Финальная', 'REFUND' => 'Возврат'];

    private const PAYMENT_METHOD = ['CASH' => 'Наличные', 'BANK' => 'Банк', 'CARD' => 'Карта', 'TRANSFER' => 'Перевод', 'OTHER' => 'Другое'];

    private const PROJECT_STATUS = ['NEW' => 'Новый', 'PLANNING' => 'Планирование', 'IN_PROGRESS' => 'В работе', 'REVIEW' => 'На проверке',
        'WAITING_CLIENT' => 'Ждём клиента', 'PAUSED' => 'Пауза', 'COMPLETED' => 'Завершён', 'CANCELLED' => 'Отменён'];

    /** Куда бухгалтеру отнести расход (подсказка по плану счетов НСБУ №21). */
    public static function expenseAccount(string $scope, ?string $hint): string
    {
        if ($scope === 'PROJECT') {
            return 'Себестоимость услуг (9130)';
        }

        return match ($hint) {
            '9410' => 'Расходы по реализации (9410)',
            '9430' => 'Прочие операционные расходы (9430)',
            default => 'Административные расходы (9420)',
        };
    }

    private static function d(BigDecimal $v): float
    {
        return (float) (string) $v->toScale(2);
    }

    private static function contractNo(?int $n): string
    {
        return Format::code('ДГ', $n);
    }

    /** @return array{body:string,filename:string,counts:array} */
    public static function build(int $year, ?\DateTimeInterface $now = null): array
    {
        $now ??= now();
        $from = Period::ts(Tasks::companyDayStart($year.'-01-01'));
        $to = Period::ts(Tasks::companyDayStart(($year + 1).'-01-01'));
        $dFrom = $year.'-01-01';
        $dTo = ($year + 1).'-01-01';
        $periods = array_map(fn ($i) => $year.'-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT), range(1, 12));
        $company = Settings::company();
        // Категории расходов/доходов — по коду (expenses.category / other_incomes.category)
        $cats = DB::table('finance_categories')->get(['code', 'name', 'account_hint'])->keyBy('code');
        $cat = fn ($code) => $cats->get($code);
        $tr = fn ($q) => $q->withTrashed();

        $contracts = Contract::with(['client' => $tr, 'deal' => $tr])->where('status', 'SIGNED')->where('signed_at', '>=', $from)->where('signed_at', '<', $to)->orderBy('signed_at')->get();
        $payments = Payment::with(['client' => $tr, 'deal' => $tr, 'contract'])->where('status', 'PAID')->where('paid_at', '>=', $from)->where('paid_at', '<', $to)->orderBy('paid_at')->get();
        $allPaid = DB::table('payments')->where('status', 'PAID')->where('paid_at', '<', $to)->get(['client_id', 'type', 'amount_uzs']);
        $signedToDate = DB::table('contracts as c')->join('clients as cl', 'cl.id', '=', 'c.client_id')->where('c.status', 'SIGNED')->where('c.signed_at', '<', $to)
            ->get(['c.client_id', 'c.amount_uzs', 'cl.name', 'cl.number']);
        $expenses = Expense::with(['project' => $tr, 'payee' => $tr, 'createdBy' => $tr])->where('expense_date', '>=', $dFrom)->where('expense_date', '<', $dTo)
            ->orderBy('expense_date')->orderBy('created_at')->get();
        $payroll = PayrollEntry::with(['user' => $tr])->whereIn('period', $periods)->get()
            ->sortBy(fn ($p) => $p->period.'|'.mb_strtolower((string) $p->user?->full_name))->values();
        $commissions = Commission::with(['user' => $tr, 'deal' => $tr])->whereIn('period', $periods)->where('status', '<>', 'CANCELLED')->orderBy('period')->get();
        $projects = Project::with(['client' => $tr])->where('created_at', '<', $to)->where('status', '<>', 'CANCELLED')
            ->where(fn ($q) => $q->whereNull('completed_at')->orWhere('completed_at', '>=', $from))->orderBy('number')->get();
        $incomes = OtherIncome::with(['project' => $tr, 'client' => $tr])->where('income_date', '>=', $dFrom)->where('income_date', '<', $dTo)->orderBy('income_date')->get();

        $sign = fn ($p) => $p->type === 'REFUND' ? BigDecimal::of((string) $p->amount_uzs)->negated() : BigDecimal::of((string) $p->amount_uzs);
        $zero = BigDecimal::zero();
        $month = fn ($dt) => (int) substr(Tasks::companyDate(CarbonImmutable::parse($dt, 'UTC')), 5, 2) - 1;
        $dateMonth = fn ($date) => (int) substr(is_string($date) ? $date : $date->format('Y-m-d'), 5, 2) - 1;

        // ── Сводка по месяцам
        $m = array_fill(0, 12, ['contracts' => $zero, 'paid' => $zero, 'cost' => $zero, 'sales' => $zero, 'admin' => $zero, 'payroll' => $zero, 'income' => $zero]);
        foreach ($incomes as $r) {
            $i = $dateMonth($r->income_date);
            $m[$i]['income'] = $m[$i]['income']->plus((string) $r->amount_uzs);
        }
        foreach ($contracts as $c) {
            $i = $month($c->signed_at);
            $m[$i]['contracts'] = $m[$i]['contracts']->plus((string) $c->amount_uzs);
        }
        foreach ($payments as $p) {
            $i = $month($p->paid_at);
            $m[$i]['paid'] = $m[$i]['paid']->plus($sign($p));
        }
        foreach ($expenses as $e) {
            $i = $dateMonth($e->expense_date);
            $acc = self::expenseAccount($e->scope, $cat($e->category)?->account_hint);
            $k = str_starts_with($acc, 'Себестоимость') ? 'cost' : (str_starts_with($acc, 'Расходы по реализации') ? 'sales' : 'admin');
            $m[$i][$k] = $m[$i][$k]->plus((string) $e->amount_uzs);
        }
        foreach ($payroll as $p) {
            $i = (int) substr($p->period, 5, 2) - 1;
            $m[$i]['payroll'] = $m[$i]['payroll']->plus((string) $p->final_salary);
        }
        $t = array_fill_keys(array_keys($m[0]), $zero);
        foreach ($m as $x) {
            foreach ($x as $k => $v) {
                $t[$k] = $t[$k]->plus($v);
            }
        }

        // ── Расчёты с клиентами на 31.12: подписано по договорам − оплачено
        $byClient = [];
        foreach ($signedToDate as $c) {
            $byClient[$c->client_id] ??= ['name' => $c->name, 'number' => $c->number, 'contracted' => $zero, 'paid' => $zero];
            $byClient[$c->client_id]['contracted'] = $byClient[$c->client_id]['contracted']->plus($c->amount_uzs);
        }
        $names = DB::table('clients')->whereIn('id', $allPaid->pluck('client_id')->unique()->all() ?: ['-'])->get(['id', 'name', 'number'])->keyBy('id');
        foreach ($allPaid as $p) {
            $c = $names[$p->client_id];
            $byClient[$p->client_id] ??= ['name' => $c->name, 'number' => $c->number, 'contracted' => $zero, 'paid' => $zero];
            $byClient[$p->client_id]['paid'] = $byClient[$p->client_id]['paid']->plus($sign($p));
        }
        $settlements = array_values(array_filter(array_map(fn ($r) => $r + ['balance' => $r['contracted']->minus($r['paid'])], $byClient), fn ($r) => ! $r['balance']->isZero()));
        $coll = collator_create('ru_RU');
        usort($settlements, fn ($a, $b) => $coll ? collator_compare($coll, $a['name'], $b['name']) : strcmp($a['name'], $b['name']));
        $receivable = array_reduce($settlements, fn ($s, $r) => $r['balance']->isGreaterThan(0) ? $s->plus($r['balance']) : $s, $zero);
        $advances = array_reduce($settlements, fn ($s, $r) => $r['balance']->isLessThan(0) ? $s->plus($r['balance']->negated()) : $s, $zero);

        // ── Задолженность по зарплате и комиссиям на конец года
        $toC = CarbonImmutable::parse($to, 'UTC');
        $payrollDebt = $payroll->filter(fn ($p) => $p->status === 'APPROVED' || ($p->status === 'PAID' && $p->paid_at && $p->paid_at->gte($toC)))
            ->reduce(fn ($s, $p) => $s->plus((string) $p->final_salary), $zero);
        $commissionsUnpaid = $commissions->filter(fn ($c) => $c->status !== 'PAID' || ($c->paid_at && $c->paid_at->gte($toC)))
            ->reduce(fn ($s, $c) => $s->plus((string) $c->amount_uzs), $zero);
        $d = fn (BigDecimal $v) => self::d($v);
        $local = fn ($v) => ExportService::local($v);
        $day = fn ($v) => ExportService::day($v);
        $num = fn ($v) => ExportService::num($v);

        $notes = new Sheet('Пояснения', [['header' => 'Пакет для бухгалтера', 'width' => 60], ['header' => '', 'width' => 70]], [
            ['Компания', $company['name'] ?: '— (укажите в «Настройки → Автоматизация → Компания»)'],
            ['ИНН (СТИР)', $company['inn'] ?: '—'],
            ['Налоговый режим', self::TAX_REGIMES[$company['taxRegime']] ?? self::TAX_REGIMES['OTHER']],
            ['Руководитель', $company['director'] ?: '—'],
            ['Бухгалтер', $company['accountant'] ?: '—'],
            ['Период', '01.01.'.$year.' — 31.12.'.$year],
            ['Сформировано', Tasks::companyDate($now).' ('.brand()->name().')'],
            ['Валюта', 'UZS. Суммы в USD пересчитаны по курсу на дату операции (колонка «Курс»)'],
            [null, null],
            ['Как посчитано', null],
            ['Договоры', 'Подписанные в году договоры (дата подписания)'],
            ['Оплаты', 'Подтверждённые РОП оплаты по дате поступления; возвраты — с минусом'],
            ['Расчёты с клиентами', 'На 31.12: сумма подписанных договоров − оплачено. «+» — долг клиента (дебиторка), «−» — аванс полученный'],
            ['Расходы', 'Внесённые в CRM расходы по дате расхода; колонка «Отнесение» — подсказка по счёту НСБУ №21'],
            ['Зарплата', 'Расчёт CRM: оклад + KPI-бонус + комиссия + бонусы − штраф. НДФЛ и социальный налог не начислены'],
            ['Комиссии', 'Справочно: уже включены в зарплату месяца (колонка «Комиссия» на листе «Зарплата»)'],
            ['Проекты', 'Проекты в работе на конец года и завершённые в году: для актов выполненных работ и незавершённых услуг'],
            ['Сводка (форма №2)', 'Черновик по данным CRM: выручка — по оплатам (кассовый метод). По НСБУ выручку признают по актам — проверьте по листу «Проекты»'],
            [null, null],
            ['Чего нет в CRM (нужно из бухгалтерии и банка)', null],
            ['•', 'Остатки денег в кассе и на счетах, банковские выписки'],
            ['•', 'Основные средства, нематериальные активы и их износ'],
            ['•', 'Кредиты и займы, уставный капитал, дивиденды'],
            ['•', 'Налоги и взносы (начисление и уплата), НДФЛ и социальный налог с зарплаты'],
            ['•', 'Акты выполненных работ и счета-фактуры (Didox / Faktura.uz), расчёты с поставщиками'],
            [null, null],
            ['Итоги года', null],
            ['Подписано договоров, UZS', $d($t['contracts'])],
            ['Получено оплат (за вычетом возвратов), UZS', $d($t['paid'])],
            ['Расходы на проекты (себестоимость), UZS', $d($t['cost'])],
            ['Расходы компании, UZS', $d($t['sales']->plus($t['admin']))],
            ['Зарплата начислена (CRM), UZS', $d($t['payroll'])],
            ['Прочие поступления, UZS', $d($t['income'])],
            ['Дебиторская задолженность клиентов на 31.12, UZS', $d($receivable)],
            ['Авансы полученные от клиентов на 31.12, UZS', $d($advances)],
            ['Зарплата начислена, но не выплачена на 31.12, UZS', $d($payrollDebt)],
        ]);

        $lines = [
            ['010', 'Чистая выручка (по оплатам)', fn ($x) => $x['paid']],
            ['020', 'Себестоимость: расходы на проекты', fn ($x) => $x['cost']],
            ['030', 'Валовая прибыль', fn ($x) => $x['paid']->minus($x['cost'])],
            ['040', 'Расходы периода, всего', fn ($x) => $x['sales']->plus($x['admin'])->plus($x['payroll'])],
            ['050', '  расходы по реализации (реклама компании)', fn ($x) => $x['sales']],
            ['060', '  административные (прочие расходы компании)', fn ($x) => $x['admin']],
            ['060', '  зарплата (без НДФЛ и соцналога)', fn ($x) => $x['payroll']],
            ['090', 'Прочие доходы (прочие поступления)', fn ($x) => $x['income']],
            ['100', 'Прибыль от основной деятельности', fn ($x) => $x['paid']->minus($x['cost'])->minus($x['sales'])->minus($x['admin'])->minus($x['payroll'])->plus($x['income'])],
            ['—', 'Справочно: подписано договоров', fn ($x) => $x['contracts']],
        ];
        $summary = new Sheet('Сводка (форма 2)', [
            ['header' => 'Строка'], ['header' => 'Показатель', 'width' => 52], ['header' => 'За год, UZS', 'money' => true, 'width' => 18],
            ...array_map(fn ($h) => ['header' => $h, 'money' => true, 'width' => 15], self::MONTHS),
        ], array_map(fn ($l) => [$l[0], $l[1], $d(($l[2])($t)), ...array_map(fn ($x) => $d(($l[2])($x)), $m)], $lines));

        $sheets = [$notes, $summary];
        $sheets[] = new Sheet('Договоры', [
            ['header' => 'Номер'], ['header' => 'Дата договора', 'date' => true], ['header' => 'Подписан', 'date' => true], ['header' => 'Клиент', 'width' => 26],
            ['header' => 'Сделка', 'width' => 28], ['header' => 'Сумма', 'money' => true], ['header' => 'Валюта'], ['header' => 'Курс'], ['header' => 'Сумма, UZS', 'money' => true],
        ], $contracts->map(fn ($c) => [self::contractNo($c->number), $day($c->contract_date), $local($c->signed_at), $c->client?->name,
            Format::code('D', $c->deal?->number).' '.$c->deal?->title, $num($c->amount), $c->currency, $num($c->exchange_rate), $num($c->amount_uzs)])->all());
        $sheets[] = new Sheet('Оплаты', [
            ['header' => 'Номер'], ['header' => 'Дата поступления', 'date' => true], ['header' => 'Клиент', 'width' => 26], ['header' => 'Договор'],
            ['header' => 'Сделка', 'width' => 28], ['header' => 'Тип'], ['header' => 'Способ'], ['header' => 'Сумма', 'money' => true], ['header' => 'Валюта'],
            ['header' => 'Курс'], ['header' => 'Сумма, UZS', 'money' => true],
        ], $payments->map(fn ($p) => [Format::code('PAY', $p->number), $local($p->paid_at), $p->client?->name, $p->contract ? self::contractNo($p->contract->number) : null,
            Format::code('D', $p->deal?->number).' '.$p->deal?->title, self::PAYMENT_TYPE[$p->type] ?? $p->type, self::PAYMENT_METHOD[$p->method] ?? $p->method,
            $p->type === 'REFUND' ? -$num($p->amount) : $num($p->amount), $p->currency, $num($p->exchange_rate), $d($sign($p))])->all());
        $sheets[] = new Sheet('Прочие поступления', [
            ['header' => 'Номер'], ['header' => 'Дата', 'date' => true], ['header' => 'Категория', 'width' => 24], ['header' => 'Подсказка счёта'],
            ['header' => 'Клиент', 'width' => 24], ['header' => 'Проект', 'width' => 28], ['header' => 'Описание', 'width' => 30],
            ['header' => 'Сумма', 'money' => true], ['header' => 'Валюта'], ['header' => 'Сумма, UZS', 'money' => true],
        ], $incomes->map(fn ($r) => [Format::code('INC', $r->number), $day($r->income_date), $cat($r->category)?->name ?? $r->category, $cat($r->category)?->account_hint, $r->client?->name,
            $r->project ? Format::code('P', $r->project->number).' '.$r->project->name : null, $r->description, $num($r->amount), $r->currency, $num($r->amount_uzs)])->all());
        $sheets[] = new Sheet('Расчёты с клиентами 31.12', [
            ['header' => 'Клиент', 'width' => 28], ['header' => 'Номер'], ['header' => 'Подписано договоров, UZS', 'money' => true, 'width' => 20],
            ['header' => 'Оплачено, UZS', 'money' => true, 'width' => 18], ['header' => 'Долг клиента (дебиторка), UZS', 'money' => true, 'width' => 22],
            ['header' => 'Аванс полученный, UZS', 'money' => true, 'width' => 20],
        ], [...array_map(fn ($r) => [$r['name'], Format::code('C', $r['number']), $d($r['contracted']), $d($r['paid']),
            $r['balance']->isGreaterThan(0) ? $d($r['balance']) : null, $r['balance']->isLessThan(0) ? $d($r['balance']->negated()) : null], $settlements),
            ['Итого', null, null, null, $d($receivable), $d($advances)]]);
        $sheets[] = new Sheet('Расходы', [
            ['header' => 'Номер'], ['header' => 'Дата', 'date' => true], ['header' => 'Тип'], ['header' => 'Категория', 'width' => 16],
            ['header' => 'Отнесение (подсказка)', 'width' => 30], ['header' => 'Проект', 'width' => 28], ['header' => 'Описание', 'width' => 30],
            ['header' => 'Получатель', 'width' => 20], ['header' => 'Сумма', 'money' => true], ['header' => 'Валюта'], ['header' => 'Сумма, UZS', 'money' => true],
            ['header' => 'Внёс', 'width' => 18],
        ], $expenses->map(fn ($e) => [Format::code('EXP', $e->number), $day($e->expense_date), $e->scope === 'PROJECT' ? 'Проект' : 'Компания',
            $cat($e->category)?->name ?? $e->category, self::expenseAccount($e->scope, $cat($e->category)?->account_hint),
            $e->project ? Format::code('P', $e->project->number).' '.$e->project->name : null, $e->description, $e->payee?->full_name,
            $num($e->amount), $e->currency, $num($e->amount_uzs), $e->createdBy?->full_name])->all());
        $sheets[] = new Sheet('Зарплата', [
            ['header' => 'Месяц'], ['header' => 'Сотрудник', 'width' => 24], ['header' => 'Оклад', 'money' => true], ['header' => 'Сдельно', 'money' => true],
            ['header' => 'За ролики SMM', 'money' => true, 'width' => 16], ['header' => 'KPI-бонус', 'money' => true], ['header' => 'Комиссия', 'money' => true], ['header' => 'Бонус', 'money' => true], ['header' => 'Штраф', 'money' => true],
            ['header' => 'Итого начислено', 'money' => true, 'width' => 16], ['header' => 'Статус'], ['header' => 'Выплачено', 'date' => true],
        ], $payroll->map(fn ($p) => [$p->period, $p->user?->full_name, $num($p->base_salary), $num($p->piece_rate), $num($p->smm_video_pay), $num($p->kpi_bonus), $num($p->commission),
            $num($p->other_bonus), $num($p->penalty), $num($p->final_salary), ['DRAFT' => 'Черновик', 'APPROVED' => 'Утверждена', 'PAID' => 'Выплачена'][$p->status] ?? $p->status,
            $local($p->paid_at)])->all());
        $sheets[] = new Sheet('Комиссии', [
            ['header' => 'Месяц'], ['header' => 'Сотрудник', 'width' => 24], ['header' => 'Сделка', 'width' => 30], ['header' => 'База, UZS', 'money' => true],
            ['header' => 'Ставка'], ['header' => 'Комиссия, UZS', 'money' => true], ['header' => 'Статус'], ['header' => 'Выплачена', 'date' => true],
        ], [...$commissions->map(fn ($c) => [$c->period, $c->user?->full_name, Format::code('D', $c->deal?->number).' '.$c->deal?->title, $num($c->base_amount_uzs),
            $num($c->rate), $num($c->amount_uzs), ['ACCRUED' => 'Начислена', 'APPROVED' => 'Утверждена', 'PAID' => 'Выплачена', 'CANCELLED' => 'Отменена'][$c->status] ?? $c->status,
            $local($c->paid_at)])->all(), ['Не выплачено на 31.12', null, null, null, null, $d($commissionsUnpaid), null, null]]);

        $projectIds = $projects->pluck('id')->all() ?: ['-'];
        $projectExp = DB::table('expenses')->whereNull('deleted_at')->where('expense_date', '<', $dTo)->whereIn('project_id', $projectIds)
            ->groupBy('project_id')->selectRaw('project_id, SUM(amount_uzs) AS s')->pluck('s', 'project_id');
        $projectPaid = DB::table('payments')->join('projects', 'projects.deal_id', '=', 'payments.deal_id')->whereIn('projects.id', $projectIds)
            ->where('payments.status', 'PAID')->where('payments.paid_at', '<', $to)->get(['projects.id as pid', 'payments.type', 'payments.amount_uzs'])->groupBy('pid');
        $sheets[] = new Sheet('Проекты', [
            ['header' => 'Номер'], ['header' => 'Проект', 'width' => 30], ['header' => 'Клиент', 'width' => 24], ['header' => 'Статус на сегодня', 'width' => 16],
            ['header' => 'Завершён', 'date' => true], ['header' => 'Стоимость, UZS', 'money' => true], ['header' => 'Оплачено на 31.12, UZS', 'money' => true, 'width' => 18],
            ['header' => 'Расходы на 31.12, UZS', 'money' => true, 'width' => 18], ['header' => 'Работы завершены в году', 'width' => 14],
        ], $projects->map(fn ($p) => [Format::code('P', $p->number), $p->name, $p->client?->name, self::PROJECT_STATUS[$p->status] ?? $p->status, $local($p->completed_at),
            $num($p->price_uzs), $d(collect($projectPaid->get($p->id, []))->reduce(fn ($s, $x) => $s->plus($sign($x)), $zero)),
            $d(BigDecimal::of((string) ($projectExp[$p->id] ?? 0))), $p->completed_at && $p->completed_at->lt($toC) ? 'Да' : 'Нет'])->all());

        $book = new Spreadsheet;
        $book->getProperties()->setCreator(brand()->name());
        foreach ($sheets as $i => $s) {
            // Лист «Пояснения» — без автофильтра и с жирными заголовками разделов
            $s->addTo($book, $i !== 0);
        }
        $ws = $book->getSheet(0);
        foreach ($notes->rows as $ri => $r) {
            $row = $ri + 2;
            if ($r[1] === null && $r[0]) {
                $ws->getStyle([1, $row, 2, $row])->getFont()->setBold(true);
            }
            if (is_float($r[1])) {
                $ws->getStyle([2, $row])->getNumberFormat()->setFormatCode('#,##0.00');
            }
        }
        $book->setActiveSheetIndex(0);
        $tmp = tempnam(sys_get_temp_dir(), 'acc');
        (new Xlsx($book))->save($tmp);
        $body = file_get_contents($tmp);
        @unlink($tmp);

        return [
            'body' => $body,
            'filename' => 'fluggi-buhgalter-'.$year.'.xlsx',
            'counts' => ['contracts' => $contracts->count(), 'payments' => $payments->count(), 'expenses' => $expenses->count(), 'payroll' => $payroll->count()],
        ];
    }

    public static function file(int $year): array
    {
        $f = self::build($year);
        DB::transaction(fn () => Audit::log('export.run', 'accountant_package', null, ['export' => ['old' => null, 'new' => ['year' => $year] + $f['counts']]]));

        return $f;
    }
}
