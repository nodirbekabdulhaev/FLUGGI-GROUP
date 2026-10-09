<?php

namespace App\Services\Crm;

use App\Exceptions\BusinessRule;
use App\Models\Lead;
use App\Models\Meeting;
use App\Models\Team;
use App\Support\Audit;
use App\Support\Format;
use App\Support\Outbox;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Встречи по лиду или сделке. Менеджер — ответственный за запись, РОП — руководитель его отдела.
 * Назначение встречи по лиду переводит лид на этап «Назначена встреча», проведение — «Встреча проведена».
 */
final class MeetingService
{
    public const TRACKED = ['starts_at', 'duration_min', 'type', 'link', 'status', 'comment'];

    public static function find(string $id, string $code = 'meeting.read'): Meeting
    {
        return CrmAccess::meetings($code)->whereKey($id)->firstOrFail();
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

        return DB::transaction(function () use ($input, $access, $lead, $deal, $record, $team) {
            $m = Meeting::create([
                'lead_id' => $lead?->id,
                'deal_id' => $deal?->id,
                'client_id' => $deal?->client_id ?? $lead?->client_id,
                'manager_id' => $record->owner_id,
                'rop_id' => $team?->head_id,
                'team_id' => $record->team_id,
                'starts_at' => $input['starts_at'],
                'duration_min' => $input['duration_min'] ?? 60,
                'type' => $input['type'],
                'link' => $input['link'] ?? null,
                'comment' => $input['comment'] ?? null,
                'created_by_id' => $access->id(),
            ]);
            $m->refresh();
            $startsAt = self::iso($m->starts_at);
            Activities::log('meeting.created', ['lead_id' => $lead?->id, 'deal_id' => $deal?->id, 'meeting_id' => $m->id], ['startsAt' => $startsAt, 'type' => $m->type]);
            if ($lead) {
                LeadService::advanceTo($lead->id, 'MEETING_SCHEDULED', $access->id());
            }
            Audit::log('meeting.create', 'meeting', $m->id, ['starts_at' => ['old' => null, 'new' => $startsAt]]);
            Outbox::publish('meeting.created', ['meetingId' => $m->id, 'managerId' => $m->manager_id, 'ropId' => $m->rop_id, 'startsAt' => $startsAt]);

            return $m;
        });
    }

    /** Изменение и перенос: новая дата без явного статуса → «Перенесена». */
    public function update(Meeting $before, array $input): Meeting
    {
        if ($before->status === 'DONE') {
            throw new BusinessRule(t('meetings.errors.doneImmutable'));
        }
        $data = array_intersect_key($input, array_flip(['starts_at', 'duration_min', 'type', 'link', 'comment', 'status']));
        if (empty($data['status']) && ! empty($data['starts_at'])
            && CarbonImmutable::parse($data['starts_at'])->getTimestampMs() !== $before->starts_at->getTimestampMs()) {
            $data['status'] = 'RESCHEDULED';
        }
        if (array_key_exists('status', $data) && $data['status'] === null) {
            unset($data['status']);
        }

        return DB::transaction(function () use ($before, $data) {
            $old = $before->getAttributes();
            $before->update($data);
            $after = $before->fresh();
            $changes = Audit::diff($old, $after->getAttributes(), self::TRACKED);
            if ($changes) {
                Activities::log('meeting.updated', ['lead_id' => $after->lead_id, 'deal_id' => $after->deal_id, 'meeting_id' => $after->id], ['changes' => $changes]);
                Audit::log('meeting.update', 'meeting', $after->id, $changes);
            }

            return $after;
        });
    }

    /** «Встреча проведена» + результат; лид переходит на этап «Встреча проведена». */
    public function complete(Meeting $before, string $result): Meeting
    {
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
            Outbox::publish('meeting.completed', ['meetingId' => $before->id, 'managerId' => $before->manager_id, 'ropId' => $before->rop_id]);

            return $before->refresh();
        });
    }

    private static function iso(\DateTimeInterface $d): string
    {
        return CarbonImmutable::instance($d)->utc()->format('Y-m-d\TH:i:s.v\Z');
    }

    /** «Сегодня» по Ташкенту — начало дня в UTC (для «Предстоящих»). */
    public static function todayStartUtc(): CarbonImmutable
    {
        return Format::today()->utc();
    }
}
