<?php

namespace App\Services\People;

use App\Exceptions\BusinessRule;
use App\Models\AccAccount;
use App\Models\AccPerson;
use App\Models\AccTransaction;
use App\Models\EmployeeAdvance;
use App\Models\PayrollEntry;
use App\Models\User;
use App\Services\Accounting\OperationsService;
use App\Support\Audit;
use App\Support\Outbox;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Авансы сотрудникам. Выдаёт и отменяет CEO (payroll.manage на всю компанию), сотрудник видит свои.
 * Аванс удерживается из зарплаты месяца period (по умолчанию — месяц выдачи): payroll_entries.advances,
 * к выплате = итого − авансы. Утверждённая или выплаченная зарплата месяца неизменяема — аванс в такой
 * месяц не выдаётся и не отменяется. Не удаляется — только отмена с причиной.
 *
 * «Записать в Бухгалтерию» (нужно право accounting.manage): расход категории «Зарплата» с человеком
 * бухгалтерии, связанным с сотрудником, и периодом аванса — он входит в «выплачено» ведомости бухгалтерии
 * за этот месяц, поэтому при выплате остатка в Бухгалтерию вносится только остаток (без двойного учёта).
 * Отмена аванса удаляет (мягко, с той же причиной) и эту операцию.
 */
final class AdvanceService
{
    public const PERIOD_RE = '/^\d{4}-(0[1-9]|1[0-2])$/';

    /** Авансы сотрудника (новые сверху), с отменёнными. */
    public static function forUser(string $userId, int $limit = 100): Collection
    {
        return EmployeeAdvance::with(['createdBy:id,full_name', 'cancelledBy:id,full_name', 'transaction.account'])
            ->where('user_id', $userId)->orderByDesc('paid_at')->orderByDesc('created_at')->limit($limit)->get();
    }

    /** Сумма действующих авансов месяца: user_id → сумма (строка, 2 знака). */
    public static function sumByUser(string $period, ?array $userIds = null): array
    {
        return EmployeeAdvance::active()->where('period', $period)->when($userIds !== null, fn ($q) => $q->whereIn('user_id', $userIds ?: ['-']))
            ->groupBy('user_id')->selectRaw('user_id, SUM(amount) AS s')->pluck('s', 'user_id')
            ->map(fn ($s) => (string) BigDecimal::of((string) $s)->toScale(2))->all();
    }

    /**
     * @param  array{user_id:string,amount:string,paid_at:string,period?:?string,comment?:?string,accounting?:bool,account_id?:?string}  $d
     */
    public static function create(array $d): EmployeeAdvance
    {
        $access = access();
        $access->authorize('payroll.manage', 'ALL');
        $user = User::with('role')->where('status', 'ACTIVE')->find($d['user_id']);
        if (! $user || $user->role?->code === 'CEO') {
            throw new BusinessRule(t('payroll.advance.errors.user'), ['user_id' => t('payroll.advance.errors.user')]);
        }
        $amount = BigDecimal::of($d['amount'])->toScale(2);
        if (! $amount->isPositive()) {
            throw new BusinessRule(t('payroll.advance.errors.amount'), ['amount' => t('payroll.advance.errors.amount')]);
        }
        $period = ($d['period'] ?? null) ?: substr($d['paid_at'], 0, 7);
        if (! preg_match(self::PERIOD_RE, $period)) {
            throw new BusinessRule(t('payroll.advance.errors.period'), ['period' => t('payroll.advance.errors.period')]);
        }
        $comment = trim((string) ($d['comment'] ?? '')) ?: null;
        $toAccounting = (bool) ($d['accounting'] ?? false);
        $person = null;
        if ($toAccounting) {
            $access->authorize('accounting.manage');
            if (empty($d['account_id'])) {
                throw new BusinessRule(t('payroll.advance.errors.account'), ['account_id' => t('payroll.advance.errors.account')]);
            }
            $person = AccPerson::where('user_id', $user->id)->orderByDesc('is_active')->orderByDesc('created_at')->first();
            if (! $person) {
                throw new BusinessRule(t('payroll.advance.errors.person'), ['accounting' => t('payroll.advance.errors.person')]);
            }
        }

        return DB::transaction(function () use ($user, $amount, $period, $comment, $d, $person, $access) {
            self::assertOpen($user->id, $period);
            $txId = null;
            if ($person) {
                // Расход «Зарплата» с человеком и периодом — правила категории проверяет OperationsService
                $tx = OperationsService::create([
                    'account_id' => $d['account_id'], 'direction' => 'OUT', 'category' => 'SALARY', 'amount' => (string) $amount,
                    'date' => $d['paid_at'], 'period' => $period, 'counterparty' => null, 'person_id' => $person->id,
                    'description' => t('payroll.advance.accountingText', ['period' => $period]).($comment ? ' · '.$comment : ''),
                ]);
                $txId = $tx->id;
            }
            $a = EmployeeAdvance::create([
                'user_id' => $user->id, 'amount' => (string) $amount, 'paid_at' => $d['paid_at'], 'period' => $period,
                'comment' => $comment, 'created_by_id' => $access->id(), 'accounting_transaction_id' => $txId,
            ]);
            self::syncEntry($user->id, $period);
            Audit::log('payroll.advance_create', 'employee_advance', $a->id, [
                'user' => ['old' => null, 'new' => $user->full_name], 'amount' => ['old' => null, 'new' => (string) $amount],
                'paidAt' => ['old' => null, 'new' => $d['paid_at']], 'period' => ['old' => null, 'new' => $period],
                'accountingTransactionId' => ['old' => null, 'new' => $txId],
            ]);
            Outbox::publish('payroll.advance_created', [
                'advanceId' => $a->id, 'userId' => $user->id, 'amountUzs' => (string) $amount, 'period' => $period,
                'paidAt' => $d['paid_at'], 'accountingTransactionId' => $txId,
            ]);

            return $a;
        });
    }

    public static function cancel(string $id, string $reason): EmployeeAdvance
    {
        $access = access();
        $access->authorize('payroll.manage', 'ALL');
        $reason = trim($reason);
        if ($reason === '') {
            throw new BusinessRule(t('payroll.advance.errors.reason'), ['reason' => t('payroll.advance.errors.reason')]);
        }

        return DB::transaction(function () use ($id, $reason, $access) {
            $a = EmployeeAdvance::lockForUpdate()->findOrFail($id);
            if ($a->cancelled_at) {
                throw new BusinessRule(t('payroll.advance.errors.cancelled'));
            }
            self::assertOpen($a->user_id, $a->period);
            if ($a->accounting_transaction_id && AccTransaction::whereKey($a->accounting_transaction_id)->exists()) {
                if (! $access->can('accounting.manage')) {
                    throw new BusinessRule(t('payroll.advance.errors.accountingLinked'));
                }
                OperationsService::delete($a->accounting_transaction_id, $reason);
            }
            $a->update(['cancelled_at' => now(), 'cancelled_by_id' => $access->id(), 'cancel_reason' => $reason]);
            self::syncEntry($a->user_id, $a->period);
            Audit::log('payroll.advance_cancel', 'employee_advance', $a->id, [
                'cancelled' => ['old' => null, 'new' => (string) $a->amount], 'reason' => ['old' => null, 'new' => $reason],
            ]);
            Outbox::publish('payroll.advance_cancelled', [
                'advanceId' => $a->id, 'userId' => $a->user_id, 'amountUzs' => (string) $a->amount, 'period' => $a->period, 'reason' => $reason,
            ]);

            return $a->fresh();
        });
    }

    /** Зарплата месяца уже утверждена или выплачена — авансы этого месяца не меняются. */
    private static function assertOpen(string $userId, string $period): void
    {
        $entry = PayrollEntry::where('user_id', $userId)->where('period', $period)->lockForUpdate()->first();
        if ($entry && $entry->status !== 'DRAFT') {
            $msg = t('payroll.advance.errors.frozen', ['period' => $period]);
            throw new BusinessRule($msg, ['period' => $msg]);
        }
    }

    /** Черновик зарплаты месяца: авансы — сумма действующих авансов (итого не меняется, меняется «к выплате»). */
    public static function syncEntry(string $userId, string $period): void
    {
        $entry = PayrollEntry::where('user_id', $userId)->where('period', $period)->first();
        if ($entry && $entry->status === 'DRAFT') {
            $entry->update(['advances' => self::sumByUser($period, [$userId])[$userId] ?? '0.00']);
        }
    }

    /** Счета в сумах для записи аванса в Бухгалтерию. */
    public static function accounts(): Collection
    {
        return AccAccount::where('is_active', true)->where('currency', 'UZS')->orderBy('sort')->orderBy('name')->get(['id', 'name']);
    }

    /** К выплате по строке зарплаты: итого − авансы. */
    public static function payable(PayrollEntry $e): string
    {
        return (string) BigDecimal::of((string) $e->final_salary)->minus((string) ($e->advances ?? '0'))->toScale(2);
    }

    /** Месяц аванса по умолчанию — месяц даты выдачи (YYYY-MM). */
    public static function defaultPeriod(?string $paidAt = null): string
    {
        return ($paidAt ? CarbonImmutable::parse($paidAt) : CarbonImmutable::now(config('app.company_timezone')))->format('Y-m');
    }
}
