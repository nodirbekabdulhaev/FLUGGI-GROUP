<?php

namespace App\Services\Sales;

use App\Exceptions\BusinessRule;
use App\Models\Commission;
use App\Models\CommissionRule;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Комиссии (ТЗ §32–34): свои видит каждый, РОП — отдела, CEO — все (Rule 10).
 * Статусы: начислена → утверждена → выплачена (только CEO, каждое действие — в аудите).
 */
final class CommissionsService
{
    public const STATUSES = ['ACCRUED', 'APPROVED', 'PAID', 'CANCELLED'];

    public const CALC_TYPES = ['PERCENT_OF_PAYMENT', 'PERCENT_OF_PROFIT', 'FIXED_PER_DEAL'];

    public const METRICS = ['avg_check_usd', 'avg_check_uzs', 'orders_count', 'revenue_uzs', 'revenue_usd'];

    public const OPS = ['>', '>=', '<', '<=', '='];

    public static function query(): Builder
    {
        return access()->restrict(
            Commission::query(),
            'commission.read',
            fn ($q, $uid) => $q->where('user_id', $uid),
            fn ($q, $teamIds, $uid) => $q->where('user_id', $uid)
                ->orWhereIn('deal_id', fn ($d) => $d->select('id')->from('deals')->whereIn('team_id', $teamIds ?: ['-'])),
        );
    }

    /**
     * @param  array{period?:?string, user_id?:?string, status?:?string}  $f
     * @return array{items: LengthAwarePaginator, totalUzs: string}
     */
    public static function list(array $f, int $perPage = 25): array
    {
        $base = fn () => self::query()
            ->when($f['period'] ?? null, fn ($q, $v) => $q->where('period', $v))
            ->when($f['user_id'] ?? null, fn ($q, $v) => $q->where('user_id', $v))
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v));
        $items = $base()->with(['user:id,full_name', 'payment:id,number', 'deal:id,number,title', 'rule:id,name', 'approvedBy:id,full_name'])
            ->orderByDesc('created_at')->paginate($perPage)->withQueryString();
        $total = (string) ($base()->where('status', '<>', 'CANCELLED')->sum('amount_uzs') ?: '0');

        return ['items' => $items, 'totalUzs' => $total];
    }

    /** Утверждение и выплата (ТЗ §32–33): начислена → утверждена → выплачена. */
    public static function transition(array $ids, string $to): int
    {
        $from = $to === 'APPROVED' ? 'ACCRUED' : 'APPROVED';
        $actor = access()->id();
        $ids = array_values(array_unique($ids));

        return DB::transaction(function () use ($ids, $from, $to, $actor) {
            $rows = Commission::whereIn('id', $ids)->lockForUpdate()->get();
            if ($rows->count() !== count($ids)) {
                abort(404);
            }
            if ($rows->contains(fn ($r) => $r->status !== $from)) {
                throw new BusinessRule(t($to === 'APPROVED' ? 'sales.errors.approveAccruedOnly' : 'sales.errors.payApprovedOnly'));
            }
            Commission::whereIn('id', $ids)->where('status', $from)->update($to === 'APPROVED'
                ? ['status' => $to, 'approved_by_id' => $actor, 'approved_at' => now(), 'updated_at' => now()]
                : ['status' => $to, 'paid_by_id' => $actor, 'paid_at' => now(), 'updated_at' => now()]);
            foreach ($rows as $r) {
                Audit::log($to === 'APPROVED' ? 'commission.approve' : 'commission.pay', 'commission', $r->id, [
                    'status' => ['old' => $from, 'new' => $to],
                    'amountUzs' => ['old' => null, 'new' => (string) $r->amount_uzs],
                ], $actor);
            }

            return $rows->count();
        });
    }

    public static function rules()
    {
        $rules = CommissionRule::orderBy('applies_to')->orderByDesc('priority')->get();
        $users = User::whereIn('id', $rules->pluck('user_id')->filter()->all())->pluck('full_name', 'id');
        foreach ($rules as $r) {
            $r->setAttribute('user_name', $r->user_id ? ($users[$r->user_id] ?? null) : null);
        }

        return $rules;
    }

    /**
     * @param  array{name:string, applies_to:string, user_id:?string, calc_type:string, value:string|float, conditions:?array, priority:int, is_active:bool}  $input
     */
    public static function saveRule(?string $id, array $input): CommissionRule
    {
        if ($input['calc_type'] !== 'FIXED_PER_DEAL' && (float) $input['value'] > 100) {
            throw new BusinessRule(t('sales.errors.percentOver100'), ['value' => t('sales.errors.percentOver100')]);
        }
        $actor = access()->id();

        return DB::transaction(function () use ($id, $input, $actor) {
            $before = $id ? CommissionRule::find($id) : null;
            if ($id && ! $before) {
                abort(404);
            }
            $data = [
                'name' => $input['name'],
                'applies_to' => $input['applies_to'],
                'user_id' => $input['user_id'] ?? null,
                'calc_type' => $input['calc_type'],
                'value' => (string) $input['value'],
                'conditions' => $input['conditions'] ?? null,
                'priority' => (int) $input['priority'],
                'is_active' => (bool) $input['is_active'],
            ];
            $old = $before ? ['value' => Numbers::rate($before->value), 'conditions' => $before->conditions, 'isActive' => $before->is_active] : null;
            $saved = $before ? tap($before)->update($data) : CommissionRule::create($data);
            $saved->refresh();
            Audit::log($before ? 'commission_rule.update' : 'commission_rule.create', 'commission_rule', $saved->id, [
                'value' => ['old' => $old['value'] ?? null, 'new' => Numbers::rate($saved->value)],
                'conditions' => ['old' => $old['conditions'] ?? null, 'new' => $saved->conditions],
                'isActive' => ['old' => $old['isActive'] ?? null, 'new' => $saved->is_active],
            ], $actor);

            return $saved;
        });
    }

    /** Условие правила для формы: плоский список «все / любое» (DSL ТЗ §34). */
    public static function readConditions(?array $c): array
    {
        if (! $c) {
            return ['mode' => 'all', 'leaves' => []];
        }
        if (isset($c['metric'])) {
            return ['mode' => 'all', 'leaves' => [$c]];
        }
        $mode = isset($c['any']) ? 'any' : 'all';
        $leaves = array_values(array_filter($c[$mode] ?? [], fn ($x) => is_array($x) && isset($x['metric'])));

        return ['mode' => $mode, 'leaves' => $leaves];
    }

    /** Понятная запись условий: «Средний чек за месяц, USD > 3 000 или …». */
    public static function describe(?array $c): string
    {
        ['mode' => $mode, 'leaves' => $leaves] = self::readConditions($c);
        if (! $leaves) {
            return t('commissionRules.noConditions');
        }

        return implode(' '.t($mode === 'any' ? 'commissionRules.or' : 'commissionRules.and').' ', array_map(
            fn ($l) => t('commissionRules.metric.'.$l['metric']).' '.$l['op'].' '.\App\Support\Format::number((string) $l['value']),
            $leaves,
        ));
    }
}
