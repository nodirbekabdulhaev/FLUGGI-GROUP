<?php

namespace App\Services\Automation;

use App\Domain\People;
use App\Domain\Tasks;
use App\Models\Contract;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\Meeting;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Todo;
use App\Services\Notifications\NotificationEvents;
use App\Services\Notifications\Notifier;
use App\Support\Format;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Умные напоминания (ТЗ §41). Каждое напоминание записывается в reminder_log с уникальным
 * ключом — повторный запуск cron (или параллельный) не отправит то же самое ещё раз.
 */
final class ReminderService
{
    private const DAY = 86400;

    /** true — ключ новый, напоминание нужно отправить. */
    private static function once(string $kind, string $key): bool
    {
        return DB::table('reminder_log')->insertOrIgnore([
            'id' => (string) Str::uuid(), 'kind' => $kind, 'dedupe_key' => mb_substr($kind.':'.$key, 0, 191),
            'created_at' => now()->format('Y-m-d H:i:s.v'),
        ]) === 1;
    }

    /** Отправить один раз по ключу (для событийных напоминаний). */
    public static function sendOnce(string $kind, string $key, array $to, string $type, callable|array $content, ?string $link): int
    {
        if (! self::once($kind, $key)) {
            return 0;
        }
        Notifier::notify($to, $type, $content, $link);

        return 1;
    }

    private static function ts(DateTimeInterface $d): string
    {
        return CarbonImmutable::instance($d)->utc()->format('Y-m-d H:i:s.v');
    }

    private static function meetingLink(Meeting $m): string
    {
        return $m->lead_id ? '/sales/leads/'.$m->lead_id : '/sales/deals/'.$m->deal_id;
    }

    /** @return array<string,int> */
    public static function run(?DateTimeInterface $now = null): array
    {
        $now = CarbonImmutable::instance($now ?? now())->utc();
        $today = Tasks::companyDate($now);
        $out = [];
        $add = function (string $k, int $n) use (&$out) {
            if ($n) {
                $out[$k] = ($out[$k] ?? 0) + $n;
            }
        };
        $subject = fn (Meeting $m) => $m->lead?->title ?? $m->deal?->title ?? '';

        // Встреча завтра — один раз по каждой встрече
        $tomorrow = Tasks::companyDayStart(Tasks::addDays($today, 1));
        $rows = Meeting::with(['lead', 'deal'])->whereIn('status', ['SCHEDULED', 'CONFIRMED'])
            ->where('starts_at', '>=', self::ts($tomorrow))->where('starts_at', '<', self::ts($tomorrow->addDay()))->get();
        foreach ($rows as $m) {
            $add('meeting.tomorrow', self::sendOnce('meeting.tomorrow', $m->id, [$m->manager_id], 'meeting.reminder',
                fn () => [t('automation.remind.meetingTomorrow', ['time' => People::tashkentTime($m->starts_at)]), $subject($m)], self::meetingLink($m)));
        }

        // Встреча через 30 минут
        $rows = Meeting::with(['lead', 'deal'])->whereIn('status', ['SCHEDULED', 'CONFIRMED'])
            ->where('starts_at', '>', self::ts($now))->where('starts_at', '<=', self::ts($now->addMinutes(30)))->get();
        foreach ($rows as $m) {
            $min = max(1, (int) round(($m->starts_at->getTimestamp() - $now->getTimestamp()) / 60));
            $add('meeting.soon', self::sendOnce('meeting.soon', $m->id, [$m->manager_id, $m->rop_id], 'meeting.reminder',
                fn () => [t('automation.remind.meetingSoon', ['min' => $min]), People::tashkentTime($m->starts_at).' · '.$subject($m)], self::meetingLink($m)));
        }

        // Клиенту не звонили 3 дня (лиды в работе) — не чаще раза в 3 дня
        $edge = self::ts($now->subDays(3));
        $leads = Lead::where('status', 'OPEN')
            ->where(fn ($q) => $q->where('last_contact_at', '<', $edge)->orWhere(fn ($w) => $w->whereNull('last_contact_at')->where('created_at', '<', $edge)))
            ->limit(500)->get(['id', 'title', 'owner_id']);
        $bucket = intdiv($now->getTimestamp(), 3 * self::DAY);
        foreach ($leads as $l) {
            $add('lead.no_contact', self::sendOnce('lead.no_contact', $l->id.':'.$bucket, [$l->owner_id], 'client.no_contact',
                fn () => [t('automation.remind.noContact'), $l->title], '/sales/leads/'.$l->id));
        }

        // Клиент не отвечает: по открытой сделке нет активности 7 дней — раз в неделю
        $weekAgo = $now->subDays(7);
        $deals = Deal::where('status', 'OPEN')->where('created_at', '<', self::ts($weekAgo))->limit(500)->get(['id', 'title', 'owner_id']);
        if ($deals->isNotEmpty()) {
            $last = DB::table('activities')->whereIn('deal_id', $deals->pluck('id'))->groupBy('deal_id')
                ->selectRaw('deal_id, MAX(created_at) AS at')->pluck('at', 'deal_id');
            $week = intdiv($now->getTimestamp(), 7 * self::DAY);
            foreach ($deals as $d) {
                $at = $last[$d->id] ?? null;
                if ($at && CarbonImmutable::parse($at, 'UTC')->gt($weekAgo)) {
                    continue;
                }
                $add('deal.silent', self::sendOnce('deal.silent', $d->id.':'.$week, [$d->owner_id], 'client.no_contact',
                    fn () => [t('automation.remind.dealSilent'), $d->title], '/sales/deals/'.$d->id));
            }
        }

        // КП отправлено 2 дня назад и без ответа
        $rows = Proposal::whereIn('status', ['SENT', 'VIEWED'])->where('sent_at', '<', self::ts($now->subDays(2)))->get(['id', 'number', 'title', 'deal_id', 'manager_id']);
        foreach ($rows as $p) {
            $add('proposal.waiting', self::sendOnce('proposal.waiting', $p->id, [$p->manager_id], 'proposal.reminder',
                fn () => [t('automation.remind.proposalWaiting'), Format::code('KP', $p->number).' '.$p->title], '/sales/deals/'.$p->deal_id));
        }

        // Договор не подписан 3 дня
        $rows = Contract::with(['deal' => fn ($q) => $q->withTrashed()])->whereIn('status', ['DRAFT', 'SENT', 'IN_APPROVAL'])->where('created_at', '<', self::ts($now->subDays(3)))->get();
        foreach ($rows as $c) {
            $add('contract.unsigned', self::sendOnce('contract.unsigned', $c->id, [$c->deal?->owner_id, Notifier::teamHead($c->deal?->team_id)], 'contract.reminder',
                fn () => [t('automation.remind.contractUnsigned'), (string) $c->deal?->title], '/sales/deals/'.$c->deal_id));
        }

        // Оплата просрочена (ожидаемая дата прошла)
        $rows = Payment::with(['deal' => fn ($q) => $q->withTrashed()])->where('status', 'PENDING')->where('due_date', '<', $today)->get();
        foreach ($rows as $p) {
            $amount = Format::number(BigDecimal::of((string) $p->amount)->toScale(0, RoundingMode::HalfUp), 0).' '.$p->currency;
            $add('payment.overdue', self::sendOnce('payment.overdue', $p->id, [$p->deal?->owner_id, Notifier::teamHead($p->deal?->team_id)], 'payment.overdue',
                fn () => [t('automation.remind.paymentOverdue'), $p->deal?->title.': '.$amount], '/sales/deals/'.$p->deal_id));
        }

        // Проект заканчивается через 3 дня
        $rows = Project::whereIn('status', ReportService::ACTIVE_PROJECT)->whereDate('deadline', Tasks::addDays($today, 3))->get();
        foreach ($rows as $p) {
            $add('project.ending', self::sendOnce('project.ending', $p->id, [$p->manager_id, $p->rop_id], 'project.ending',
                fn () => [t('automation.remind.projectEnding'), Format::code('P', $p->number).' '.$p->name], '/projects/'.$p->id));
        }

        // Просроченный проект — РОП и CEO, один раз
        $rows = Project::whereIn('status', ReportService::ACTIVE_PROJECT)->whereDate('deadline', '<', $today)->get();
        $ceos = $rows->isNotEmpty() ? Notifier::ceoIds() : [];
        foreach ($rows as $p) {
            $add('project.overdue', self::sendOnce('project.overdue', $p->id, [$p->rop_id, ...$ceos], 'project.overdue',
                fn () => [t('automation.remind.projectOverdue'), Format::code('P', $p->number).' '.$p->name], '/projects/'.$p->id));
        }

        // Личные дела: «сегодня срок» — утром (с 09:00) один раз; просрочено — один раз
        if (strcmp(People::tashkentTime($now), '09:00') >= 0) {
            $dayEnd = Tasks::companyDayStart(Tasks::addDays($today, 1));
            $todos = Todo::where('status', 'OPEN')->whereNotNull('due_at')->where('due_at', '<', self::ts($dayEnd))->limit(1000)->get(['id', 'title', 'owner_id', 'due_at']);
            foreach ($todos as $t) {
                $overdue = $t->due_at->lt($now);
                $kind = $overdue ? 'todo.overdue' : 'todo.today';
                $time = People::tashkentTime($t->due_at);
                $add($kind, self::sendOnce($kind, $t->id, [$t->owner_id], $overdue ? 'todo.overdue' : 'todo.due',
                    fn () => [t($overdue ? 'automation.remind.todoOverdue' : 'automation.remind.todoToday', ['title' => $t->title]), t('automation.remind.until', ['time' => $time])], '/todos'));
            }
        }

        if ($out) {
            Log::info('Reminders: '.json_encode($out));
        }

        return $out;
    }

    /** Публично для событийных уведомлений о плане. */
    public static function amount(mixed $v): string
    {
        return NotificationEvents::uzs($v);
    }
}
