<?php

namespace App\Services\Todos;

use App\Auth\Access;
use App\Domain\People;
use App\Domain\Recurrence;
use App\Domain\Tasks;
use App\Models\RecurringTodo;
use App\Services\Notifications\Notifier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Регулярные дела (налоговый календарь и т.п.): за N дней до срока создаётся дело
 * исполнителю, уведомление — в CRM и Telegram.
 */
final class RecurringTodoService
{
    public const FREQUENCIES = ['MONTHLY', 'QUARTERLY', 'YEARLY'];

    /** Срок дела по правилу — конец рабочего дня по Ташкенту. */
    public const DUE_TIME = '18:00';

    /**
     * Налоговый календарь резидента IT-Park (по списку CEO). Названия — шаблоны:
     * {прошлый_месяц}, {прошлый_квартал}, {прошлый_год}, {год} подставляются от срока.
     */
    public const TAX_CALENDAR = [
        ['title' => 'Налог с оборота за {прошлый_месяц}', 'kind' => 'PAYMENT', 'frequency' => 'MONTHLY', 'dayOfMonth' => 4],
        ['title' => 'Налог на доходы работников (НДФЛ) за {прошлый_месяц}', 'kind' => 'PAYMENT', 'frequency' => 'MONTHLY', 'dayOfMonth' => 4],
        ['title' => 'Отчёт IT-Park об обороте за {прошлый_месяц}', 'kind' => 'REPORT', 'frequency' => 'MONTHLY', 'dayOfMonth' => 4],
        ['title' => 'ИНПС — посчитать за {прошлый_месяц}', 'kind' => 'PAYMENT', 'frequency' => 'MONTHLY', 'dayOfMonth' => 10],
        ['title' => 'Квартальный отчёт IT-Park за {прошлый_квартал}', 'kind' => 'REPORT', 'frequency' => 'QUARTERLY', 'dayOfMonth' => 4, 'month' => 1],
        ['title' => 'Статотчёт за {прошлый_квартал}', 'kind' => 'REPORT', 'frequency' => 'QUARTERLY', 'dayOfMonth' => 4, 'month' => 1],
        ['title' => 'Баланс (форма №1) и отчёт о финансовых результатах (форма №2) за {год}', 'description' => 'Скачать «Пакет для бухгалтера» в разделе «Финансы» и отправить бухгалтеру', 'kind' => 'REPORT', 'frequency' => 'YEARLY', 'dayOfMonth' => 1, 'month' => 12],
        ['title' => 'Годовой статотчёт за {прошлый_год}', 'kind' => 'REPORT', 'frequency' => 'YEARLY', 'dayOfMonth' => 1, 'month' => 2],
    ];

    private static function rule(RecurringTodo $r): array
    {
        return ['frequency' => $r->frequency, 'dayOfMonth' => (int) $r->day_of_month, 'month' => $r->month];
    }

    public static function nextDue(RecurringTodo $r, ?string $today = null): string
    {
        return Recurrence::nextDue(self::rule($r), $today ?? Tasks::companyDate(now()));
    }

    private static function scope(Access $a): Builder
    {
        return RecurringTodo::query()->with('owner:id,full_name')
            ->where(fn ($q) => $q->where('owner_id', $a->id())->orWhere('created_by_id', $a->id()));
    }

    public static function dto(RecurringTodo $r): array
    {
        $due = self::nextDue($r);

        return [
            'id' => $r->id, 'title' => $r->title, 'description' => $r->description, 'kind' => $r->kind,
            'priority' => $r->priority, 'owner' => ['id' => $r->owner_id, 'name' => $r->owner?->full_name],
            'frequency' => $r->frequency, 'dayOfMonth' => (int) $r->day_of_month, 'month' => $r->month,
            'remindDaysBefore' => (int) $r->remind_days_before, 'isActive' => (bool) $r->is_active,
            'nextDue' => $due, 'nextTitle' => Recurrence::fillPeriod($r->title, $due),
        ];
    }

    public static function list(Access $a): array
    {
        $rows = self::scope($a)->orderBy('frequency')->orderBy('day_of_month')->orderBy('created_at')->get()
            ->map(fn ($r) => self::dto($r))->all();
        usort($rows, fn ($x, $y) => strcmp($x['nextDue'], $y['nextDue']) ?: strcmp($x['title'], $y['title']));

        return $rows;
    }

    private static function data(array $input): array
    {
        return [
            'title' => $input['title'],
            'description' => $input['description'] ?? null,
            'kind' => $input['kind'] ?? 'REPORT',
            'priority' => $input['priority'] ?? 'HIGH',
            'frequency' => $input['frequency'],
            'day_of_month' => (int) $input['day_of_month'],
            'month' => $input['frequency'] === 'MONTHLY' ? null : (int) ($input['month'] ?? 1),
            'remind_days_before' => (int) ($input['remind_days_before'] ?? 3),
            'is_active' => (bool) ($input['is_active'] ?? true),
        ];
    }

    public static function create(Access $a, array $input): RecurringTodo
    {
        $ownerId = ($input['owner_id'] ?? null) ?: $a->id();
        // Регулярные дела другим сотрудникам ставит только CEO; остальные — себе
        if ($ownerId !== $a->id() && $a->roleCode !== 'CEO') {
            throw new HttpException(403, t('todos.errors.recurringOwn'));
        }
        TodoService::assertAssignable($a, $ownerId);

        return DB::transaction(fn () => RecurringTodo::create(self::data($input) + ['owner_id' => $ownerId, 'created_by_id' => $a->id()]));
    }

    public static function update(Access $a, string $id, array $input): RecurringTodo
    {
        $before = self::scope($a)->whereKey($id)->firstOrFail();
        $ownerId = ($input['owner_id'] ?? null) ?: $before->owner_id;
        if ($ownerId !== $before->owner_id) {
            TodoService::assertAssignable($a, $ownerId);
        }
        DB::transaction(fn () => $before->update(self::data($input) + ['owner_id' => $ownerId]));

        return $before->fresh('owner');
    }

    public static function remove(Access $a, string $id): void
    {
        $r = self::scope($a)->whereKey($id)->firstOrFail();
        DB::transaction(function () use ($r) {
            $r->update(['is_active' => false]);
            $r->delete();
        });
    }

    /** Налоговый календарь IT-Park одним нажатием; уже добавленные пункты не дублируются. */
    public static function addTaxCalendar(Access $a): void
    {
        if ($a->roleCode !== 'CEO') {
            throw new HttpException(403, t('todos.errors.taxCalendarCeo'));
        }
        $have = RecurringTodo::where('owner_id', $a->id())->pluck('title')->flip();
        DB::transaction(function () use ($a, $have) {
            foreach (self::TAX_CALENDAR as $item) {
                if ($have->has($item['title'])) {
                    continue;
                }
                RecurringTodo::create([
                    'title' => $item['title'], 'description' => $item['description'] ?? null,
                    'kind' => $item['kind'] ?? 'REPORT', 'priority' => $item['priority'] ?? 'HIGH',
                    'frequency' => $item['frequency'], 'day_of_month' => $item['dayOfMonth'],
                    'month' => $item['frequency'] === 'MONTHLY' ? null : ($item['month'] ?? 1),
                    'remind_days_before' => $item['remindDaysBefore'] ?? 3,
                    'owner_id' => $a->id(), 'created_by_id' => $a->id(),
                ]);
            }
        });
    }

    /** Задача планировщика: создать дела, срок которых наступает в ближайшие N дней. */
    public static function generate(?\DateTimeInterface $now = null): array
    {
        $now ??= now();
        $today = Tasks::companyDate($now);
        $rules = RecurringTodo::where('is_active', true)
            ->whereHas('owner', fn ($q) => $q->where('status', 'ACTIVE'))->get();
        $created = 0;
        foreach ($rules as $r) {
            $due = self::nextDue($r, $today);
            if (strcmp(Tasks::addDays($today, (int) $r->remind_days_before), $due) < 0) {
                continue;
            }
            $title = Recurrence::fillPeriod($r->title, $due);
            $stamp = now()->format('Y-m-d H:i:s.v');
            // Уникальный ключ (recurring_id, recurring_due) — повторный запуск не дублирует
            $inserted = DB::table('todos')->insertOrIgnore([
                'id' => (string) Str::uuid(), 'title' => $title, 'description' => $r->description,
                'kind' => $r->kind, 'priority' => $r->priority,
                'due_at' => People::atTashkent($due, self::DUE_TIME)->utc()->format('Y-m-d H:i:s.v'),
                'owner_id' => $r->owner_id, 'creator_id' => $r->created_by_id,
                'recurring_id' => $r->id, 'recurring_due' => $due,
                'created_at' => $stamp, 'updated_at' => $stamp,
            ]);
            if (! $inserted) {
                continue;
            }
            $created++;
            $label = implode('.', array_reverse(explode('-', $due)));
            Notifier::notify([$r->owner_id], 'todo.recurring', fn () => ['📅 '.$title, t('todos.notify.recurringBody', ['date' => $label])], '/todos');
        }

        return ['created' => $created];
    }
}
