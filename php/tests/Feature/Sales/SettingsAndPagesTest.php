<?php

namespace Tests\Feature\Sales;

use App\Models\AuditLog;
use App\Models\Commission;
use App\Models\CommissionRule;
use App\Models\EmployeeRate;
use App\Models\Payment;
use App\Models\Service;
use App\Models\Tariff;
use App\Models\WorkItem;
use Illuminate\Support\Str;

/** Комиссии (утвердить/выплатить), правила, тарифы и ставки; экраны в обоих языках. */
class SettingsAndPagesTest extends SalesTestCase
{
    private function paidDeal(): Payment
    {
        $deal = $this->deal();
        $this->actingAsUser($this->rop)->post('/finance/payments', ['deal_id' => $deal->id, 'amount' => '9000000', 'currency' => 'UZS', 'type' => 'FULL', 'method' => 'CASH']);
        $p = Payment::firstOrFail();
        $this->actingAsUser($this->rop)->postJson("/finance/payments/{$p->id}/confirm")->assertOk();

        return $p;
    }

    public function test_commissions_approve_and_pay_only_by_ceo(): void
    {
        $this->paidDeal();
        $ids = Commission::pluck('id')->all();
        $this->actingAsUser($this->rop)->postJson('/finance/commissions/approve', ['ids' => $ids])->assertForbidden();
        $this->actingAsUser($this->ceo);
        $this->postJson('/finance/commissions/pay', ['ids' => $ids])->assertStatus(422);
        $this->postJson('/finance/commissions/approve', ['ids' => $ids])->assertOk()->assertJsonPath('updated', 2);
        $this->assertSame(['APPROVED'], Commission::pluck('status')->unique()->values()->all());
        $this->postJson('/finance/commissions/approve', ['ids' => $ids])->assertStatus(422);
        $this->postJson('/finance/commissions/pay', ['ids' => $ids])->assertOk();
        $this->assertSame(['PAID'], Commission::pluck('status')->unique()->values()->all());
        $this->assertSame(2, AuditLog::where('action', 'commission.pay')->count());
        $this->postJson('/finance/commissions/approve', ['ids' => [(string) Str::uuid()]])->assertNotFound();
    }

    public function test_commission_scope_own_team_all(): void
    {
        $this->paidDeal();
        $this->actingAsUser($this->manager)->get('/finance/commissions?period=')->assertOk()->assertSee($this->manager->full_name)->assertDontSee($this->rop->full_name);
        $this->actingAsUser($this->rop)->get('/finance/commissions?period=')->assertOk()->assertSee($this->manager->full_name)->assertSee($this->rop->full_name);
        $this->actingAsUser($this->manager2)->get('/finance/commissions?period=')->assertOk()->assertDontSee($this->manager->full_name);
        $this->actingAsUser($this->ceo)->get('/finance/commissions?period=')->assertOk()->assertSee($this->manager->full_name);
    }

    public function test_commission_rules_crud_validation_and_access(): void
    {
        $this->actingAsUser($this->rop)->get('/settings/commission-rules')->assertForbidden();
        $this->actingAsUser($this->ceo)->get('/settings/commission-rules')->assertOk()->assertSee('РОП — 15%');
        $this->postJson('/settings/commission-rules', ['name' => 'X', 'applies_to' => 'MANAGER', 'calc_type' => 'PERCENT_OF_PAYMENT', 'value' => '150', 'is_active' => 1])->assertStatus(422);
        $id = $this->postJson('/settings/commission-rules', [
            'name' => 'Фикс', 'applies_to' => 'MANAGER', 'calc_type' => 'FIXED_PER_DEAL', 'value' => '500000', 'priority' => 50, 'is_active' => 1,
            'mode' => 'any', 'leaves' => [['metric' => 'orders_count', 'op' => '>', 'value' => '0']],
        ])->assertOk()->json('id');
        $rule = CommissionRule::findOrFail($id);
        $this->assertEquals(['any' => [['metric' => 'orders_count', 'op' => '>', 'value' => 0]]], $rule->conditions);
        $this->putJson("/settings/commission-rules/$id", ['name' => 'Фикс', 'applies_to' => 'MANAGER', 'calc_type' => 'FIXED_PER_DEAL', 'value' => '600000', 'priority' => 50])->assertOk();
        $this->assertFalse($rule->fresh()->is_active);
        $this->assertNull($rule->fresh()->conditions);
        $this->assertSame(2, AuditLog::where('entity_type', 'commission_rule')->count());
    }

    public function test_fixed_rule_pays_once_per_deal(): void
    {
        CommissionRule::create(['name' => 'Фикс', 'applies_to' => 'MANAGER', 'calc_type' => 'FIXED_PER_DEAL', 'value' => 500000, 'priority' => 100]);
        $deal = $this->deal();
        $this->actingAsUser($this->rop);
        foreach (['PREPAYMENT', 'FINAL'] as $type) {
            $this->post('/finance/payments', ['deal_id' => $deal->id, 'amount' => '4500000', 'currency' => 'UZS', 'type' => $type, 'method' => 'CASH']);
        }
        foreach (Payment::orderBy('number')->get() as $p) {
            $this->postJson("/finance/payments/{$p->id}/confirm")->assertOk();
        }
        // Комиссия — менеджеру сделки, хотя оплаты вносил РОП
        $this->assertSame([$this->manager->id], Commission::where('role', 'MANAGER')->pluck('user_id')->all());
        $this->assertSame(['500000.00'], Commission::where('role', 'MANAGER')->pluck('amount_uzs')->map(fn ($v) => (string) $v)->all());
    }

    public function test_tariffs_economics_and_editing(): void
    {
        $this->actingAsUser($this->ceo);
        $this->get('/settings/tariffs')->assertOk()->assertSee('Эконом')->assertSee(money('8222500'));
        $smm = Tariff::where('name', 'Эконом')->whereHas('service', fn ($q) => $q->where('code', 'SMM'))->firstOrFail();
        $list = collect($this->getJson('/sales/tariffs')->assertOk()->json());
        $this->assertSame(['priceUzs' => '8222500.00', 'executorsUzs' => '2922000.00'], array_intersect_key($list->firstWhere('id', $smm->id)['economics'], array_flip(['priceUzs', 'executorsUzs'])));
        // Менеджер видит тариф без экономики и не меняет
        $this->actingAsUser($this->manager);
        $this->assertNull(collect($this->getJson('/sales/tariffs')->json())->firstWhere('id', $smm->id)['economics']);
        $this->get('/settings/tariffs')->assertForbidden();
        $this->putJson("/settings/tariffs/{$smm->id}", ['service_id' => $smm->service_id, 'name' => 'x', 'price' => '1', 'currency' => 'USD'])->assertForbidden();

        $site = Tariff::where('name', 'Стандарт')->firstOrFail();
        $this->actingAsUser($this->ceo)->putJson("/settings/tariffs/{$site->id}", [
            'service_id' => $site->service_id, 'name' => 'Стандарт', 'price' => '1200', 'currency' => 'USD', 'is_active' => 1,
            'items' => [['kind' => 'FIXED', 'specialty' => 'DEVELOPER', 'amount' => '5 500 000', 'currency' => 'UZS', 'label' => 'Веб-разработчик']],
        ])->assertOk();
        $site->refresh();
        $this->assertSame('1200.00', (string) $site->price);
        $this->assertSame('5500000.00', (string) $site->items()->first()->amount);
        $this->putJson("/settings/tariffs/{$site->id}", ['service_id' => $site->service_id, 'name' => 'S', 'price' => '0', 'currency' => 'USD'])->assertStatus(422);
        $this->postJson('/settings/tariffs', ['service_id' => $site->service_id, 'name' => 'S', 'price' => '10', 'currency' => 'USD', 'items' => [['kind' => 'PIECE']]])->assertStatus(422);
        $this->assertTrue(AuditLog::where('action', 'tariff.update')->exists());

        // Работы
        $id = $this->postJson('/settings/work-items', ['name' => 'Монтаж', 'unit' => 'шт', 'specialty' => 'EDITOR', 'default_rate' => '100000', 'currency' => 'UZS', 'is_active' => 1])->assertOk()->json('id');
        $this->assertMatchesRegularExpression('/^W_[0-9A-F]{8}$/', WorkItem::findOrFail($id)->code);
    }

    public function test_employee_rates_access(): void
    {
        $reel = WorkItem::where('code', 'REEL')->firstOrFail();
        $this->actingAsUser($this->ceo)->putJson("/team/employees/{$this->executor->id}/rates", ['rates' => [['work_item_id' => $reel->id, 'rate' => '12', 'currency' => 'USD']]])->assertOk();
        $rates = collect($this->getJson("/team/employees/{$this->executor->id}/rates")->assertOk()->json());
        $this->assertSame('12.00', $rates->firstWhere('workItem.code', 'REEL')['rate']);
        $this->actingAsUser($this->executor)->get("/team/employees/{$this->executor->id}/rates")->assertOk()->assertSee('12');
        $this->getJson("/team/employees/{$this->manager->id}/rates")->assertForbidden();
        $this->actingAsUser($this->rop)->putJson("/team/employees/{$this->executor->id}/rates", ['rates' => []])->assertForbidden();
        // Пустая ставка — удалить
        $this->actingAsUser($this->ceo)->putJson("/team/employees/{$this->executor->id}/rates", ['rates' => [['work_item_id' => $reel->id, 'rate' => '', 'currency' => 'USD']]])->assertOk();
        $this->assertSame(0, EmployeeRate::where('user_id', $this->executor->id)->count());
    }

    public function test_pages_render_for_allowed_roles_in_both_languages(): void
    {
        $deal = $this->deal();
        $this->readyToPay($deal);
        $pages = [
            'MANAGER' => ['/sales/proposals', '/sales/contracts', '/finance/payments', '/finance/commissions'],
            'ROP' => ['/sales/proposals?status=ACCEPTED', '/sales/contracts?q=Hamza', '/finance/payments?date_from=2026-01-01&type=FULL', '/finance/commissions'],
            'CEO' => ['/settings/commission-rules', '/settings/tariffs', '/settings/documents', '/finance/commissions?period=', "/team/employees/{$this->manager->id}/rates"],
        ];
        foreach (['ru', 'uz'] as $locale) {
            foreach ($pages as $role => $urls) {
                $user = ['MANAGER' => $this->manager, 'ROP' => $this->rop, 'CEO' => $this->ceo][$role];
                $user->update(['locale' => $locale]);
                $this->actingAsUser($user);
                foreach ($urls as $url) {
                    $this->get($url)->assertOk()->assertDontSee('sales.')->assertDontSee('tariffs.')->assertDontSee('commissionRules.');
                }
            }
        }
        // Узбекский текст на месте
        $this->manager->update(['locale' => 'uz']);
        $this->actingAsUser($this->manager)->get('/finance/payments')->assertOk()->assertSee('Toʻlovlar', false);
        $this->assertNotNull(Service::first());
    }
}
