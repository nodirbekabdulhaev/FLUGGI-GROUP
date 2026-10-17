<?php

namespace App\Services\Crm;

use App\Exceptions\BusinessRule;
use App\Models\Lead;
use App\Models\Meeting;
use App\Models\Team;
use App\Models\User;
use App\Support\Audit;
use App\Support\Format;
use App\Support\Outbox;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Встречи по лиду или сделке. У встречи несколько участников (сотрудники с отметкой
 * «Участвует во встречах»); один из них — ответственный за результат (manager_id): только он
 * (или руководитель с правом на встречи отдела/всех) пишет результат и отмечает «Проведена» /
 * «Не состоялась». РОП — руководитель отдела лида/сделки. Время участников не пересекается:
 * занятого сотрудника на встречу не поставить. Назначение встречи по лиду переводит лид
 * на этап «Назначена встреча», проведение — «Встреча проведена».
 */
final class MeetingService
{
    public const TRACKED = ['starts_at', 'duration_min', 'type', 'link', 'status', 'comment', 'manager_id'];

    /** Встреча в области видимости (чужая — 404). */
    public static function find(string $id, string $code = 'meeting.read'): Meeting
    {
        return CrmAccess::meetings($code)->whereKey($id)->firstOrFail();
    }

    /** Изменить, перенести, отменить: ответственный, создатель, ведущий лид/сделку; РОП — встречи отдела; CEO — все. */
    public static function canEdit(Meeting $m): bool
    {
        $a = access();
        if (! $a->can('meeting.update')) {
            return false;
        }
        $uid = $a->id();

        return match ($a->scope('meeting.update')) {
            'ALL' => true,
            'TEAM' => in_array($m->team_id, $a->teamIds(), true) || in_array($uid, [$m->manager_id, $m->rop_id, $m->created_by_id], true),
            default => in_array($uid, [$m->manager_id, $m->created_by_id], true) || self::ownsRecord($m, $uid),
        };
    }

    /** Результат, «Проведена», «Не состоялась»: ответственный; РОП — по встречам отдела; CEO — любые. */
    public static function canWriteResult(Meeting $m): bool
    {
        $a = access();
        if (! $a->can('meeting.update')) {
            return false;
        }

        return match ($a->scope('meeting.update')) {
            'ALL' => true,
            'TEAM' => $m->manager_id === $a->id() || $m->rop_id === $a->id() || in_array($m->team_id, $a->teamIds(), true),
            default => $m->manager_id === $a->id(),
        };
    }

    private static function ownsRecord(Meeting $m, string $uid): bool
    {
        return ($m->lead_id && $m->lead?->owner_id === $uid) || ($m->deal_id && $m->deal?->owner_id === $uid);
    }

    /**
     * Участники встречи: выбранные сотрудники с отметкой «Участвует во встречах»
     * (у существующей встречи — также её нынешние участники и ответственный).
     * Не выбраны: новая встреча — ведущий лид/сделку (как раньше), существующая — без изменений.
     *
     * @return list<string>
     */
    private static function participants(?array $ids, string $fallbackId, ?Meeting $existing = null): array
    {
        $ids = array_values(array_unique(array_filter(array_map('strval', $ids ?? []))));
        if (! $ids) {
            return $existing ? $existing->participantIds() : [$fallbackId];
        }
        $keep = $existing ? array_merge($existing->participantIds(), [$existing->manager_id]) : [];
        $allowed = User::whereIn('id', $ids)->where('status', 'ACTIVE')
            ->where(fn ($q) => $q->where('meeting_participant', true)->orWhereIn('id', $keep ?: ['-']))
            ->pluck('id')->all();
        $bad = array_diff($ids, $allowed);
        if ($bad) {
            throw new BusinessRule(t('meetings.errors.participant'), ['participant_ids' => t('meetings.errors.participant')]);
        }

        return $ids;
    }

    /** Ответственный — из участников; не указан: прежний (если остался), создатель (если участник) или первый выбранный. */
    private static function responsible(?string $managerId, array $participants, ?Meeting $existing = null): string
    {
        if ($managerId) {
            if (! in_array($managerId, $participants, true)) {
                throw new BusinessRule(t('meetings.errors.responsible'), ['manager_id' => t('meetings.errors.responsible')]);
            }

            return $managerId;
        }
        if ($existing && in_array($existing->manager_id, $participants, true)) {
            return $existing->manager_id;
        }

        return in_array(access()->id(), $participants, true) ? access()->id() : $participants[0];
    }

    private static function syncParticipants(string $meetingId, array $ids): void
    {
        DB::table('meeting_participants')->where('meeting_id', $meetingId)->whereNotIn('user_id', $ids)->delete();
        $have = DB::table('meeting_participants')->where('meeting_id', $meetingId)->pluck('user_id')->all();
        $now = now()->format('Y-m-d H:i:s.v');
        $rows = array_map(fn ($uid) => ['id' => (string) Str::uuid(), 'meeting_id' => $meetingId, 'user_id' => $uid, 'created_at' => $now], array_values(array_diff($ids, $have)));
        if ($rows) {
            DB::table('meeting_participants')->insert($rows);
        }
    }

    private static function names(array $ids): string
    {
        return User::withTrashed()->whereIn('id', $ids)->orderBy('full_name')->pluck('full_name')->implode(', ');
    }

    public function create(array $input): Meeting
    {
        $access = access();
        $lead = ! empty($input['lead_id']) ? CrmAccess::lead($input['lead_id']) : null;
        $deal = ! empty($input['deal_id']) ? CrmAccess::deal($input['deal_id']) : null;
        if ((bool) $lead === (bool) $deal) {
            throw new BusinessRule(t('meetings.errors.target'), ['lead_id' => t('meetings.errors.target')]);
        }
        $record = $lead ?? $deal;
        if ($record->status !== 'OPEN') {
            throw new BusinessRule(t('meetings.errors.closedRecord'));
        }
        $team = $record->team_id ? Team::find($record->team_id) : null;
        $participants = self::participants($input['participant_ids'] ?? null, $record->owner_id);
        $managerId = self::responsible($input['manager_id'] ?? null, $participants);
        $duration = (int) ($input['duration_min'] ?? 60);

        return DB::transaction(function () use ($input, $access, $lead, $deal, $team, $record, $participants, $managerId, $duration) {
            MeetingSlots::assertFree($participants, self::utc($input['starts_at']), $duration);
            $m = Meeting::create([
                'lead_id' => $lead?->id,
                'deal_id' => $deal?->id,
                'client_id' => $deal?->client_id ?? $lead?->client_id,
                'manager_id' => $managerId,
                'rop_id' => $team?->head_id,
                'team_id' => $record->team_id,
                'starts_at' => $input['starts_at'],
                'duration_min' => $duration,
                'type' => $input['type'],
                'link' => $input['link'] ?? null,
                'comment' => $input['comment'] ?? null,
                'created_by_id' => $access->id(),
            ]);
            self::syncParticipants($m->id, $participants);
            $m->refresh();
            $startsAt = self::iso($m->starts_at);
            Activities::log('meeting.created', ['lead_id' => $lead?->id, 'deal_id' => $deal?->id, 'meeting_id' => $m->id], ['startsAt' => $startsAt, 'type' => $m->type]);
            if ($lead) {
                LeadService::advanceTo($lead->id, 'MEETING_SCHEDULED', $access->id());
            }
            Audit::log('meeting.create', 'meeting', $m->id, [
                'starts_at' => ['old' => null, 'new' => $startsAt],
                'participants' => ['old' => null, 'new' => self::names($participants)],
                'responsible' => ['old' => null, 'new' => self::names([$managerId])],
            ]);
            Outbox::publish('meeting.created', ['meetingId' => $m->id, 'managerId' => $m->manager_id, 'ropId' => $m->rop_id, 'startsAt' => $startsAt, 'participantIds' => $participants]);

            return $m;
        });
    }

    /** Изменение и перенос: новая дата без явного статуса → «Перенесена». Участники и ответственный — по желанию. */
    public function update(Meeting $before, array $input): Meeting
    {
        if (! self::canEdit($before)) {
            abort(403);
        }
        if ($before->status === 'DONE') {
            throw new BusinessRule(t('meetings.errors.doneImmutable'));
        }
        $data = array_intersect_key($input, array_flip(['starts_at', 'duration_min', 'type', 'link', 'comment', 'status']));
        $moved = ! empty($data['starts_at'])
            && self::utc($data['starts_at'])->getTimestampMs() !== $before->starts_at->getTimestampMs();
        if (empty($data['status']) && $moved) {
            $data['status'] = 'RESCHEDULED';
        }
        if (array_key_exists('status', $data) && $data['status'] === null) {
            unset($data['status']);
        }
        if (($data['status'] ?? null) === 'NO_SHOW' && $before->status !== 'NO_SHOW' && ! self::canWriteResult($before)) {
            abort(403, t('meetings.errors.responsibleOnly'));
        }
        $oldParticipants = $before->participantIds();
        $participants = array_key_exists('participant_ids', $input) && $input['participant_ids']
            ? self::participants($input['participant_ids'], $before->manager_id, $before) : $oldParticipants;
        if (! $participants) {
            $participants = [$before->manager_id];
        }
        $data['manager_id'] = self::responsible($input['manager_id'] ?? null, $participants, $before);

        return DB::transaction(function () use ($before, $data, $oldParticipants, $participants, $moved) {
            $status = $data['status'] ?? $before->status;
            $duration = (int) ($data['duration_min'] ?? $before->duration_min);
            $timeChanged = $moved || $duration !== (int) $before->duration_min;
            $added = array_values(array_diff($participants, $oldParticipants));
            $removed = array_values(array_diff($oldParticipants, $participants));
            if (in_array($status, MeetingSlots::BUSY, true) && ($timeChanged || $added || ! in_array($before->status, MeetingSlots::BUSY, true))) {
                $start = self::utc($data['starts_at'] ?? $before->starts_at);
                MeetingSlots::assertFree($participants, $start, $duration, $before->id);
            }
            $old = $before->getAttributes();
            $previousStart = self::iso($before->starts_at);
            $before->update($data);
            self::syncParticipants($before->id, $participants);
            $after = $before->fresh();
            $changes = Audit::diff($old, $after->getAttributes(), self::TRACKED);
            if ($added || $removed) {
                $changes['participants'] = ['old' => self::names($oldParticipants), 'new' => self::names($participants)];
            }
            if ($changes) {
                Activities::log('meeting.updated', ['lead_id' => $after->lead_id, 'deal_id' => $after->deal_id, 'meeting_id' => $after->id], ['changes' => $changes]);
                Audit::log('meeting.update', 'meeting', $after->id, $changes);
            }
            if ($added) {
                Outbox::publish('meeting.participants_added', ['meetingId' => $after->id, 'userIds' => $added, 'participantIds' => $participants]);
            }
            if ($moved && $after->status !== 'CANCELLED') {
                Outbox::publish('meeting.rescheduled', ['meetingId' => $after->id, 'managerId' => $after->manager_id, 'ropId' => $after->rop_id,
                    'participantIds' => $participants, 'startsAt' => self::iso($after->starts_at), 'previousStartsAt' => $previousStart]);
            }
            if ($after->status === 'CANCELLED' && $old['status'] !== 'CANCELLED') {
                Outbox::publish('meeting.cancelled', ['meetingId' => $after->id, 'managerId' => $after->manager_id, 'ropId' => $after->rop_id, 'participantIds' => $participants]);
            }

            return $after;
        });
    }

    /** «Встреча проведена» + результат (пишет ответственный); лид переходит на этап «Встреча проведена». */
    public function complete(Meeting $before, string $result): Meeting
    {
        if (! self::canWriteResult($before)) {
            abort(403, t('meetings.errors.responsibleOnly'));
        }
        if ($before->status === 'DONE') {
            throw new BusinessRule(t('meetings.errors.alreadyDone'));
        }
        if ($before->status === 'CANCELLED') {
            throw new BusinessRule(t('meetings.errors.cancelled'));
        }

        return DB::transaction(function () use ($before, $result) {
            $from = $before->status;
            $before->update(['status' => 'DONE', 'result' => $result]);
            Activities::log('meeting.completed', ['lead_id' => $before->lead_id, 'deal_id' => $before->deal_id, 'meeting_id' => $before->id], ['result' => mb_substr($result, 0, 500)]);
            if ($before->lead_id) {
                Lead::whereKey($before->lead_id)->update(['last_contact_at' => now()]);
                LeadService::advanceTo($before->lead_id, 'MEETING_DONE', access()->id());
            }
            Audit::log('meeting.complete', 'meeting', $before->id, ['status' => ['old' => $from, 'new' => 'DONE']]);
            Outbox::publish('meeting.completed', ['meetingId' => $before->id, 'managerId' => $before->manager_id, 'ropId' => $before->rop_id, 'participantIds' => $before->participantIds()]);

            return $before->refresh();
        });
    }

    private static function utc(\DateTimeInterface|string $d): CarbonImmutable
    {
        return ($d instanceof \DateTimeInterface ? CarbonImmutable::instance($d) : CarbonImmutable::parse($d, 'UTC'))->utc();
    }

    private static function iso(\DateTimeInterface|string $d): string
    {
        return self::utc($d)->format('Y-m-d\TH:i:s.v\Z');
    }

    /** «Сегодня» по Ташкенту — начало дня в UTC (для «Предстоящих»). */
    public static function todayStartUtc(): CarbonImmutable
    {
        return Format::today()->utc();
    }
}
