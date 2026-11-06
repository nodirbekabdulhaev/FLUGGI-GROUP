<?php

namespace App\Services\Analytics;

use App\Auth\Access;
use App\Domain\Tasks;
use App\Models\Expense;
use App\Models\Payment;
use App\Services\Crm\CrmAccess;
use App\Support\Audit;
use App\Support\Format;
use App\Support\Lang;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Экспорт в Excel/CSV (ТЗ §44). Данные — строго в пределах прав пользователя на сущность,
 * каждая выгрузка записывается в журнал аудита.
 */
final class ExportService
{
    public const ENTITIES = ['leads', 'deals', 'clients', 'payments', 'expenses', 'projects', 'tasks'];

    /** Максимум строк в одном файле — защита от случайной выгрузки всей базы. */
    public const LIMIT = 50_000;

    /** Момент → дата и время по Ташкенту (Excel показывает «как есть»). */
    public static function local($d): ?CarbonImmutable
    {
        return $d ? CarbonImmutable::parse($d, 'UTC')->setTimezone(Format::tz())->shiftTimezone('UTC') : null;
    }

    /** Календарная дата (без времени). */
    public static function day($d): ?CarbonImmutable
    {
        return $d ? CarbonImmutable::createFromFormat('!Y-m-d', substr(is_string($d) ? $d : $d->format('Y-m-d'), 0, 10), 'UTC') : null;
    }

    public static function num($v): ?float
    {
        return $v === null ? null : (float) (string) $v;
    }

    private static function col(string $key, array $opts = []): array
    {
        return ['header' => t('export.columns.'.$key)] + $opts;
    }

    private static function ref(?object $r): ?string
    {
        if (! $r) {
            return null;
        }

        return Lang::name($r);
    }

    private static function label(string $group, ?string $v): ?string
    {
        return $v ? t($group.'.'.$v) : null;
    }

    /** @return array{0: ?string, 1: ?string} период выгрузки (all — без ограничения) */
    private static function range(string $period, ?string $from, ?string $to): ?array
    {
        if ($period === 'all') {
            return null;
        }
        [$a, $b] = Period::resolve($period, $from, $to);

        return [Period::ts($a), Period::ts($b)];
    }

    public static function sheet(Access $a, string $entity, string $period = 'all', ?string $from = null, ?string $to = null): Sheet
    {
        $r = self::range($period, $from, $to);
        $created = fn ($q, string $col = 'created_at') => $r ? $q->where($col, '>=', $r[0])->where($col, '<', $r[1]) : $q;
        $title = t('export.entities.'.$entity);

        return match ($entity) {
            'leads' => new Sheet($title, [
                self::col('number'), self::col('title', ['width' => 30]), self::col('contact', ['width' => 20]), self::col('company', ['width' => 22]),
                self::col('phone', ['width' => 16]), self::col('telegram'), self::col('email', ['width' => 22]), self::col('source', ['width' => 16]),
                self::col('service', ['width' => 16]), self::col('budget', ['money' => true]), self::col('currency'), self::col('stage', ['width' => 16]),
                self::col('status'), self::col('owner', ['width' => 20]), self::col('team'), self::col('createdAt', ['date' => true]),
            ], $created(CrmAccess::leads(), 'leads.created_at')->with(['source', 'service', 'stage', 'owner' => fn ($q) => $q->withTrashed(), 'team' => fn ($q) => $q->withTrashed()])
                ->orderBy('number')->limit(self::LIMIT)->get()->map(fn ($l) => [
                    Format::code('L', $l->number), $l->title, $l->contact_name, $l->company_name, $l->phone, $l->telegram, $l->email,
                    self::ref($l->source), self::ref($l->service), self::num($l->budget), $l->currency, self::ref($l->stage),
                    self::label('crm.status.lead', $l->status), $l->owner?->full_name, $l->team?->name, self::local($l->created_at),
                ])->all()),
            'deals' => new Sheet($title, [
                self::col('number'), self::col('title', ['width' => 30]), self::col('client', ['width' => 24]), self::col('service', ['width' => 16]),
                self::col('amount', ['money' => true]), self::col('currency'), self::col('amountUzs', ['money' => true]), self::col('stage', ['width' => 16]),
                self::col('status'), self::col('repeat'), self::col('owner', ['width' => 20]), self::col('team'),
                self::col('createdFem', ['date' => true]), self::col('paidAt', ['date' => true]),
            ], $created(CrmAccess::deals(), 'deals.created_at')->with(['client' => fn ($q) => $q->withTrashed(), 'service', 'stage', 'owner' => fn ($q) => $q->withTrashed(), 'team' => fn ($q) => $q->withTrashed()])
                ->orderBy('number')->limit(self::LIMIT)->get()->map(fn ($d) => [
                    Format::code('D', $d->number), $d->title, $d->client?->name, self::ref($d->service), self::num($d->amount), $d->currency,
                    self::num($d->amount_uzs), self::ref($d->stage), self::label('crm.status.deal', $d->status), t($d->is_repeat ? 'common.yes' : 'common.no'),
                    $d->owner?->full_name, $d->team?->name, self::local($d->created_at), self::local($d->won_at),
                ])->all()),
            'clients' => self::clients($a, $title, $created),
            'payments' => new Sheet($title, [
                self::col('number'), self::col('deal', ['width' => 28]), self::col('client', ['width' => 24]), self::col('type'), self::col('method'),
                self::col('status', ['width' => 20]), self::col('amount', ['money' => true]), self::col('currency'), self::col('rate'),
                self::col('amountUzs', ['money' => true]), self::col('dueDate', ['date' => true]), self::col('paidAt', ['date' => true]), self::col('confirmedBy', ['width' => 20]),
            ], Payment::with(['deal' => fn ($q) => $q->withTrashed(), 'client' => fn ($q) => $q->withTrashed(), 'confirmedBy' => fn ($q) => $q->withTrashed()])
                ->whereIn('deal_id', CrmAccess::deals('payment.read')->select('deals.id'))
                ->when($r, fn ($q) => $q->where(fn ($w) => $w->where(fn ($x) => $x->where('paid_at', '>=', $r[0])->where('paid_at', '<', $r[1]))
                    ->orWhere(fn ($x) => $x->whereNull('paid_at')->where('created_at', '>=', $r[0])->where('created_at', '<', $r[1]))))
                ->orderBy('number')->limit(self::LIMIT)->get()->map(fn ($p) => [
                    Format::code('PAY', $p->number), Format::code('D', $p->deal?->number).' '.$p->deal?->title, $p->client?->name,
                    self::label('export.paymentType', $p->type), self::label('export.paymentMethod', $p->method), self::label('export.paymentStatus', $p->status),
                    self::num($p->amount), $p->currency, self::num($p->exchange_rate), self::num($p->amount_uzs), self::day($p->due_date),
                    self::local($p->paid_at), $p->confirmedBy?->full_name,
                ])->all()),
            'expenses' => self::expenses($a, $title, $r),
            'projects' => new Sheet($title, [
                self::col('number'), self::col('project', ['width' => 30]), self::col('client', ['width' => 24]), self::col('status', ['width' => 16]),
                self::col('manager', ['width' => 20]), self::col('rop', ['width' => 20]), self::col('start', ['date' => true]), self::col('deadline', ['date' => true]),
                self::col('priceUzs', ['money' => true]), self::col('createdAt', ['date' => true]), self::col('completedAt', ['date' => true]),
            ], $created(ProjectScope::projects($a))->with(['client' => fn ($q) => $q->withTrashed(), 'manager' => fn ($q) => $q->withTrashed(), 'rop' => fn ($q) => $q->withTrashed()])
                ->orderBy('number')->limit(self::LIMIT)->get()->map(fn ($p) => [
                    Format::code('P', $p->number), $p->name, $p->client?->name, self::label('export.projectStatus', $p->status), $p->manager?->full_name,
                    $p->rop?->full_name, self::day($p->start_date), self::day($p->deadline), self::num($p->price_uzs), self::local($p->created_at), self::local($p->completed_at),
                ])->all()),
            'tasks' => new Sheet($title, [
                self::col('number'), self::col('task', ['width' => 34]), self::col('project', ['width' => 28]), self::col('assignee', ['width' => 20]),
                self::col('status', ['width' => 14]), self::col('priority'), self::col('deadline', ['date' => true]), self::col('reworks'),
                self::col('createdFem', ['date' => true]), self::col('doneAt', ['date' => true]),
            ], $created(ProjectScope::tasks($a))->with(['project' => fn ($q) => $q->withTrashed(), 'assignee' => fn ($q) => $q->withTrashed()])
                ->orderBy('number')->limit(self::LIMIT)->get()->map(fn ($t) => [
                    Format::code('T', $t->number), $t->title, Format::code('P', $t->project?->number).' '.$t->project?->name, $t->assignee?->full_name,
                    self::label('export.taskStatus', $t->status), self::label('crm.priority', $t->priority), self::local($t->deadline), (int) $t->rework_count,
                    self::local($t->created_at), self::local($t->completed_at),
                ])->all()),
        };
    }

    private static function clients(Access $a, string $title, callable $created): Sheet
    {
        $rows = $created(CrmAccess::clients(), 'clients.created_at')->with(['owner' => fn ($q) => $q->withTrashed(), 'team' => fn ($q) => $q->withTrashed()])
            ->orderBy('number')->limit(self::LIMIT)->get();
        $m = ClientInsights::compute($rows->pluck('id')->all());

        return new Sheet($title, [
            self::col('number'), self::col('name', ['width' => 28]), self::col('clientType'), self::col('phone', ['width' => 16]), self::col('email', ['width' => 22]),
            self::col('telegram'), self::col('city'), self::col('owner', ['width' => 20]), self::col('team'), self::col('ltv', ['money' => true]),
            self::col('paidDeals'), self::col('lastPayment', ['date' => true]), self::col('health'), self::col('score'), self::col('createdAt', ['date' => true]),
        ], $rows->map(function ($c) use ($m) {
            $x = $m[$c->id];

            return [
                Format::code('C', $c->number), $c->name, self::label('crm.clientType', $c->type), $c->phone, $c->email, $c->telegram, $c->city,
                $c->owner?->full_name, $c->team?->name, (float) $x['ltv'], $x['paidDeals'], self::local($x['lastPaymentAt']),
                self::label('crm.health', $x['health']['level']), $x['health']['score'], self::local($c->created_at),
            ];
        })->all());
    }

    /** Расходы, которые пользователь видит (finance.read): проектные — по видимости проекта, компании — CEO. */
    public static function expensesQuery(Access $a)
    {
        $a->scope('finance.read');

        return Expense::query()->where(function ($w) use ($a) {
            $w->where(fn ($p) => $p->where('scope', 'PROJECT')->whereIn('project_id', ProjectScope::projects($a, 'finance.read')->select('projects.id')));
            if ($a->can('finance.company.read', 'ALL')) {
                $w->orWhere('scope', 'COMPANY');
            }
        });
    }

    private static function expenses(Access $a, string $title, ?array $r): Sheet
    {
        // Календарные даты расходов: [from; to) по Ташкенту
        $dates = $r ? [Tasks::companyDate(CarbonImmutable::parse($r[0], 'UTC')), Tasks::companyDate(CarbonImmutable::parse($r[1], 'UTC'))] : null;
        $cats = DB::table('finance_categories')->pluck('name', 'code');
        $rows = self::expensesQuery($a)
            ->with(['project' => fn ($q) => $q->withTrashed(), 'payee' => fn ($q) => $q->withTrashed(), 'createdBy' => fn ($q) => $q->withTrashed()])
            ->when($dates, fn ($q) => $q->where('expense_date', '>=', $dates[0])->where('expense_date', '<', $dates[1]))
            ->orderBy('expense_date')->limit(self::LIMIT)->get();

        return new Sheet($title, [
            self::col('number'), self::col('date', ['date' => true]), self::col('expenseScope'), self::col('category', ['width' => 16]), self::col('project', ['width' => 28]),
            self::col('description', ['width' => 30]), self::col('payee', ['width' => 20]), self::col('amount', ['money' => true]), self::col('currency'),
            self::col('amountUzs', ['money' => true]), self::col('createdBy', ['width' => 20]),
        ], $rows->map(fn ($e) => [
            Format::code('EXP', $e->number), self::day($e->expense_date), self::label('export.expenseScope', $e->scope), $cats[$e->category] ?? $e->category,
            $e->project ? Format::code('P', $e->project->number).' '.$e->project->name : null, $e->description, $e->payee?->full_name,
            self::num($e->amount), $e->currency, self::num($e->amount_uzs), $e->createdBy?->full_name,
        ])->all());
    }

    /** @return array{body:string,filename:string,contentType:string,rows:int} */
    public static function file(Access $a, string $entity, string $format, string $period, ?string $from, ?string $to): array
    {
        $sheet = self::sheet($a, $entity, $period, $from, $to);
        $base = 'fluggi-'.$entity.'-'.Tasks::companyDate(now());
        $out = $format === 'csv'
            ? ['body' => $sheet->toCsv(), 'filename' => $base.'.csv', 'contentType' => 'text/csv; charset=utf-8']
            : ['body' => Sheet::xlsx([$sheet]), 'filename' => $base.'.xlsx', 'contentType' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
        DB::transaction(fn () => Audit::log('export.run', $entity, null, ['export' => ['old' => null, 'new' => ['format' => $format, 'period' => $period, 'rows' => count($sheet->rows)]]]));

        return $out + ['rows' => count($sheet->rows)];
    }
}
