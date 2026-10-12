<?php

namespace Tests\Feature\Sales;

use App\Models\Activity;
use App\Models\AuditLog;
use App\Models\Commission;
use App\Models\Contract;
use App\Models\IdempotencyKey;
use App\Models\OutboxEvent;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ProposalVersion;
use App\Models\Tariff;
use App\Models\Task;
use App\Services\Sales\Numbers;

/** Сценарий приёмки §84, шаги 9–16 и 25 (как sales.int.test.ts прежней версии). */
class SalesFlowTest extends SalesTestCase
{
    public function test_proposal_to_payment_creates_project_and_commissions(): void
    {
        $deal = $this->deal();
        $this->actingAsUser($this->manager);

        // Шаг 9: КП v1
        $this->post('/sales/proposals', [
            'deal_id' => $deal->id, 'title' => 'SMM на 3 месяца', 'currency' => 'UZS', 'payment_terms' => '50% предоплата',
            'items' => [['service_id' => $deal->service_id, 'description' => 'SMM', 'quantity' => '3', 'unit_price' => '3 000 000']],
        ])->assertSessionHasNoErrors();
        $kp = Proposal::where('deal_id', $deal->id)->firstOrFail();
        $this->assertSame('9000000.00', (string) $kp->total);
        $this->assertSame(1, $kp->current_version);
        $this->assertTrue(Activity::where('type', 'proposal.created')->where('deal_id', $deal->id)->exists());
        $this->assertTrue(AuditLog::where('action', 'proposal.create')->where('entity_id', $kp->id)->exists());

        // ТЗ §16: изменение → v2 (11 млн) → v3 (10,5 млн)
        $this->put("/sales/proposals/{$kp->id}", ['title' => 'SMM на 3 месяца', 'currency' => 'UZS', 'version_comment' => 'Добавили сторис',
            'items' => [['service_id' => $deal->service_id, 'description' => 'SMM + сторис', 'quantity' => '1', 'unit_price' => '11000000']]])->assertSessionHasNoErrors();
        $this->put("/sales/proposals/{$kp->id}", ['title' => 'SMM на 3 месяца', 'currency' => 'UZS',
            'items' => [['service_id' => $deal->service_id, 'description' => 'SMM + сторис', 'quantity' => '1', 'unit_price' => '11000000', 'discount_pct' => '4.5454545']]])->assertSessionHasNoErrors();
        $this->assertSame('10500000.00', (string) $kp->fresh()->total);
        $this->assertSame([[3, '10500000.00'], [2, '11000000.00'], [1, '9000000.00']],
            ProposalVersion::where('proposal_id', $kp->id)->orderByDesc('version')->get()->map(fn ($v) => [$v->version, (string) $v->total])->all());
        $this->getJson("/sales/proposals/{$kp->id}/versions")->assertOk()->assertJsonCount(3);

        // PDF
        $pdf = $this->get("/documents/proposals/{$kp->id}?format=pdf");
        $pdf->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $pdf->getContent());

        // Согласование у РОП: пока на согласовании — отправить нельзя; менеджер не утверждает
        $this->post("/sales/proposals/{$kp->id}/submit-approval")->assertSessionHasNoErrors();
        $this->postJson("/sales/proposals/{$kp->id}/send")->assertStatus(422);
        $this->post("/sales/proposals/{$kp->id}/approve")->assertForbidden();
        $this->assertTrue(OutboxEvent::where('type', 'proposal.approval_requested')->exists());
        $this->actingAsUser($this->rop)->post("/sales/proposals/{$kp->id}/approve")->assertSessionHasNoErrors();
        $this->assertSame($this->rop->id, $kp->fresh()->approved_by_id);

        // Шаг 10: отправлено → «КП отправлено»
        $this->actingAsUser($this->manager)->post("/sales/proposals/{$kp->id}/send")->assertSessionHasNoErrors();
        $this->assertSame('PROPOSAL_SENT', $this->stageOf($deal));

        // Шаг 11: принято → «Переговоры», сумма сделки = итог КП
        $this->post("/sales/proposals/{$kp->id}/accept")->assertSessionHasNoErrors();
        $this->assertSame('NEGOTIATION', $this->stageOf($deal));
        $this->assertSame('10500000.00', (string) $deal->fresh()->amount);
        $this->putJson("/sales/proposals/{$kp->id}", ['title' => 'x', 'currency' => 'UZS', 'items' => [['description' => 'x', 'quantity' => 1, 'unit_price' => 1]]])->assertStatus(422);

        // Шаг 12–13: договор из КП → подписан → «Ожидаем оплату»
        $this->post('/sales/contracts', ['deal_id' => $deal->id, 'proposal_id' => $kp->id, 'contract_date' => '2026-10-06', 'amount' => '10500000', 'currency' => 'UZS'])->assertSessionHasNoErrors();
        $contract = Contract::where('deal_id', $deal->id)->firstOrFail();
        $this->assertSame('CONTRACT', $this->stageOf($deal));
        $this->post("/sales/contracts/{$contract->id}/sign")->assertSessionHasNoErrors();
        $this->assertSame('AWAITING_PAYMENT', $this->stageOf($deal));
        $this->assertTrue(OutboxEvent::where('type', 'contract.signed')->exists());

        // Шаг 14: предоплата 50%; менеджер не подтверждает сам
        $this->post('/finance/payments', ['deal_id' => $deal->id, 'contract_id' => $contract->id, 'amount' => '5250000', 'currency' => 'UZS', 'type' => 'PREPAYMENT', 'method' => 'BANK'])->assertSessionHasNoErrors();
        $prepay = Payment::where('deal_id', $deal->id)->firstOrFail();
        $this->assertSame('PENDING', $prepay->status);
        $this->post("/finance/payments/{$prepay->id}/confirm")->assertForbidden();

        // Шаг 15–16, 25: РОП подтверждает → PAID → проект + комиссии
        $res = $this->actingAsUser($this->rop)->postJson("/finance/payments/{$prepay->id}/confirm");
        $res->assertOk()->assertJsonPath('projectCreated', true);
        $this->assertSame('PAID', $prepay->fresh()->status);
        $comms = Commission::where('payment_id', $prepay->id)->get()->map(fn ($c) => [$c->role, (string) $c->amount_uzs])->sortBy(0)->values()->all();
        $this->assertSame([['MANAGER', '525000.00'], ['ROP', '525000.00']], $comms);

        $won = $deal->fresh();
        $this->assertSame('WON', $won->status);
        $this->assertSame('PAID', $won->stage->code);
        $project = Project::where('deal_id', $deal->id)->firstOrFail();
        $this->assertSame($this->rop->id, $project->rop_id); // Rule 4
        $this->assertSame($this->manager->id, $project->manager_id);
        $this->assertSame($deal->client_id, $project->client_id); // Rule 1
        $this->assertSame('10500000.00', (string) $project->price);
        // Задачи из шаблона услуги SMM
        $this->assertSame(8, Task::where('project_id', $project->id)->count());
        foreach (['deal.won', 'payment.paid', 'project.created'] as $type) {
            $this->assertTrue(OutboxEvent::where('type', $type)->exists(), $type);
        }

        // Второй платёж: проект не дублируется
        $this->actingAsUser($this->manager)->post('/finance/payments', ['deal_id' => $deal->id, 'amount' => '5250000', 'currency' => 'UZS', 'type' => 'FINAL', 'method' => 'BANK'])->assertSessionHasNoErrors();
        $final = Payment::where('deal_id', $deal->id)->where('type', 'FINAL')->firstOrFail();
        $this->actingAsUser($this->rop)->postJson("/finance/payments/{$final->id}/confirm")->assertOk()->assertJsonPath('projectCreated', false);
        $this->assertSame(1, Project::count());

        $money = $this->actingAsUser($this->manager)->getJson("/sales/deals/{$deal->id}/money")->assertOk()->json();
        $this->assertSame(['10500000.00', '10500000.00', '0.00'], [$money['contractUzs'], $money['paidUzs'], $money['receivableUzs']]);

        // Менеджер видит свои комиссии, РОП — отдела
        $this->get('/finance/commissions?period=')->assertOk()->assertSee(money('1050000'));
        $this->assertSame(2, Commission::where('user_id', $this->manager->id)->count());
        $this->actingAsUser($this->rop)->get('/finance/commissions?period=')->assertOk();
    }

    public function test_double_confirm_does_not_create_second_project(): void
    {
        $deal = $this->deal();
        $this->actingAsUser($this->manager)->post('/finance/payments', ['deal_id' => $deal->id, 'amount' => '9000000', 'currency' => 'UZS', 'type' => 'FULL', 'method' => 'CASH']);
        $p = Payment::firstOrFail();
        $this->actingAsUser($this->rop);
        $this->postJson("/finance/payments/{$p->id}/confirm")->assertOk();
        $this->postJson("/finance/payments/{$p->id}/confirm")->assertStatus(422);
        $this->assertSame(1, Project::count());
        $this->assertSame(2, Commission::count());
    }

    public function test_refund_reverses_commissions_and_is_limited(): void
    {
        $deal = $this->deal();
        $this->actingAsUser($this->manager)->post('/finance/payments', ['deal_id' => $deal->id, 'amount' => '9000000', 'currency' => 'UZS', 'type' => 'FULL', 'method' => 'CASH']);
        $p = Payment::firstOrFail();
        $this->actingAsUser($this->rop)->postJson("/finance/payments/{$p->id}/confirm")->assertOk();
        $this->postJson("/finance/payments/{$p->id}/refund", ['amount' => '10000000', 'method' => 'CASH', 'comment' => 'x'])->assertStatus(422);
        $this->postJson("/finance/payments/{$p->id}/refund", ['amount' => '1000000', 'method' => 'CASH', 'comment' => ''])->assertStatus(422);
        $r = $this->postJson("/finance/payments/{$p->id}/refund", ['amount' => '1000000', 'method' => 'CASH', 'comment' => 'Частичный возврат'])->assertOk()->json('id');
        $this->assertSame(['-100000.00', '-100000.00'], Commission::where('payment_id', $r)->pluck('amount_uzs')->map(fn ($v) => (string) $v)->sort()->values()->all());
        $this->assertSame('1000000.00', $this->getJson("/sales/deals/{$deal->id}/money")->json('refundedUzs'));
        $this->assertTrue(OutboxEvent::where('type', 'payment.refunded')->exists());
        $this->assertTrue(AuditLog::where('action', 'payment.refund')->where('entity_id', $r)->exists());
        // Остаток к возврату — 8 млн
        $this->postJson("/finance/payments/{$p->id}/refund", ['amount' => '8000001', 'method' => 'CASH', 'comment' => 'x'])->assertStatus(422);
        $this->postJson("/finance/payments/{$p->id}/refund", ['amount' => '8000000', 'method' => 'CASH', 'comment' => 'Остаток'])->assertOk();
    }

    public function test_rop_gets_15_percent_when_average_check_over_3000_usd(): void
    {
        $deal = $this->deal('50000000'); // ≈ 3953 USD по курсу 12 650
        $this->actingAsUser($this->manager)->post('/finance/payments', ['deal_id' => $deal->id, 'amount' => '50000000', 'currency' => 'UZS', 'type' => 'FULL', 'method' => 'BANK']);
        $p = Payment::firstOrFail();
        $this->actingAsUser($this->rop)->postJson("/finance/payments/{$p->id}/confirm")->assertOk();
        $rop = Commission::where('payment_id', $p->id)->where('role', 'ROP')->firstOrFail();
        $this->assertSame('15', Numbers::rate($rop->rate));
        $this->assertSame('7500000.00', (string) $rop->amount_uzs);
        $this->assertSame('10', Numbers::rate(Commission::where('payment_id', $p->id)->where('role', 'MANAGER')->value('rate')));
    }

    public function test_foreign_payments_and_proposals_are_hidden(): void
    {
        $deal = $this->deal();
        $this->actingAsUser($this->manager)->post('/finance/payments', ['deal_id' => $deal->id, 'amount' => '100', 'currency' => 'UZS', 'type' => 'FULL', 'method' => 'CASH']);
        $p = Payment::firstOrFail();
        $this->actingAsUser($this->manager2);
        $this->patchJson("/finance/payments/{$p->id}", ['amount' => '1'])->assertNotFound();
        $this->postJson('/finance/payments', ['deal_id' => $deal->id, 'amount' => '100', 'currency' => 'UZS', 'type' => 'FULL', 'method' => 'CASH'])->assertNotFound();
        $this->postJson('/sales/proposals', ['deal_id' => $deal->id, 'title' => 'x', 'currency' => 'UZS', 'items' => [['description' => 'x', 'quantity' => 1, 'unit_price' => 1]]])->assertNotFound();
        $this->get('/finance/payments')->assertOk()->assertDontSee(Numbers::payment($p->number));
        $this->actingAsUser($this->executor);
        $this->get('/finance/payments')->assertForbidden();
        $this->get('/finance/commissions')->assertForbidden();
        $this->get('/sales/proposals')->assertForbidden();
    }

    public function test_pending_payment_can_be_edited_and_cancelled_but_not_after_confirm(): void
    {
        $deal = $this->deal();
        $this->actingAsUser($this->manager)->post('/finance/payments', ['deal_id' => $deal->id, 'amount' => '500000', 'currency' => 'UZS', 'type' => 'PREPAYMENT', 'method' => 'CASH']);
        $p = Payment::firstOrFail();
        $this->patchJson("/finance/payments/{$p->id}", ['amount' => '5 000 000', 'method' => 'BANK'])->assertOk();
        $p->refresh();
        $this->assertSame(['5000000.00', '5000000.00', 'BANK'], [(string) $p->amount, (string) $p->amount_uzs, $p->method]);
        $audit = AuditLog::where('action', 'payment.update')->firstOrFail();
        $this->assertEquals(['old' => 'CASH', 'new' => 'BANK'], $audit->changes['method']);

        $this->actingAsUser($this->rop)->postJson("/finance/payments/{$p->id}/confirm")->assertOk();
        $late = $this->actingAsUser($this->manager)->patchJson("/finance/payments/{$p->id}", ['amount' => '1'])->assertStatus(422);
        $this->assertStringContainsString('возврат', $late->json('message'));
        $this->postJson("/finance/payments/{$p->id}/cancel")->assertStatus(422);

        $this->post('/finance/payments', ['deal_id' => $deal->id, 'amount' => '100', 'currency' => 'UZS', 'type' => 'FINAL', 'method' => 'CASH']);
        $p2 = Payment::where('type', 'FINAL')->firstOrFail();
        $this->postJson("/finance/payments/{$p2->id}/cancel")->assertOk();
        $this->assertSame('CANCELLED', $p2->fresh()->status);
        $this->postJson("/finance/payments/{$p2->id}/confirm")->assertForbidden();
    }

    public function test_idempotency_key_prevents_duplicate_payment(): void
    {
        $deal = $this->deal();
        $this->actingAsUser($this->manager);
        $body = ['deal_id' => $deal->id, 'amount' => '100', 'currency' => 'UZS', 'type' => 'FULL', 'method' => 'CASH', '_idem' => 'abcdefgh12345678'];
        $this->post('/finance/payments', $body)->assertRedirect();
        $this->post('/finance/payments', $body)->assertRedirect()->assertHeader('Idempotent-Replay', 'true');
        $this->assertSame(1, Payment::count());
        $this->assertSame(1, IdempotencyKey::count());
        // Ошибка освобождает ключ
        $this->postJson('/finance/payments', ['_idem' => 'zzzzzzzz12345678', 'amount' => '-1'] + $body)->assertStatus(422);
        $this->assertSame(1, IdempotencyKey::count());
    }

    public function test_contract_rules(): void
    {
        $deal = $this->deal();
        $this->actingAsUser($this->manager);
        // Без КП — договор только по принятому КП
        $this->post('/sales/proposals', ['deal_id' => $deal->id, 'title' => 'SMM', 'currency' => 'UZS', 'items' => [['description' => 'SMM', 'quantity' => 1, 'unit_price' => '9000000']]]);
        $kp = Proposal::firstOrFail();
        $this->postJson('/sales/contracts', ['deal_id' => $deal->id, 'proposal_id' => $kp->id, 'contract_date' => '2026-10-06', 'amount' => '9000000', 'currency' => 'UZS'])->assertStatus(422);
        $this->postJson('/sales/contracts', ['deal_id' => $deal->id, 'contract_date' => '2026-10-06', 'amount' => '9000000', 'currency' => 'UZS'])->assertOk();
        $c = Contract::firstOrFail();
        $this->patchJson("/sales/contracts/{$c->id}", ['amount' => '8000000', 'comment' => 'Скидка'])->assertOk();
        $this->assertSame('8000000.00', (string) $c->fresh()->amount);
        $this->assertTrue(AuditLog::where('action', 'contract.update')->exists());
        $this->postJson("/sales/contracts/{$c->id}/sign")->assertOk();
        $this->patchJson("/sales/contracts/{$c->id}", ['amount' => '1'])->assertStatus(422);
        $this->postJson("/sales/contracts/{$c->id}/send")->assertStatus(422);
        // С подтверждённой оплатой отменить нельзя
        $this->post('/finance/payments', ['deal_id' => $deal->id, 'contract_id' => $c->id, 'amount' => '100', 'currency' => 'UZS', 'type' => 'FULL', 'method' => 'CASH']);
        $this->actingAsUser($this->rop)->postJson('/finance/payments/'.Payment::firstOrFail()->id.'/confirm')->assertOk();
        $this->postJson("/sales/contracts/{$c->id}/cancel")->assertStatus(422);
        $this->actingAsUser($this->manager2)->postJson("/sales/contracts/{$c->id}/cancel")->assertNotFound();
    }

    public function test_tariff_price_is_locked_for_manager(): void
    {
        $deal = $this->deal('8222500');
        $smm = Tariff::where('name', 'Эконом')->whereHas('service', fn ($q) => $q->where('code', 'SMM'))->firstOrFail();
        $this->actingAsUser($this->manager);
        // 650 USD × 12 650 = 8 222 500
        $this->postJson('/sales/proposals', ['deal_id' => $deal->id, 'title' => 'SMM', 'currency' => 'UZS', 'items' => [['tariff_id' => $smm->id, 'description' => 'SMM — Эконом', 'quantity' => 1, 'unit_price' => '7000000']]])
            ->assertStatus(422)->assertJsonValidationErrors('items.0.unit_price');
        $this->postJson('/sales/proposals', ['deal_id' => $deal->id, 'title' => 'SMM', 'currency' => 'UZS', 'items' => [['tariff_id' => $smm->id, 'description' => 'SMM — Эконом', 'quantity' => 1, 'unit_price' => '8222500']]])->assertOk();
        $this->assertSame($smm->service_id, Proposal::firstOrFail()->items()->first()->service_id);
        // РОП может изменить цену тарифа
        $this->actingAsUser($this->rop)->postJson('/sales/proposals', ['deal_id' => $deal->id, 'title' => 'SMM', 'currency' => 'UZS', 'items' => [['tariff_id' => $smm->id, 'description' => 'x', 'quantity' => 1, 'unit_price' => '7000000', 'discount_pct' => 5]]])->assertOk();
    }

    public function test_deal_partials_render_and_respect_permissions(): void
    {
        $deal = $this->deal();
        $this->readyToPay($deal);
        $this->actingAsUser($this->manager);
        $access = $this->as($this->manager);
        foreach (['deal-proposals', 'deal-contracts', 'deal-payments', 'deal-files'] as $partial) {
            $html = view('sales.partials.'.$partial, ['deal' => $deal->fresh()])->render();
            $this->assertNotSame('', trim($html), $partial);
        }
        $this->assertStringContainsString(route('sales.requisites.update', $deal->client_id), view('sales.partials.client-requisites', ['client' => $deal->client])->render());
        $this->as($this->executor);
        foreach (['deal-proposals', 'deal-contracts', 'deal-payments'] as $partial) {
            $this->assertSame('', trim(view('sales.partials.'.$partial, ['deal' => $deal->fresh()])->render()), $partial);
        }
        unset($access);
    }
}
