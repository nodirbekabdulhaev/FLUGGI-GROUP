<?php

namespace App\Services\Finance;

use App\Domain\Finance;
use App\Exceptions\BusinessRule;
use App\Models\PartnerPayout;
use App\Models\Project;
use App\Models\User;
use App\Support\Audit;
use App\Support\Outbox;
use App\Support\Settings;
use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Доход партнёра (второго CEO): процент от чистой прибыли каждого проекта (по умолчанию 40 %,
 * при убытке — 0). Партнёр и CEO запрашивают выплату по проекту (не больше остатка), утверждает
 * или отклоняет её только CEO, который сам не партнёр. Утверждённые выплаты — «Выплачено».
 * Партнёр видит свою долю по всем проектам, даже если его роль не видит финансы проектов.
 */
final class PartnerService
{
    private const KEY = 'finance';

    public const DEFAULT_PCT = '40';

    /** @return array{partnerId:?string, partnerPct:string} */
    public static function settings(): array
    {
        $s = Settings::get(self::KEY);
        $id = $s['partnerId'] ?? null;
        $pct = $s['partnerPct'] ?? null;
        $pct = is_numeric($pct) && (float) $pct >= 0 && (float) $pct <= 100 ? (string) BigDecimal::of((string) $pct)->stripTrailingZeros() : self::DEFAULT_PCT;

        return ['partnerId' => is_string($id) && $id !== '' ? $id : null, 'partnerPct' => $pct];
    }

    public static function partner(): ?User
    {
        $id = self::settings()['partnerId'];

        return $id ? User::find($id) : null;
    }

    public static function isPartner(?string $userId = null): bool
    {
        $userId ??= access(false)?->id();
        $partner = self::settings()['partnerId'];

        return $userId !== null && $partner !== null && $userId === $partner;
    }

    /** Страницу «Доход партнёра» видят партнёр и CEO. */
    public static function canView(): bool
    {
        $a = access(false);

        return $a !== null && ($a->roleCode === 'CEO' || self::isPartner($a->id()));
    }

    /** Пункт меню — тем же, кому доступна страница. */
    public static function navAllowed(): bool
    {
        return self::canView();
    }

    public static function authorizeView(): void
    {
        if (! self::canView()) {
            throw new HttpException(403);
        }
    }

    /** Менять партнёра и процент — только CEO с правом на финансы компании. */
    public static function canManageSettings(): bool
    {
        $a = access(false);

        return $a !== null && $a->roleCode === 'CEO' && $a->can('finance.company.read', 'ALL');
    }

    /** Утверждать выплаты — только CEO, который сам не партнёр («первый CEO»). */
    public static function canDecide(): bool
    {
        $a = access(false);

        return $a !== null && $a->roleCode === 'CEO' && ! self::isPartner($a->id());
    }

    /** Кандидаты в партнёры — активные сотрудники. */
    public static function candidates(): Collection
    {
        return User::with('role')->where('status', 'ACTIVE')->orderBy('full_name')->get(['id', 'full_name', 'role_id']);
    }

    public static function saveSettings(?string $partnerId, string $pct): array
    {
        if (! self::canManageSettings()) {
            throw new HttpException(403);
        }
        if ($partnerId !== null && ! User::whereKey($partnerId)->where('status', 'ACTIVE')->exists()) {
            throw new BusinessRule(t('partner.errors.partnerUser'), ['partner_id' => t('partner.errors.partnerUser')]);
        }
        $pct = (string) BigDecimal::of($pct)->toScale(2)->stripTrailingZeros();
        DB::transaction(function () use ($partnerId, $pct) {
            $before = self::settings();
            Settings::save(self::KEY, ['partnerId' => $partnerId, 'partnerPct' => $pct] + Settings::get(self::KEY));
            Audit::log('settings.finance', 'setting', null, [
                'partnerId' => ['old' => $before['partnerId'], 'new' => $partnerId],
                'partnerPct' => ['old' => $before['partnerPct'], 'new' => $pct],
            ]);
        });

        return self::settings();
    }

    /**
     * Утверждённые и ожидающие выплаты партнёру по проектам.
     *
     * @return array{approved: array<string,BigDecimal>, requested: array<string,BigDecimal>}
     */
    public static function amounts(array $projectIds, ?string $partnerId): array
    {
        $out = ['approved' => [], 'requested' => []];
        if (! $projectIds || ! $partnerId) {
            return $out;
        }
        $rows = DB::table('partner_payouts')->whereIn('project_id', $projectIds)->where('partner_id', $partnerId)
            ->whereIn('status', ['APPROVED', 'REQUESTED'])->groupBy('project_id', 'status')
            ->select('project_id', 'status', DB::raw('SUM(amount_uzs) AS s'))->get();
        foreach ($rows as $r) {
            $out[$r->status === 'APPROVED' ? 'approved' : 'requested'][$r->project_id] = BigDecimal::of((string) $r->s);
        }

        return $out;
    }

    /** Доля, выплачено, на утверждении и остаток по одному проекту при известной чистой прибыли. */
    public static function forProject(Project $p, string $netProfit): array
    {
        $s = self::settings();
        $amounts = self::amounts([$p->id], $s['partnerId']);

        return self::row($s, $netProfit, $amounts['approved'][$p->id] ?? BigDecimal::zero(), $amounts['requested'][$p->id] ?? BigDecimal::zero());
    }

    private static function row(array $s, string $net, BigDecimal $approved, BigDecimal $requested): array
    {
        $share = BigDecimal::of(Finance::partnerShare($net, $s['partnerPct']));
        $left = $share->minus($approved)->minus($requested);

        return [
            'configured' => $s['partnerId'] !== null,
            'pct' => $s['partnerPct'],
            'netProfitUzs' => $net,
            'shareUzs' => (string) $share->toScale(2),
            'paidUzs' => (string) $approved->toScale(2),
            'requestedUzs' => (string) $requested->toScale(2),
            'remainingUzs' => (string) ($left->isNegative() ? BigDecimal::zero() : $left)->toScale(2),
        ];
    }

    /** Доля партнёра по проекту — без проверки прав на финансы проекта (для страницы партнёра и карточки проекта). */
    public static function projectShare(string $projectId): array
    {
        $p = Project::findOrFail($projectId);

        return self::forProject($p, FinanceService::profitRows([$p])[$p->id]['netProfit']);
    }

    /**
     * Все проекты (отменённые — только если по ним были выплаты) с долей партнёра и итогами.
     *
     * @return array{rows: Collection, totals: array<string,string>}
     */
    public static function overview(): array
    {
        self::authorizeView();
        $s = self::settings();
        $withPayouts = $s['partnerId'] ? DB::table('partner_payouts')->where('partner_id', $s['partnerId'])->distinct()->pluck('project_id')->all() : [];
        $projects = Project::with('client:id,name')
            ->where(fn ($q) => $q->where('status', '<>', 'CANCELLED')->orWhereIn('id', $withPayouts ?: ['-']))
            ->orderByDesc('created_at')->get();
        $profit = FinanceService::profitRows($projects);
        $amounts = self::amounts($projects->pluck('id')->all(), $s['partnerId']);
        $totals = ['revenue' => BigDecimal::zero(), 'net' => BigDecimal::zero(), 'share' => BigDecimal::zero(), 'paid' => BigDecimal::zero(), 'requested' => BigDecimal::zero(), 'remaining' => BigDecimal::zero()];
        $rows = $projects->map(function (Project $p) use ($profit, $amounts, $s, &$totals) {
            $r = self::row($s, $profit[$p->id]['netProfit'], $amounts['approved'][$p->id] ?? BigDecimal::zero(), $amounts['requested'][$p->id] ?? BigDecimal::zero());
            $r['revenueUzs'] = (string) $profit[$p->id]['revenue']->toScale(2);
            foreach (['revenue' => 'revenueUzs', 'net' => 'netProfitUzs', 'share' => 'shareUzs', 'paid' => 'paidUzs', 'requested' => 'requestedUzs', 'remaining' => 'remainingUzs'] as $k => $f) {
                $totals[$k] = $totals[$k]->plus($r[$f]);
            }
            $p->setAttribute('partner', $r);

            return $p;
        });

        return ['rows' => $rows, 'totals' => array_map(fn (BigDecimal $v) => (string) $v->toScale(2), $totals)];
    }

    /** Заявки и решения по выплатам текущего партнёра, новые сверху. */
    public static function payouts(?string $status = null): Collection
    {
        self::authorizeView();
        $partner = self::settings()['partnerId'];
        if (! $partner) {
            return collect();
        }

        return PartnerPayout::with(['project:id,number,name', 'requestedBy:id,full_name', 'decidedBy:id,full_name'])
            ->where('partner_id', $partner)->when($status, fn ($q) => $q->where('status', $status))
            ->orderByDesc('created_at')->limit(200)->get();
    }

    /** Число заявок, ждущих решения CEO (для бейджа). */
    public static function pendingCount(): int
    {
        $partner = self::settings()['partnerId'];

        return $partner && self::canDecide() ? PartnerPayout::where('partner_id', $partner)->where('status', 'REQUESTED')->count() : 0;
    }

    /** Заявка на выплату партнёру по проекту: сумма не больше остатка (доля − выплачено − на утверждении). */
    public static function request(string $projectId, string $amount, ?string $comment): PartnerPayout
    {
        self::authorizeView();
        $partner = self::settings()['partnerId'];
        if (! $partner) {
            throw new BusinessRule(t('partner.errors.notConfigured'));
        }

        return DB::transaction(function () use ($projectId, $amount, $comment, $partner) {
            $p = Project::whereKey($projectId)->lockForUpdate()->firstOrFail();
            $left = BigDecimal::of(self::projectShare($p->id)['remainingUzs']);
            $sum = BigDecimal::of($amount)->toScale(2);
            if ($sum->isLessThanOrEqualTo(0)) {
                throw new BusinessRule(t('partner.errors.amount'), ['amount' => t('partner.errors.amount')]);
            }
            if ($sum->isGreaterThan($left)) {
                $msg = t('partner.errors.overRemaining', ['amount' => money((string) $left)]);
                throw new BusinessRule($msg, ['amount' => $msg]);
            }
            $payout = PartnerPayout::create([
                'project_id' => $p->id, 'partner_id' => $partner, 'amount_uzs' => (string) $sum, 'status' => 'REQUESTED',
                'comment' => $comment, 'requested_by' => access()->id(),
            ]);
            Audit::log('partner_payout.request', 'partner_payout', $payout->id, [
                'projectId' => ['old' => null, 'new' => $p->id], 'amountUzs' => ['old' => null, 'new' => (string) $sum], 'status' => ['old' => null, 'new' => 'REQUESTED'],
            ]);
            Outbox::publish('partner.payout_requested', [
                'payoutId' => $payout->id, 'projectId' => $p->id, 'partnerId' => $partner, 'amountUzs' => (string) $sum, 'requestedById' => access()->id(),
            ]);

            return $payout;
        });
    }

    /** Решение по заявке: утвердить (сумма — в «Выплачено») или отклонить с причиной. */
    public static function decide(string $id, bool $approve, ?string $reason): PartnerPayout
    {
        if (! self::canDecide()) {
            throw new HttpException(403);
        }

        return DB::transaction(function () use ($id, $approve, $reason) {
            $payout = PartnerPayout::whereKey($id)->lockForUpdate()->firstOrFail();
            // CEO, которому положена выплата, сам её не утверждает (даже если партнёра сменили)
            if ($payout->partner_id === access()->id()) {
                throw new HttpException(403);
            }
            if ($payout->status !== 'REQUESTED') {
                throw new BusinessRule(t('partner.errors.decided'));
            }
            if (! $approve && trim((string) $reason) === '') {
                throw new BusinessRule(t('partner.errors.reason'), ['reject_reason' => t('partner.errors.reason')]);
            }
            $to = $approve ? 'APPROVED' : 'REJECTED';
            $payout->update([
                'status' => $to, 'decided_by' => access()->id(), 'decided_at' => now(),
                'reject_reason' => $approve ? null : trim((string) $reason),
            ]);
            Audit::log($approve ? 'partner_payout.approve' : 'partner_payout.reject', 'partner_payout', $payout->id, [
                'status' => ['old' => 'REQUESTED', 'new' => $to], 'amountUzs' => ['old' => null, 'new' => (string) $payout->amount_uzs],
            ] + ($approve ? [] : ['rejectReason' => ['old' => null, 'new' => trim((string) $reason)]]));
            Outbox::publish('partner.payout_decided', [
                'payoutId' => $payout->id, 'projectId' => $payout->project_id, 'partnerId' => $payout->partner_id,
                'status' => $to, 'amountUzs' => (string) $payout->amount_uzs, 'reason' => $approve ? null : trim((string) $reason),
            ]);

            return $payout->fresh();
        });
    }
}
