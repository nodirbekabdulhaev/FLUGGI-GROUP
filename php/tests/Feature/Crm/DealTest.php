<?php

namespace Tests\Feature\Crm;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Deal;
use App\Models\OutboxEvent;
use App\Services\Crm\DealService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Сделки: создание, ворота этапов (BUSINESS_RULES §3), закрытие, возврат, удаление. */
class DealTest extends CrmTestCase
{
    private function deal(): Deal
    {
        $lead = $this->newLead($this->manager);
        [, $dealId] = $this->convert($lead, $this->rop, ['client_name' => 'C', 'amount' => '100']);

        return Deal::findOrFail($dealId);
    }

    public function test_paid_cannot_be_set_manually_and_gates_apply(): void
    {
        $deal = $this->deal();
        $this->actingAsUser($this->manager)->postJson("/sales/deals/{$deal->id}/stage", ['stage' => 'PAID'])->assertStatus(422);
        // КП и договор оформляет РОП: менеджеру — понятное сообщение, РОП — «сначала отправьте КП»
        $this->actingAsUser($this->manager)->postJson("/sales/deals/{$deal->id}/stage", ['stage' => 'PROPOSAL_SENT'])
            ->assertStatus(422)->assertJson(['message' => t('deals.errors.proposalByRop')]);
        $this->actingAsUser($this->rop)->postJson("/sales/deals/{$deal->id}/stage", ['stage' => 'PROPOSAL_SENT'])
            ->assertStatus(422)->assertJson(['message' => t('deals.errors.sendProposalFirst')]);
        // С отправленным КП — можно; дальше нужен договор
        DB::table('proposals')->insert($this->proposalRow($deal));
        $this->actingAsUser($this->manager)->postJson("/sales/deals/{$deal->id}/stage", ['stage' => 'NEGOTIATION'])->assertOk();
        $this->actingAsUser($this->manager)->postJson("/sales/deals/{$deal->id}/stage", ['stage' => 'CONTRACT'])
            ->assertStatus(422)->assertJson(['message' => t('deals.errors.contractByRop')]);
        $this->actingAsUser($this->rop)->postJson("/sales/deals/{$deal->id}/stage", ['stage' => 'CONTRACT'])
            ->assertStatus(422)->assertJson(['message' => t('deals.errors.createContractFirst')]);
        // Назад — без проверок
        $this->actingAsUser($this->manager)->post("/sales/deals/{$deal->id}/stage", ['stage' => 'NEED_DEFINED'])->assertSessionHasNoErrors();
        $this->assertSame('NEED_DEFINED', $deal->fresh()->stage->code);
        $this->assertTrue(OutboxEvent::where('type', 'deal.stage_changed')->exists());
        $this->assertTrue(AuditLog::where('action', 'deal.stage_change')->exists());
    }

    private function proposalRow(Deal $deal): array
    {
        $cols = collect(DB::select('SHOW COLUMNS FROM proposals'))->keyBy('Field');
        $row = ['id' => (string) Str::uuid(), 'deal_id' => $deal->id, 'status' => 'SENT'];
        foreach ($cols as $name => $c) {
            if (isset($row[$name]) || $c->Null === 'YES' || $c->Default !== null || str_contains($c->Extra, 'auto_increment')) {
                continue;
            }
            $row[$name] = match (true) {
                str_ends_with($name, '_id') => $name === 'client_id' ? $deal->client_id : ($name === 'created_by_id' || $name === 'author_id' ? $this->manager->id : $deal->owner_id),
                str_starts_with($c->Type, 'decimal'), str_starts_with($c->Type, 'int') => 0,
                str_starts_with($c->Type, 'datetime'), str_starts_with($c->Type, 'date') => now(),
                str_starts_with($c->Type, 'enum') => trim(explode(',', substr($c->Type, 5))[0], "'()"),
                str_starts_with($c->Type, 'json') => '{}',
                default => 'x',
            };
        }

        return $row;
    }

    public function test_close_lost_publishes_event_and_reopen(): void
    {
        $deal = $this->deal();
        $this->actingAsUser($this->manager)->post("/sales/deals/{$deal->id}/close", ['status' => 'REJECTED'])->assertSessionHasErrors('loss_reason_id');
        $this->actingAsUser($this->manager)->post("/sales/deals/{$deal->id}/close", ['status' => 'LOST', 'loss_reason_id' => $this->lossReason('NO_BUDGET')])->assertSessionHasNoErrors();
        $this->assertSame('LOST', $deal->fresh()->status);
        $this->assertTrue(OutboxEvent::where('type', 'deal.lost')->exists());
        $this->actingAsUser($this->manager)->postJson("/sales/deals/{$deal->id}/stage", ['stage' => 'NEGOTIATION'])->assertStatus(422);
        $this->actingAsUser($this->manager)->post("/sales/deals/{$deal->id}/reopen")->assertSessionHasNoErrors();
        $this->assertSame('OPEN', $deal->fresh()->status);
        // Оплаченную сделку не вернуть и не удалить
        $deal->update(['status' => 'WON']);
        $this->actingAsUser($this->rop)->post("/sales/deals/{$deal->id}/reopen")->assertSessionHas('error');
        $this->actingAsUser($this->rop)->delete("/sales/deals/{$deal->id}")->assertSessionHas('error');
        $this->assertNull($deal->fresh()->deleted_at);
    }

    public function test_repeat_deal_for_existing_client(): void
    {
        $deal = $this->deal();
        $this->actingAsUser($this->manager)->post('/sales/deals', [
            'client_id' => $deal->client_id, 'title' => 'Повторная', 'amount' => '5 000 000', 'currency' => 'UZS',
        ])->assertSessionHasNoErrors();
        $repeat = Deal::where('title', 'Повторная')->firstOrFail();
        $this->assertTrue($repeat->is_repeat);
        $this->assertSame('5000000.00', (string) $repeat->amount_uzs);
        $this->assertSame($this->manager->id, $repeat->owner_id);
        // Контакт чужого клиента не подходит
        $other = Client::create(['name' => 'X', 'owner_id' => $this->manager->id, 'team_id' => $this->team1->id]);
        $contact = $other->contacts()->create(['full_name' => 'Y']);
        $this->actingAsUser($this->manager)->post('/sales/deals', [
            'client_id' => $deal->client_id, 'contact_id' => $contact->id, 'title' => 'Z', 'amount' => '1',
        ])->assertSessionHasErrors('contact_id');
        // Без курса USD нельзя
        DB::table('exchange_rates')->delete();
        $this->actingAsUser($this->manager)->post('/sales/deals', ['client_id' => $deal->client_id, 'title' => 'U', 'amount' => '1', 'currency' => 'USD'])
            ->assertSessionHasErrors('currency');
    }

    public function test_assign_and_delete_rights(): void
    {
        $deal = $this->deal();
        $this->actingAsUser($this->manager)->post("/sales/deals/{$deal->id}/assign", ['owner_id' => $this->manager->id])->assertForbidden();
        $this->actingAsUser($this->ceo)->post("/sales/deals/{$deal->id}/assign", ['owner_id' => $this->otherManager->id])->assertSessionHasNoErrors();
        $this->assertSame($this->team2->id, $deal->fresh()->team_id);
        $this->actingAsUser($this->manager)->delete("/sales/deals/{$deal->id}")->assertForbidden();
        $this->actingAsUser($this->ceo)->delete("/sales/deals/{$deal->id}")->assertRedirect('/sales/deals');
        $this->assertSoftDeleted('deals', ['id' => $deal->id]);
    }

    public function test_deal_money_summary_shown_for_payment_read(): void
    {
        $deal = $this->deal();
        $this->actingAsUser($this->manager)->get("/sales/deals/{$deal->id}")->assertOk()->assertSee(t('sales.money.receivable'));
        $this->assertSame('0', DealService::money($deal)['receivable'] === '0.00' ? '0' : 'x');
    }
}
