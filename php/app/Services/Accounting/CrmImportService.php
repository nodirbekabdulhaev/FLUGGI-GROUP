<?php

namespace App\Services\Accounting;

use App\Exceptions\BusinessRule;
use App\Models\AccTransaction;
use App\Models\Payment;
use App\Services\References\ExchangeRates;
use App\Services\Sales\Numbers;
use App\Support\Audit;
use App\Support\Format;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Оплаты клиентов из CRM → операции бухгалтерии. Подтверждённая оплата попадает на счёт, у которого
 * отмечен её способ оплаты (нет такого счёта — пропускается); возврат (REFUND) — расход того же вида.
 * Повторный импорт ничего не дублирует: уникальность payment_id + direction.
 */
final class CrmImportService
{
    /** Результат одной оплаты: imported | exists | noAccount | notPaid | beforeOpening | noRate. */
    public static function import(string $paymentId, ?string $actorId = null): string
    {
        return DB::transaction(function () use ($paymentId, $actorId) {
            $p = Payment::with('client:id,name')->find($paymentId);
            if (! $p || $p->status !== 'PAID' || ! $p->paid_at) {
                return 'notPaid';
            }
            $direction = $p->type === 'REFUND' ? 'OUT' : 'IN';
            if (AccTransaction::withTrashed()->where('payment_id', $p->id)->where('direction', $direction)->exists()) {
                return 'exists';
            }
            $acc = AccountsService::forCrmMethod($p->method);
            if (! $acc) {
                return 'noAccount';
            }
            $date = CarbonImmutable::parse($p->paid_at)->setTimezone(Format::tz())->toDateString();
            if ($acc->opening_date && $date < $acc->opening_date->toDateString()) {
                return 'beforeOpening';
            }
            $amount = self::amountFor($p, $acc->currency, $date);
            if ($amount === null) {
                return 'noRate';
            }
            try {
                $row = DB::transaction(fn () => AccTransaction::create([
                    'account_id' => $acc->id, 'direction' => $direction, 'category' => 'CLIENT_PAYMENT', 'amount' => $amount,
                    'date' => $date, 'counterparty' => $p->client?->name ? mb_substr($p->client->name, 0, 191) : null,
                    'description' => Numbers::payment($p->number), 'payment_id' => $p->id, 'created_by_id' => null,
                ]));
            } catch (UniqueConstraintViolationException) {
                return 'exists';
            }
            Audit::log('accounting.crm_import', 'acc_transaction', $row->id, [
                'payment' => ['old' => null, 'new' => $p->id], 'direction' => ['old' => null, 'new' => $direction],
                'account' => ['old' => null, 'new' => $acc->name], 'amount' => ['old' => null, 'new' => $amount],
            ], $actorId);

            return 'imported';
        });
    }

    /**
     * Сумма в валюте счёта: UZS — amount_uzs оплаты; USD — сумма оплаты в долларах,
     * иначе пересчёт amount_uzs по курсу на дату оплаты.
     */
    private static function amountFor(Payment $p, string $currency, string $date): ?string
    {
        if ($currency === 'UZS') {
            return (string) Accounting::dec($p->amount_uzs)->toScale(2);
        }
        if ($p->currency === $currency) {
            return (string) Accounting::dec($p->amount)->toScale(2);
        }
        try {
            $rate = ExchangeRates::rateFor($currency, $date);
        } catch (BusinessRule) {
            return null;
        }

        return (string) Accounting::dec($p->amount_uzs)->dividedBy($rate, 2, RoundingMode::HalfUp);
    }

    /**
     * Загрузка всех подтверждённых оплат за период [from, to] (даты по Ташкенту).
     *
     * @return array<string,int> счётчики по результатам
     */
    public static function importPeriod(string $from, string $to): array
    {
        $a = CarbonImmutable::createFromFormat('!Y-m-d', $from, Format::tz())->utc();
        $b = CarbonImmutable::createFromFormat('!Y-m-d', $to, Format::tz())->addDay()->utc();
        $counts = ['imported' => 0, 'exists' => 0, 'noAccount' => 0, 'beforeOpening' => 0, 'noRate' => 0, 'notPaid' => 0];
        Payment::where('status', 'PAID')->where('paid_at', '>=', $a)->where('paid_at', '<', $b)->orderBy('paid_at')
            ->pluck('id')->each(function ($id) use (&$counts) {
                $counts[self::import($id)]++;
            });
        DB::transaction(fn () => Audit::log('accounting.crm_import_period', 'acc_transaction', null, [
            'period' => ['old' => null, 'new' => "$from — $to"], 'imported' => ['old' => null, 'new' => $counts['imported']],
        ]));

        return $counts;
    }
}
