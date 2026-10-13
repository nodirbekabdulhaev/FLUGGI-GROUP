<?php

namespace App\Services\Accounting;

use App\Services\Analytics\Sheet;
use App\Services\Sales\Numbers;
use App\Support\Audit;
use App\Support\Format;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Выгрузки бухгалтерии в Excel: операции по фильтрам и налоговый отчёт за месяц. */
final class AccountingExport
{
    private const LIMIT = 50000;

    public const XLSX = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    private static function num(mixed $v): ?float
    {
        return $v === null ? null : (float) (string) $v;
    }

    private static function day($v): ?CarbonImmutable
    {
        return $v ? CarbonImmutable::parse($v->format('Y-m-d')) : null;
    }

    /** @return array{body:string,filename:string} */
    public static function operations(array $filters): array
    {
        $rows = OperationsService::query($filters)->limit(self::LIMIT)->get();
        $sheet = new Sheet(t('accounting.operations.title'), [
            ['header' => t('accounting.fields.date'), 'date' => true],
            ['header' => t('accounting.fields.account'), 'width' => 24],
            ['header' => t('accounting.fields.direction')],
            ['header' => t('accounting.fields.category'), 'width' => 24],
            ['header' => t('accounting.fields.amount'), 'money' => true, 'width' => 16],
            ['header' => t('accounting.fields.currency')],
            ['header' => t('accounting.fields.counterparty'), 'width' => 28],
            ['header' => t('accounting.fields.person'), 'width' => 24],
            ['header' => t('accounting.fields.period')],
            ['header' => t('accounting.fields.description'), 'width' => 32],
            ['header' => t('accounting.fields.crmPayment')],
            ['header' => t('accounting.fields.createdBy'), 'width' => 20],
        ], $rows->map(fn ($r) => [
            self::day($r->date), $r->account?->name, t('accounting.direction.'.$r->direction), t('accounting.category.'.$r->category),
            self::num($r->direction === 'OUT' ? '-'.$r->amount : $r->amount), $r->account?->currency, $r->counterparty, $r->person?->full_name,
            $r->period, $r->description, $r->payment ? Numbers::payment($r->payment->number) : null, $r->createdBy?->full_name,
        ])->all());
        self::audit('acc_operations', ['filters' => array_filter($filters), 'rows' => count($sheet->rows)]);

        return ['body' => Sheet::xlsx([$sheet]), 'filename' => 'fluggi-accounting-operations-'.Format::today()->toDateString().'.xlsx'];
    }

    /** Налоговый отчёт за месяц: налоги, ведомость, база IT Park. */
    public static function taxReport(string $period): array
    {
        $t = TaxesService::forPeriod($period);
        $taxes = new Sheet(t('accounting.taxes.title').' '.$period, [
            ['header' => t('accounting.taxes.tax'), 'width' => 28],
            ['header' => t('accounting.taxes.base'), 'money' => true, 'width' => 18],
            ['header' => t('accounting.taxes.rate')],
            ['header' => t('accounting.taxes.due'), 'money' => true, 'width' => 16],
            ['header' => t('accounting.taxes.paid'), 'money' => true, 'width' => 16],
            ['header' => t('accounting.taxes.balance'), 'money' => true, 'width' => 16],
        ], collect($t['taxes'])->map(fn ($x, $cat) => [
            t('accounting.category.'.$cat), self::num($x['base']), $x['rate'] === null ? null : $x['rate'].'%', self::num($x['due']), self::num($x['paid']), self::num($x['balance']),
        ])->values()->all());

        $sheet = TaxPayrollService::sheet($period);
        $payroll = new Sheet(t('accounting.payroll.title'), [
            ['header' => t('accounting.people.fullName'), 'width' => 28],
            ['header' => t('accounting.people.pinfl'), 'width' => 16],
            ['header' => t('accounting.people.position'), 'width' => 20],
            ['header' => t('accounting.payroll.gross'), 'money' => true, 'width' => 16],
            ['header' => t('accounting.payroll.ndfl'), 'money' => true, 'width' => 14],
            ['header' => t('accounting.payroll.inps'), 'money' => true, 'width' => 14],
            ['header' => t('accounting.payroll.net'), 'money' => true, 'width' => 16],
            ['header' => t('accounting.payroll.paid'), 'money' => true, 'width' => 16],
            ['header' => t('accounting.payroll.remaining'), 'money' => true, 'width' => 16],
            ['header' => t('accounting.payroll.status')],
        ], $sheet['rows']->map(fn ($r) => [
            $r->person?->full_name, $r->person?->pinfl, $r->person?->position, self::num($r->gross), self::num($r->ndfl), self::num($r->inps),
            self::num($r->net), self::num($r->paid), self::num($r->remaining), t('accounting.payroll.statuses.'.$r->status),
        ])->all());

        $income = new Sheet(t('accounting.taxes.bankIncome'), [
            ['header' => t('accounting.fields.date'), 'date' => true],
            ['header' => t('accounting.fields.account'), 'width' => 24],
            ['header' => t('accounting.fields.category'), 'width' => 22],
            ['header' => t('accounting.fields.counterparty'), 'width' => 28],
            ['header' => t('accounting.fields.amount'), 'money' => true, 'width' => 16],
            ['header' => t('accounting.fields.currency')],
            ['header' => t('accounting.taxes.amountUzs'), 'money' => true, 'width' => 16],
        ], TaxesService::bankIncome($period)['rows']->map(fn ($r) => [
            self::day($r->date), $r->account_name, t('accounting.category.'.$r->category), $r->counterparty, self::num($r->amount), $r->currency, self::num($r->uzs === null ? null : (string) $r->uzs),
        ])->all());
        self::audit('acc_tax_report', ['period' => $period]);

        return ['body' => Sheet::xlsx([$taxes, $payroll, $income]), 'filename' => 'fluggi-accounting-taxes-'.$period.'.xlsx'];
    }

    private static function audit(string $entity, array $info): void
    {
        DB::transaction(fn () => Audit::log('export.run', $entity, null, ['export' => ['old' => null, 'new' => $info]]));
    }
}
