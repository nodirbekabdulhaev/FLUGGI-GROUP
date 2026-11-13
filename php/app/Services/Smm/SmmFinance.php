<?php

namespace App\Services\Smm;

use App\Domain\People;
use App\Exceptions\BusinessRule;
use App\Models\AccAccount;
use App\Models\AccCategory;
use App\Models\PayrollEntry;
use App\Models\SmmExpense;
use App\Models\SmmIdea;
use App\Models\User;
use App\Services\Accounting\OperationsService;
use App\Services\Finance\Period;
use App\Support\Audit;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * SMM → «Финансы»: сколько компания потратила на свой SMM за месяц и что это дало.
 *  - По людям: оклад SMM по плану роликов (из строки зарплаты, если месяц рассчитан, иначе оклад при 100 % × доля плана),
 *    оплата за ролики (начисления видеографам/монтажёрам), бонус SMM с продаж (комиссии роли SMM), итого,
 *    выплачено (строка зарплаты «Выплачена»), остаток. Выплата — через «Зарплату» (утвердить → выплатить).
 *  - Прочие расходы SMM (реквизит, студия, реклама…) — вносятся здесь; при желании сразу операцией в Бухгалтерии.
 *  - Итоги: расходы отдела, опубликовано роликов, цена ролика, лиды/сделки/выручка с источника «Видео SMM», ROI.
 * Доступ: право smm.finance или управление зарплатой всей компании (payroll.manage ALL).
 */
final class SmmFinance
{
    /** Подсказки категорий прочих расходов (переводы smm.finance.categories.*); можно вписать свою. */
    public const CATEGORIES = ['PROPS', 'STUDIO', 'ADS', 'OTHER'];

    public static function allowed(): bool
    {
        $a = access(false);

        return $a !== null && ($a->can('smm.finance') || $a->can('payroll.manage', 'ALL'));
    }

    public static function authorize(): void
    {
        if (! self::allowed()) {
            throw new HttpException(403);
        }
    }

    /**
     * @return array{people: Collection, totals: array<string,mixed>, expenses: Collection}
     */
    public static function month(string $period): array
    {
        self::authorize();
        ['from' => $from, 'to' => $to] = People::monthRange($period);
        [$d1, $d2] = Period::monthDates($period);

        $video = SmmVideoPay::byUser($period);
        $bonus = DB::table('commissions')->where('role', 'SMM')->where('period', $period)->whereIn('status', ['APPROVED', 'PAID'])
            ->groupBy('user_id')->selectRaw('user_id, SUM(amount_uzs) AS s')->pluck('s', 'user_id');
        $entries = PayrollEntry::where('period', $period)->get()->keyBy('user_id');
        $plans = SmmPayroll::plans()->filter(fn (User $u) => SmmPayroll::hasVideoPlan($u))->pluck('id')->all();
        $ids = array_values(array_unique(array_merge(array_keys($video), $bonus->keys()->all(), $plans,
            $entries->filter(fn ($e) => $e->videos_target !== null)->keys()->all())));
        $users = User::withTrashed()->with(['employee', 'role'])->whereIn('id', $ids ?: ['-'])->orderBy('full_name')->get();
        $roles = DB::table('smm_video_accruals')->where('period', $period)->where('status', 'ACCRUED')->whereIn('user_id', $ids ?: ['-'])
            ->distinct()->get(['user_id', 'role'])->groupBy('user_id');

        $zero = BigDecimal::zero();
        $people = collect();
        foreach ($users as $u) {
            $e = $entries[$u->id] ?? null;
            // Оклад SMM по плану роликов: из строки зарплаты (учтены ручные правки), иначе — по текущему факту
            if ($e && $e->videos_target !== null) {
                $fixed = BigDecimal::of((string) $e->base_salary);
                $fixedSource = 'payroll';
            } elseif (! $e && SmmPayroll::hasVideoPlan($u) && $u->employee->base_salary !== null) {
                $fixed = BigDecimal::of(SmmPayroll::base((string) $u->employee->base_salary, SmmIdeaService::publishedCount($u->id, $period), (int) $u->employee->video_target));
                $fixedSource = 'plan';
            } else {
                $fixed = $zero;
                $fixedSource = null;
            }
            // За ролики: утверждённая/выплаченная строка зарплаты неизменна — её сумма; иначе — действующие начисления
            if ($e && $e->status !== 'DRAFT') {
                $videoPay = BigDecimal::of((string) $e->smm_video_pay);
                $videoCount = (int) $e->smm_video_count;
                $parts = $video[$u->id]['parts'] ?? [];
                if (! BigDecimal::of($video[$u->id]['sum'] ?? '0')->isEqualTo($videoPay)) {
                    $parts = [];
                }
            } else {
                $videoPay = BigDecimal::of($video[$u->id]['sum'] ?? '0');
                $videoCount = $video[$u->id]['count'] ?? 0;
                $parts = $video[$u->id]['parts'] ?? [];
            }
            $bonusUzs = BigDecimal::of((string) ($bonus[$u->id] ?? '0'))->toScale(2);
            $total = $fixed->plus($videoPay)->plus($bonusUzs)->toScale(2);
            if ($total->isZero() && $videoCount === 0) {
                continue;
            }
            $paid = $e?->status === 'PAID' ? $total : $zero->toScale(2);
            $specialties = array_values(array_intersect($u->employee?->specialtyList() ?? [], SmmAccess::SPECIALTIES));
            $people->push([
                'user' => $u,
                'specialties' => $specialties,
                'videoRoles' => ($roles[$u->id] ?? collect())->pluck('role')->unique()->values()->all(),
                'fixed' => (string) $fixed->toScale(2),
                'fixedSource' => $fixedSource,
                'videoCount' => $videoCount,
                'videoPay' => (string) $videoPay->toScale(2),
                'videoParts' => $parts,
                'bonus' => (string) $bonusUzs,
                'total' => (string) $total,
                'paid' => (string) $paid,
                'remaining' => (string) $total->minus($paid)->toScale(2),
                'payrollStatus' => $e?->status,
            ]);
        }

        $expenses = SmmExpense::with(['createdBy:id,full_name', 'transaction.account:id,name'])
            ->where('date', '>=', $d1)->where('date', '<', $d2)->orderByDesc('date')->orderByDesc('created_at')->get();

        $sum = fn (string $k) => $people->reduce(fn (BigDecimal $s, $r) => $s->plus($r[$k]), $zero)->toScale(2);
        $other = $expenses->reduce(fn (BigDecimal $s, $x) => $s->plus((string) $x->amount_uzs), $zero)->toScale(2);
        $peopleTotal = $sum('total');
        $cost = $peopleTotal->plus($other)->toScale(2);
        $videos = SmmIdea::where('status', 'PUBLISHED')->where('published_at', '>=', $from)->where('published_at', '<', $to)->count();

        // Результат: лиды с источником «Видео SMM» месяца, выигранные сделки и оплаты по сделкам из роликов
        $smmDeals = fn ($q) => $q->select('id')->from('deals')->whereNotNull('smm_idea_id')->whereNull('deleted_at');
        $leads = DB::table('leads')->whereNull('leads.deleted_at')->join('lead_sources as s', 's.id', '=', 'leads.source_id')
            ->where('s.code', SmmIdeaService::LEAD_SOURCE)->where('leads.created_at', '>=', $from)->where('leads.created_at', '<', $to)->count();
        $won = DB::table('deals')->whereNotNull('smm_idea_id')->whereNull('deleted_at')->where('status', 'WON')
            ->where('won_at', '>=', $from)->where('won_at', '<', $to)->count();
        $revenue = $zero;
        foreach (DB::table('payments')->where('status', 'PAID')->where('paid_at', '>=', $from)->where('paid_at', '<', $to)
            ->whereIn('deal_id', $smmDeals)->get(['amount_uzs', 'type']) as $p) {
            $amount = BigDecimal::of((string) $p->amount_uzs);
            $revenue = $p->type === 'REFUND' ? $revenue->minus($amount) : $revenue->plus($amount);
        }

        return [
            'people' => $people,
            'expenses' => $expenses,
            'totals' => [
                'fixed' => (string) $sum('fixed'),
                'videoPay' => (string) $sum('videoPay'),
                'videoCount' => $people->sum('videoCount'),
                'bonus' => (string) $sum('bonus'),
                'people' => (string) $peopleTotal,
                'paid' => (string) $sum('paid'),
                'remaining' => (string) $sum('remaining'),
                'other' => (string) $other,
                'cost' => (string) $cost,
                'videos' => $videos,
                'costPerVideo' => $videos > 0 ? (string) $cost->dividedBy($videos, 2, RoundingMode::HalfUp) : null,
                'leads' => $leads,
                'won' => $won,
                'revenue' => (string) $revenue->toScale(2),
                'roi' => $cost->isGreaterThan(0) ? (string) $revenue->minus($cost)->multipliedBy(100)->dividedBy($cost, 1, RoundingMode::HalfUp) : null,
            ],
        ];
    }

    /** Счета в сумах для записи расхода в Бухгалтерию. */
    public static function accounts(): Collection
    {
        return AccAccount::where('is_active', true)->where('currency', 'UZS')->orderBy('sort')->orderBy('name')->get(['id', 'name']);
    }

    /** Статьи расхода: базовая «Прочее» и свои статьи на её основе. */
    public static function articles(): array
    {
        $out = ['OTHER' => t('accounting.category.OTHER')];
        foreach (AccCategory::where('direction', 'OUT')->where('is_active', true)->where('base', 'OTHER')->orderBy('sort')->orderBy('name')->get() as $c) {
            $out[$c->id] = $c->name;
        }

        return $out;
    }

    /** Статья по умолчанию: своя статья со словом «SMM» в названии, иначе «Прочее». */
    public static function defaultArticle(): string
    {
        foreach (self::articles() as $key => $name) {
            if ($key !== 'OTHER' && Str::contains(mb_strtolower($name), 'smm')) {
                return $key;
            }
        }

        return 'OTHER';
    }

    /**
     * @param  array{date:string, category:string, amount:string, comment:?string, to_accounting?:bool, account_id?:?string, article?:?string}  $d
     */
    public static function addExpense(array $d): SmmExpense
    {
        self::authorize();
        $toAccounting = (bool) ($d['to_accounting'] ?? false);
        if ($toAccounting) {
            access()->authorize('accounting.manage');
            if (empty($d['account_id'])) {
                throw new BusinessRule(t('smm.finance.errors.accountRequired'), ['account_id' => t('smm.finance.errors.accountRequired')]);
            }
            if (! self::accounts()->contains('id', $d['account_id'])) {
                throw new BusinessRule(t('smm.finance.errors.accountInvalid'), ['account_id' => t('smm.finance.errors.accountInvalid')]);
            }
            $article = ($d['article'] ?? null) ?: 'OTHER';
            if (! array_key_exists($article, self::articles())) {
                throw new BusinessRule(t('accounting.errors.articleInvalid'), ['article' => t('accounting.errors.articleInvalid')]);
            }
        }
        $amount = BigDecimal::of($d['amount'])->toScale(2);
        if ($amount->isLessThanOrEqualTo(0)) {
            throw new BusinessRule(t('smm.finance.errors.amountPositive'), ['amount' => t('smm.finance.errors.amountPositive')]);
        }
        $category = trim($d['category']);
        $comment = trim((string) ($d['comment'] ?? '')) ?: null;

        return DB::transaction(function () use ($d, $amount, $category, $comment, $toAccounting) {
            $txId = null;
            if ($toAccounting) {
                $tx = OperationsService::create([
                    'account_id' => $d['account_id'], 'direction' => 'OUT', 'category' => ($d['article'] ?? null) ?: 'OTHER',
                    'amount' => (string) $amount, 'date' => $d['date'], 'period' => null, 'counterparty' => null,
                    'description' => 'SMM: '.$category.($comment ? ' — '.$comment : ''), 'person_id' => null,
                ]);
                $txId = $tx->id;
            }
            $x = SmmExpense::create([
                'date' => $d['date'], 'category' => $category, 'amount_uzs' => (string) $amount, 'comment' => $comment,
                'acc_transaction_id' => $txId, 'created_by_id' => access()->id(),
            ]);
            Audit::log('smm.expense_create', 'smm_expense', $x->id, array_filter([
                'date' => ['old' => null, 'new' => $d['date']], 'category' => ['old' => null, 'new' => $category],
                'amount' => ['old' => null, 'new' => (string) $amount], 'comment' => $comment ? ['old' => null, 'new' => $comment] : null,
                'accTransaction' => $txId ? ['old' => null, 'new' => $txId] : null,
            ]));

            return $x;
        });
    }

    /** Удаление расхода; связанная операция Бухгалтерии удаляется вместе с ним (нужно право на Бухгалтерию). */
    public static function deleteExpense(string $id): void
    {
        self::authorize();
        DB::transaction(function () use ($id) {
            $x = SmmExpense::lockForUpdate()->findOrFail($id);
            if ($x->acc_transaction_id && DB::table('acc_transactions')->where('id', $x->acc_transaction_id)->whereNull('deleted_at')->exists()) {
                if (! access()->can('accounting.manage')) {
                    throw new BusinessRule(t('smm.finance.errors.accountingLinked'));
                }
                OperationsService::delete($x->acc_transaction_id, t('smm.finance.expenseDeletedReason'));
            }
            Audit::log('smm.expense_delete', 'smm_expense', $x->id, [
                'category' => ['old' => $x->category, 'new' => null], 'amount' => ['old' => (string) $x->amount_uzs, 'new' => null],
                'date' => ['old' => $x->date->toDateString(), 'new' => null],
            ]);
            $x->delete();
        });
    }
}
