<?php

namespace Tests\Feature\Crm;

use App\Models\Deal;

/** Воронка: колонки по правам, суммы, прогноз, перенос карточек через JSON. */
class PipelineTest extends CrmTestCase
{
    public function test_board_columns_totals_and_moves(): void
    {
        $lead = $this->newLead($this->manager);
        $lead2 = $this->newLead($this->manager, ['company_name' => 'B', 'phone' => '+998935550000']);
        [, $dealId] = $this->convert($lead2, $this->rop, ['client_name' => 'B', 'amount' => '10000000']);
        $board = $this->actingAsUser($this->manager)->getJson('/sales/pipeline/data')->assertOk()->json();
        $this->assertSame(['NEW', 'CONTACTED', 'QUALIFICATION', 'MEETING_SCHEDULED', 'MEETING_DONE', 'NEED_DEFINED', 'PROPOSAL_SENT', 'NEGOTIATION', 'CONTRACT', 'AWAITING_PAYMENT', 'PAID'], array_column($board['columns'], 'code'));
        $this->assertSame('10000000.00', $board['pipelineUzs']);
        $this->assertSame('3000000.00', $board['weightedUzs']);
        $need = collect($board['columns'])->firstWhere('code', 'NEED_DEFINED');
        $this->assertSame([$dealId], array_column($need['items'], 'id'));

        // Перенос лида — JSON; лид нельзя перенести в этап сделки
        $this->actingAsUser($this->manager)->postJson("/sales/leads/{$lead->id}/stage", ['stage' => 'CONTACTED'])->assertOk()->assertJson(['ok' => true]);
        $this->assertSame('CONTACTED', $lead->fresh()->stage->code);
        $this->actingAsUser($this->manager)->postJson("/sales/leads/{$lead->id}/stage", ['stage' => 'NEGOTIATION'])->assertStatus(422);
        $this->actingAsUser($this->manager)->postJson("/sales/deals/{$dealId}/stage", ['stage' => 'NEW'])->assertStatus(422);
        // Ручная вероятность влияет на прогноз
        Deal::whereKey($dealId)->update(['probability_override' => 50]);
        $this->assertSame('5000000.00', $this->actingAsUser($this->manager)->getJson('/sales/pipeline/data')->json('weightedUzs'));
        // Фильтр по ответственному
        $this->assertSame(0, collect($this->actingAsUser($this->ceo)->getJson('/sales/pipeline/data?owner_id='.$this->otherManager->id)->json('columns'))->sum('count'));
        $this->actingAsUser($this->manager)->get('/sales/pipeline')->assertOk()->assertSee($lead->title, false);
    }
}
