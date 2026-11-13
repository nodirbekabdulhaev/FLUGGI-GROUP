<?php

namespace App\Services\Smm;

use App\Models\Employee;
use App\Models\SmmIdea;
use App\Models\SmmIdeaHistory;
use App\Models\SmmVideoAccrual;
use App\Models\User;
use App\Services\Finance\Input;
use App\Support\Audit;
use App\Support\Format;
use App\Support\Outbox;
use App\Support\Settings;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Оплата за опубликованный ролик SMM (внутренний отдел маркетинга компании).
 *  - Ставки по ролям в настройках SMM: видеограф, мобилограф, монтажёр (0 — не начисляется);
 *    личная ставка сотрудника (employees.smm_video_rate) заменяет ставку роли.
 *  - Видеограф идеи получает ставку «Видеограф» (или «Мобилограф», если у него только эта специальность),
 *    монтажёр идеи — ставку «Монтажёр». Если исполнитель — сам ответственный SMM-специалист, ставка
 *    начисляется только при включённой настройке payAssignee (по умолчанию нет: работа входит в его оклад).
 *  - Начисление создаётся, когда идея становится «Опубликовано» (вручную или первой успешной автопубликацией),
 *    месяц — по дате публикации (Ташкент), сумма — по ставке на этот момент (хранится в записи).
 *  - Ролик снят с публикации, удалён, сменился видеограф/монтажёр или месяц публикации — прежнее начисление
 *    сторнируется (с причиной), новому исполнителю начисляется заново. Всё — в истории идеи и в аудите.
 *  - В зарплату месяца попадает сумма действующих начислений (payroll_entries.smm_video_pay).
 */
final class SmmVideoPay
{
    public const ROLES = ['VIDEOGRAPHER', 'MOBILOGRAPHER', 'EDITOR'];

    /** Ключ ставки в настройках smm по роли. */
    private const SETTING = ['VIDEOGRAPHER' => 'rateVideographer', 'MOBILOGRAPHER' => 'rateMobilographer', 'EDITOR' => 'rateEditor'];

    /** Причины сторно (переводы — smm.finance.reverse.*). */
    public const REASONS = ['unpublished', 'deleted', 'videographer_changed', 'editor_changed', 'period_changed'];

    /** @return array{rates: array<string,string>, payAssignee: bool} */
    public static function settings(): array
    {
        $s = Settings::smm();
        $rates = [];
        foreach (self::SETTING as $role => $key) {
            $rates[$role] = (string) BigDecimal::of((string) (Input::money($s[$key] ?? '0') ?? '0'))->toScale(2);
        }

        return ['rates' => $rates, 'payAssignee' => (bool) ($s['payAssignee'] ?? false)];
    }

    /**
     * Ставки по ролям и «платить SMM-специалисту, если он сам снимает». Только с доступом к SMM «Финансы».
     *
     * @param  array<string,string>  $rates  роль → сумма
     */
    public static function saveSettings(array $rates, bool $payAssignee): void
    {
        SmmFinance::authorize();
        $old = self::settings();
        $new = [];
        foreach (self::ROLES as $role) {
            $new[$role] = (string) BigDecimal::of($rates[$role] ?? '0')->toScale(2);
        }
        DB::transaction(function () use ($old, $new, $payAssignee) {
            $value = Settings::smm();
            foreach (self::SETTING as $role => $key) {
                $value[$key] = $new[$role];
            }
            $value['payAssignee'] = $payAssignee;
            Settings::save('smm', $value);
            $changes = [];
            foreach (self::ROLES as $role) {
                if (! BigDecimal::of($old['rates'][$role])->isEqualTo($new[$role])) {
                    $changes['rate'.ucfirst(strtolower($role))] = ['old' => $old['rates'][$role], 'new' => $new[$role]];
                }
            }
            if ($old['payAssignee'] !== $payAssignee) {
                $changes['payAssignee'] = ['old' => $old['payAssignee'], 'new' => $payAssignee];
            }
            if ($changes) {
                Audit::log('smm.video_rates_update', 'settings', null, $changes);
            }
        });
    }

    /** Личная ставка за ролик (NULL — ставка роли из настроек). Право — как на ставки сотрудника (payroll.manage ALL). */
    public static function savePersonalRate(string $userId, ?string $rate): void
    {
        access()->authorize('payroll.manage', 'ALL');
        $user = User::with('employee')->findOrFail($userId);
        $new = $rate === null || $rate === '' ? null : (string) BigDecimal::of($rate)->toScale(2);
        $old = $user->employee?->smm_video_rate !== null ? (string) $user->employee->smm_video_rate : null;
        if ($old === $new || ($old !== null && $new !== null && BigDecimal::of($old)->isEqualTo($new))) {
            return;
        }
        DB::transaction(function () use ($userId, $old, $new) {
            Employee::updateOrCreate(['user_id' => $userId], ['smm_video_rate' => $new]);
            Audit::log('employee.smm_video_rate_update', 'user', $userId, ['smmVideoRate' => ['old' => $old, 'new' => $new]]);
        });
    }

    /** Ставка сотрудника за ролик в роли $role: личная, иначе — из настроек. */
    public static function rateFor(User $user, string $role): BigDecimal
    {
        $user->loadMissing('employee');
        $personal = $user->employee?->smm_video_rate;

        return BigDecimal::of($personal !== null ? (string) $personal : self::settings()['rates'][$role]);
    }

    /** Роль видеографа по специальностям: «Видеограф», иначе «Мобилограф» (обе — «Видеограф»). */
    public static function videographerRole(User $user): string
    {
        $user->loadMissing('employee');
        $list = $user->employee?->specialtyList() ?? [];

        return ! in_array('VIDEOGRAPHER', $list, true) && in_array('MOBILOGRAPHER', $list, true) ? 'MOBILOGRAPHER' : 'VIDEOGRAPHER';
    }

    private static function slot(string $role): string
    {
        return $role === 'EDITOR' ? 'editor' : 'videographer';
    }

    public static function periodOf(\DateTimeInterface $at): string
    {
        return CarbonImmutable::instance($at)->setTimezone(Format::tz())->format('Y-m');
    }

    /**
     * Привести начисления идеи в соответствие с её состоянием. Вызывать в транзакции изменения идеи —
     * после сохранения. Действующее начисление остаётся, пока ролик опубликован в том же месяце и исполнитель тот же
     * (смена ставок или настройки задним числом его не меняет); новое — если ставка больше 0.
     */
    public static function sync(SmmIdea $idea): void
    {
        $idea = SmmIdea::withTrashed()->findOrFail($idea->id);
        $active = SmmVideoAccrual::where('idea_id', $idea->id)->where('status', 'ACCRUED')->lockForUpdate()->get();
        $published = $idea->status === 'PUBLISHED' && $idea->published_at !== null && $idea->deleted_at === null;
        $period = $published ? self::periodOf($idea->published_at) : null;
        $people = ['videographer' => $idea->videographer_id, 'editor' => $idea->editor_id];

        foreach ($active as $row) {
            $slot = self::slot($row->role);
            $reason = match (true) {
                $idea->deleted_at !== null => 'deleted',
                ! $published => 'unpublished',
                $people[$slot] !== $row->user_id => $slot.'_changed',
                $row->period !== $period => 'period_changed',
                default => null,
            };
            if ($reason !== null) {
                self::reverse($idea, $row, $reason);
            }
        }
        if (! $published) {
            return;
        }
        $kept = SmmVideoAccrual::where('idea_id', $idea->id)->where('status', 'ACCRUED')->get();
        $payAssignee = self::settings()['payAssignee'];
        foreach ($people as $slot => $userId) {
            if (! $userId || $kept->contains(fn ($r) => self::slot($r->role) === $slot && $r->user_id === $userId)) {
                continue;
            }
            // Ответственный SMM-специалист сам снимает/монтирует — по умолчанию это часть его оклада
            if ($userId === $idea->assignee_id && ! $payAssignee) {
                continue;
            }
            $user = User::withTrashed()->with('employee')->find($userId);
            if (! $user) {
                continue;
            }
            $role = $slot === 'editor' ? 'EDITOR' : self::videographerRole($user);
            $amount = self::rateFor($user, $role);
            if ($amount->isLessThanOrEqualTo(0)) {
                continue;
            }
            self::accrue($idea, $user, $role, $amount, $period);
        }
    }

    private static function accrue(SmmIdea $idea, User $user, string $role, BigDecimal $amount, string $period): void
    {
        $actor = access(false)?->id();
        $row = SmmVideoAccrual::create([
            'idea_id' => $idea->id, 'user_id' => $user->id, 'role' => $role, 'amount_uzs' => (string) $amount->toScale(2),
            'period' => $period, 'status' => 'ACCRUED', 'active' => true, 'created_by_id' => $actor, 'created_at' => now(),
        ]);
        $changes = [
            'payee' => ['old' => null, 'new' => $user->full_name.' · '.t('smm.finance.roles.'.$role)],
            'amount' => ['old' => null, 'new' => (string) $amount->toScale(2)],
            'period' => ['old' => null, 'new' => $period],
        ];
        SmmIdeaHistory::create(['idea_id' => $idea->id, 'user_id' => $actor, 'action' => 'pay_accrued', 'changes' => $changes, 'created_at' => now()]);
        Audit::log('smm.video_accrued', 'smm_video_accrual', $row->id, $changes + ['idea' => ['old' => null, 'new' => $idea->code()]], $actor);
        Outbox::publish('smm.video_accrued', ['accrualId' => $row->id, 'ideaId' => $idea->id, 'userId' => $user->id, 'role' => $role,
            'amountUzs' => (string) $amount->toScale(2), 'period' => $period], $actor);
    }

    private static function reverse(SmmIdea $idea, SmmVideoAccrual $row, string $reason): void
    {
        $actor = access(false)?->id();
        $row->update(['status' => 'REVERSED', 'active' => null, 'reversed_at' => now(), 'reversed_by_id' => $actor, 'reverse_reason' => $reason]);
        $name = User::withTrashed()->whereKey($row->user_id)->value('full_name');
        $changes = [
            'payee' => ['old' => $name.' · '.t('smm.finance.roles.'.$row->role), 'new' => null],
            'amount' => ['old' => (string) $row->amount_uzs, 'new' => null],
            'reason' => ['old' => null, 'new' => t('smm.finance.reverse.'.$reason)],
        ];
        SmmIdeaHistory::create(['idea_id' => $idea->id, 'user_id' => $actor, 'action' => 'pay_reversed', 'changes' => $changes, 'created_at' => now()]);
        Audit::log('smm.video_reversed', 'smm_video_accrual', $row->id, $changes + ['status' => ['old' => 'ACCRUED', 'new' => 'REVERSED']], $actor);
        Outbox::publish('smm.video_reversed', ['accrualId' => $row->id, 'ideaId' => $idea->id, 'userId' => $row->user_id, 'role' => $row->role,
            'amountUzs' => (string) $row->amount_uzs, 'period' => $row->period, 'reason' => $reason], $actor);
    }

    /** Действующие начисления идеи (для карточки). */
    public static function forIdea(string $ideaId): Collection
    {
        return SmmVideoAccrual::with('user:id,full_name')->where('idea_id', $ideaId)->where('status', 'ACCRUED')->orderBy('created_at')->get();
    }

    /**
     * Действующие начисления месяца по сотрудникам: user_id → [count, sum, parts: [сумма → число роликов]].
     *
     * @param  list<string>|null  $userIds
     * @return array<string, array{count:int, sum:string, parts:array<string,int>}>
     */
    public static function byUser(string $period, ?array $userIds = null): array
    {
        $rows = SmmVideoAccrual::where('period', $period)->where('status', 'ACCRUED')
            ->when($userIds !== null, fn ($q) => $q->whereIn('user_id', $userIds ?: ['-']))
            ->groupBy('user_id', 'amount_uzs')->selectRaw('user_id, amount_uzs, COUNT(*) AS n')->get();
        $out = [];
        foreach ($rows as $r) {
            $o = $out[$r->user_id] ?? ['count' => 0, 'sum' => '0.00', 'parts' => []];
            $o['count'] += (int) $r->n;
            $o['sum'] = (string) BigDecimal::of($o['sum'])->plus(BigDecimal::of((string) $r->amount_uzs)->multipliedBy((int) $r->n))->toScale(2);
            $o['parts'][(string) $r->amount_uzs] = (int) $r->n;
            $out[$r->user_id] = $o;
        }

        return $out;
    }

    /** «2 × 50 000 + 1 × 70 000» для строки зарплаты и страницы «Финансы». */
    public static function partsText(array $parts): string
    {
        krsort($parts, SORT_NUMERIC);

        return collect($parts)->map(fn ($n, $amount) => $n.' × '.money((string) $amount))->implode(' + ');
    }
}
