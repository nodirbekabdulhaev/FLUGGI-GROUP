<?php

namespace App\Services\Crm;

use App\Models\Activity;
use App\Models\StageHistory;

/**
 * Таймлайн (ТЗ §12): пишется в транзакции изменения, изменить и удалить нельзя.
 * Типы — как в прежней версии: lead.created, deal.stage_changed, payment.paid, project.created…
 */
final class Activities
{
    /** @param  array{lead_id?:?string,deal_id?:?string,client_id?:?string,meeting_id?:?string,project_id?:?string,task_id?:?string}  $links */
    public static function log(string $type, array $links = [], ?array $payload = null, ?string $actorId = null): Activity
    {
        return Activity::create([
            'type' => $type,
            'actor_id' => $actorId ?? access(false)?->id(),
            'lead_id' => $links['lead_id'] ?? null,
            'deal_id' => $links['deal_id'] ?? null,
            'client_id' => $links['client_id'] ?? null,
            'meeting_id' => $links['meeting_id'] ?? null,
            'project_id' => $links['project_id'] ?? null,
            'task_id' => $links['task_id'] ?? null,
            'payload' => $payload,
        ]);
    }

    /** Переход по воронке + сколько секунд запись провела на прошлом этапе. */
    public static function stageChange(?string $leadId, ?string $dealId, ?string $fromStageId, string $toStageId, string $changedById): void
    {
        $last = StageHistory::query()
            ->when($leadId, fn ($q) => $q->where('lead_id', $leadId), fn ($q) => $q->where('deal_id', $dealId))
            ->orderByDesc('created_at')->first();
        StageHistory::create([
            'lead_id' => $leadId,
            'deal_id' => $dealId,
            'from_stage_id' => $fromStageId,
            'to_stage_id' => $toStageId,
            'changed_by_id' => $changedById,
            'duration_sec' => $last ? (int) round(now()->diffInMilliseconds($last->created_at, true) / 1000) : null,
        ]);
    }
}
