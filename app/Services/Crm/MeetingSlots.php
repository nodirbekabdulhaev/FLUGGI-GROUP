<?php

namespace App\Services\Crm;

use App\Exceptions\BusinessRule;
use App\Models\Meeting;
use App\Models\User;
use App\Support\Format;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Слоты встреч: у каждого участника встреч своя сетка (рабочие дни, часы по Ташкенту, длина слота).
 * Слот занят, если у сотрудника есть пересекающаяся по времени встреча, где он участник,
 * в статусе «Запланирована», «Подтверждена», «Перенесена» или «Проведена» (отменённые
 * и несостоявшиеся место не занимают).
 */
final class MeetingSlots
{
    public const LENGTHS = [15, 30, 45, 60, 90];

    public const BUSY = ['SCHEDULED', 'CONFIRMED', 'RESCHEDULED', 'DONE'];

    /** Пн–Сб, 09:00–19:00, слот 60 минут. */
    public const DEFAULTS = ['days' => [1, 2, 3, 4, 5, 6], 'from' => '09:00', 'to' => '19:00', 'slot' => 60];

    /** Самая длинная встреча (минут) — для поиска встреч, начавшихся раньше интервала. */
    private const MAX_DURATION = 600;

    /** Настройки сотрудника с подстановкой значений по умолчанию. */
    public static function settings(?array $raw): array
    {
        $raw ??= [];
        $days = array_values(array_unique(array_filter(array_map('intval', (array) ($raw['days'] ?? self::DEFAULTS['days'])), fn ($d) => $d >= 1 && $d <= 7)));
        sort($days);
        $time = fn ($v, $d) => is_string($v) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v) ? $v : $d;
        $slot = (int) ($raw['slot'] ?? self::DEFAULTS['slot']);

        return [
            'days' => array_key_exists('days', $raw) ? $days : self::DEFAULTS['days'],
            'from' => $time($raw['from'] ?? null, self::DEFAULTS['from']),
            'to' => $time($raw['to'] ?? null, self::DEFAULTS['to']),
            'slot' => in_array($slot, self::LENGTHS, true) ? $slot : self::DEFAULTS['slot'],
        ];
    }

    public static function forUser(User $u): array
    {
        $raw = $u->meeting_slots;

        return self::settings(is_string($raw) ? json_decode($raw, true) : $raw);
    }

    /** Сотрудники, которые участвуют во встречах (активные). */
    public static function participants(): Collection
    {
        return User::where('status', 'ACTIVE')->where('meeting_participant', true)->orderBy('full_name')->get();
    }

    /**
     * Встречи сотрудников, пересекающиеся с [from, to) (UTC): строки user_id, id, starts_at, duration_min.
     *
     * @param  list<string>  $userIds
     */
    public static function busy(array $userIds, CarbonImmutable $from, CarbonImmutable $to, ?string $excludeId = null): Collection
    {
        if (! $userIds) {
            return collect();
        }
        $f = $from->utc()->format('Y-m-d H:i:s.v');

        return DB::table('meeting_participants as p')
            ->join('meetings as m', 'm.id', '=', 'p.meeting_id')
            ->whereIn('p.user_id', $userIds)
            ->whereIn('m.status', self::BUSY)
            ->when($excludeId, fn ($q) => $q->where('m.id', '<>', $excludeId))
            ->where('m.starts_at', '<', $to->utc()->format('Y-m-d H:i:s.v'))
            ->where('m.starts_at', '>', $from->utc()->subMinutes(self::MAX_DURATION)->format('Y-m-d H:i:s.v'))
            ->whereRaw('DATE_ADD(m.starts_at, INTERVAL m.duration_min MINUTE) > ?', [$f])
            ->orderBy('m.starts_at')
            ->get(['p.user_id', 'm.id', 'm.starts_at', 'm.duration_min']);
    }

    /**
     * Проверка перед сохранением встречи: никто из участников не занят в это время.
     * Строки участников блокируются до конца транзакции — две одновременные записи на одно
     * время к одному сотруднику не пройдут обе. Вызывать внутри DB::transaction().
     *
     * @param  list<string>  $userIds
     */
    public static function assertFree(array $userIds, CarbonImmutable $startsAt, int $minutes, ?string $excludeId = null): void
    {
        $ids = array_values(array_unique($userIds));
        sort($ids);
        DB::table('users')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->pluck('id');
        $row = self::busy($ids, $startsAt, $startsAt->addMinutes($minutes), $excludeId)->first();
        if (! $row) {
            return;
        }
        $name = User::withTrashed()->whereKey($row->user_id)->value('full_name') ?? '';
        $start = CarbonImmutable::parse($row->starts_at, 'UTC');
        $msg = t('meetings.errors.busy', [
            'name' => $name,
            'time' => Format::dateTime($start).'–'.Format::local($start->addMinutes((int) $row->duration_min))->format('H:i'),
        ]);
        throw new BusinessRule($msg, ['starts_at' => $msg]);
    }

    /**
     * Сетка слотов на день (Ташкент) для сотрудников. Для каждого слота: free | busy | past,
     * занятые — какой встречей (время, участники; лид/сделка — только если встреча видна смотрящему),
     * свободные — кто из остальных выбранных сотрудников в это время занят (conflicts).
     *
     * @param  list<string>  $userIds
     * @return list<array<string,mixed>>
     */
    public static function grid(string $date, array $userIds, ?string $excludeId = null, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $tz = Format::tz();
        $day = CarbonImmutable::parse($date, $tz)->startOfDay();
        $users = User::whereIn('id', $userIds)->where('status', 'ACTIVE')->get()->keyBy('id');
        $order = array_values(array_filter(array_unique($userIds), fn ($id) => $users->has($id)));
        $busy = self::busy($order, $day->subDay(), $day->addDays(2), $excludeId);
        $intervals = [];
        foreach ($busy as $r) {
            $s = CarbonImmutable::parse($r->starts_at, 'UTC');
            $intervals[$r->user_id][] = [$s->getTimestamp(), $s->addMinutes((int) $r->duration_min)->getTimestamp(), $r->id];
        }
        $details = self::details($busy->pluck('id')->unique()->values()->all());

        $out = [];
        foreach ($order as $uid) {
            $u = $users[$uid];
            $s = self::forUser($u);
            $row = ['id' => $uid, 'name' => $u->full_name, 'settings' => $s, 'dayOff' => ! in_array($day->isoWeekday(), $s['days'], true), 'slots' => []];
            if (! $row['dayOff']) {
                $cursor = $day->setTimeFromTimeString($s['from']);
                $end = $day->setTimeFromTimeString($s['to']);
                while ($cursor->addMinutes($s['slot'])->lte($end)) {
                    $slotEnd = $cursor->addMinutes($s['slot']);
                    [$a, $b] = [$cursor->getTimestamp(), $slotEnd->getTimestamp()];
                    $ids = self::overlapping($intervals[$uid] ?? [], $a, $b);
                    $conflicts = [];
                    foreach ($order as $other) {
                        if ($other !== $uid && self::overlapping($intervals[$other] ?? [], $a, $b)) {
                            $conflicts[] = $users[$other]->full_name;
                        }
                    }
                    $row['slots'][] = [
                        'time' => $cursor->format('H:i'),
                        'end' => $slotEnd->format('H:i'),
                        'value' => $cursor->format('Y-m-d\TH:i'),
                        'minutes' => $s['slot'],
                        'state' => $ids ? 'busy' : ($cursor->lt($now) ? 'past' : 'free'),
                        'meetings' => array_values(array_map(fn ($id) => $details[$id], $ids)),
                        'conflicts' => $conflicts,
                    ];
                    $cursor = $slotEnd;
                }
            }
            $out[] = $row;
        }

        return $out;
    }

    /** @return list<string> id встреч, пересекающихся с [a, b) */
    private static function overlapping(array $intervals, int $a, int $b): array
    {
        $ids = [];
        foreach ($intervals as [$s, $e, $id]) {
            if ($s < $b && $e > $a) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    /** Что показать о занятом слоте: время и участники — всем; лид/сделка и ссылка — если встреча видна. */
    private static function details(array $ids): array
    {
        if (! $ids) {
            return [];
        }
        $visible = CrmAccess::meetings()->whereIn('meetings.id', $ids)->pluck('meetings.id')->flip();
        $names = DB::table('meeting_participants as p')->join('users as u', 'u.id', '=', 'p.user_id')
            ->whereIn('p.meeting_id', $ids)->orderBy('u.full_name')->get(['p.meeting_id', 'u.full_name'])
            ->groupBy('meeting_id')->map(fn ($g) => $g->pluck('full_name')->all());
        $out = [];
        $meetings = Meeting::with(['lead', 'deal', 'client', 'manager'])->whereIn('id', $ids)->get();
        foreach ($meetings as $m) {
            $start = Format::local($m->starts_at);
            $see = $visible->has($m->id);
            $subject = $m->client?->name ?? $m->lead?->company_name ?? $m->lead?->contact_name ?? null;
            $title = $m->lead ? Format::code('L', $m->lead->number).' '.$m->lead->title : ($m->deal ? Format::code('D', $m->deal->number).' '.$m->deal->title : null);
            $out[$m->id] = [
                'id' => $m->id,
                'time' => $start->format('H:i').'–'.$start->addMinutes($m->duration_min)->format('H:i'),
                'participants' => $names[$m->id] ?? [],
                'responsible' => $m->manager?->full_name,
                'status' => $m->status,
                'visible' => $see,
                'subject' => $see ? trim(($subject ? $subject.' · ' : '').($title ?? '')) : null,
                'link' => $see ? ($m->lead_id ? '/sales/leads/'.$m->lead_id : '/sales/deals/'.$m->deal_id) : null,
            ];
        }

        return $out;
    }
}
