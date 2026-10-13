<?php

namespace Tests\Feature\Smm;

use App\Models\AuditLog;
use App\Models\Commission;
use App\Models\CommissionRule;
use App\Models\Deal;
use App\Models\Employee;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\Payment;
use App\Models\PayrollEntry;
use App\Models\Service;
use App\Models\SmmIdea;
use App\Models\User;
use App\Services\Sales\Numbers;
use App\Support\Format;
use Tests\Feature\Sales\SalesTestCase;

/**
 * SMM и деньги: оклад по плану роликов (9 из 12 → 75 %), источник лида «Видео SMM» с выбором ролика,
 * перенос ролика в сделку, бонус 5 % с оплат SMM-специалисту (сторно при возврате) и в зарплате.
 */
class SmmSalesPayrollTest extends SalesTestCase
{
    private User $smm;

    protected function setUp(): void
    {
        parent::setUp();
        $this->smm = $this->user('EXECUTOR', employee: ['specialty' => 'SMM']);
    }

    private function idea(string $status = 'PUBLISHED', ?string $publishedAt = null, ?User $assignee = null): SmmIdea
    {
        static $n = 0;
        $n++;

        return SmmIdea::create([
            'title' => 'Ролик '.$n, 'status' => $status, 'author_id' => ($assignee ?? $this->smm)->id, 'assignee_id' => ($assignee ?? $this->smm)->id,
            'published_at' => $status === 'PUBLISHED' ? ($publishedAt ?? now()) : null, 'sort_order' => $n,
            'pm_approved_by_id' => $this->ceo->id, 'ceo_approved_by_id' => $this->ceo->id,
        ])->refresh();
    }

    public function test_salary_is_share_of_published_videos(): void
    {
        $this->actingAsUser($this->ceo)->get('/finance/payroll?period=2026-09')->assertOk()
            ->assertSee(t('payroll.smmTitle'))->assertSee($this->smm->full_name)->assertDontSee($this->executor->full_name.'</span>', false);
        $this->put('/finance/payroll/video-plans', ['period' => '2026-09', 'plans' => [$this->smm->id => ['salary' => '2 000 000', 'target' => '12']]])
            ->assertSessionHasNoErrors()->assertRedirect('/finance/payroll?period=2026-09');
        $emp = Employee::where('user_id', $this->smm->id)->firstOrFail();
        $this->assertSame('2000000.00', (string) $emp->base_salary);
        $this->assertSame(12, $emp->video_target);
        $this->assertTrue(AuditLog::where('action', 'employee.video_plan_update')->where('entity_id', $this->smm->id)->exists());
        $this->put('/finance/payroll/video-plans', ['plans' => [$this->smm->id => ['salary' => '1', 'target' => '0']]])->assertSessionHasErrors();
        $this->actingAsUser($this->rop)->put('/finance/payroll/video-plans', ['plans' => [$this->smm->id => ['salary' => '1', 'target' => '1']]])->assertForbidden();

        // 9 роликов в сентябре (по Ташкенту); 30.09 21:00 UTC = 1 октября в Ташкенте — не в сентябре; не опубликованный — не считается
        for ($i = 1; $i <= 9; $i++) {
            $this->idea('PUBLISHED', sprintf('2026-09-%02d 08:00:00', $i));
        }
        $this->idea('PUBLISHED', '2026-09-30 21:00:00');
        $this->idea('SCHEDULED');
        $this->idea('PUBLISHED', '2026-09-15 10:00:00', $this->user('EXECUTOR', employee: ['specialty' => 'EDITOR']));

        $this->actingAsUser($this->ceo)->post('/finance/payroll/calculate', ['period' => '2026-09'])->assertRedirect();
        $e = PayrollEntry::where('user_id', $this->smm->id)->where('period', '2026-09')->firstOrFail();
        $this->assertSame(9, $e->videos_published);
        $this->assertSame(12, $e->videos_target);
        $this->assertSame('2000000.00', (string) $e->base_full);
        $this->assertSame('1500000.00', (string) $e->base_salary);
        $this->assertSame('1500000.00', (string) $e->final_salary);
        $this->get('/finance/payroll?period=2026-09')->assertOk()->assertSee('9 из 12 видео (75%)', false);

        // Ручная правка оклада в черновике пересчётом не затирается
        $this->patch("/finance/payroll/{$e->id}", ['base_salary' => '1 600 000'])->assertSessionHasNoErrors();
        $this->post('/finance/payroll/calculate', ['period' => '2026-09']);
        $this->assertSame('1600000.00', (string) $e->fresh()->base_salary);

        // Больше плана — не больше 100 %
        for ($i = 1; $i <= 14; $i++) {
            $this->idea('PUBLISHED', sprintf('2026-08-%02d 08:00:00', $i));
        }
        $this->post('/finance/payroll/calculate', ['period' => '2026-08']);
        $aug = PayrollEntry::where('user_id', $this->smm->id)->where('period', '2026-08')->firstOrFail();
        $this->assertSame(14, $aug->videos_published);
        $this->assertSame('2000000.00', (string) $aug->base_salary);
        $this->get('/finance/payroll?period=2026-08')->assertOk()->assertSee('14 из 12 видео (100%)', false);

        // Исполнитель без SMM-специальности оклада не получает, как и раньше
        Employee::where('user_id', $this->executor->id)->update(['base_salary' => 5000000, 'video_target' => 10]);
        $this->post('/finance/payroll/calculate', ['period' => '2026-09']);
        $ex = PayrollEntry::where('user_id', $this->executor->id)->where('period', '2026-09')->firstOrFail();
        $this->assertSame('0.00', (string) $ex->base_salary);
        $this->assertNull($ex->videos_target);
    }

    public function test_smm_video_lead_source_requires_published_video_and_goes_to_deal(): void
    {
        $source = LeadSource::where('code', 'SMM_VIDEO')->firstOrFail();
        $this->assertSame('Видео SMM', $source->name_ru);
        $this->assertSame('SMM video', $source->name_uz);
        $this->assertSame('SMM video', $source->name_en);
        $published = $this->idea();
        $draft = $this->idea('EDITING');
        $data = [
            'contact_name' => 'Hamza', 'phone' => '+998901234567', 'source_id' => $source->id,
            'service_id' => Service::where('code', 'SMM')->value('id'), 'currency' => 'UZS', 'priority' => 'MEDIUM',
        ];
        $this->actingAsUser($this->manager);
        $this->get('/sales/leads')->assertOk()->assertSee(t('smm.leadVideo'))->assertSee($published->title)->assertDontSee($draft->title);
        $this->post('/sales/leads', $data)->assertSessionHasErrors('smm_idea_id');
        $this->post('/sales/leads', $data + ['smm_idea_id' => $draft->id])->assertSessionHasErrors('smm_idea_id');
        $this->post('/sales/leads', $data + ['smm_idea_id' => $published->id])->assertSessionHasNoErrors();
        $lead = Lead::latest('number')->firstOrFail();
        $this->assertSame($published->id, $lead->smm_idea_id);
        $this->get("/sales/leads/{$lead->id}")->assertOk()->assertSee($published->code().' · '.$published->title);

        // Другой источник — ролик в лиде не хранится
        $this->post('/sales/leads', array_merge($data, ['source_id' => LeadSource::where('code', 'INSTAGRAM')->value('id'), 'smm_idea_id' => $published->id, 'phone' => '+998901111111']))->assertSessionHasNoErrors();
        $this->assertNull(Lead::latest('number')->first()->smm_idea_id);

        // Квалификация: ролик переходит в сделку
        $this->actingAsUser($this->rop)->post("/sales/leads/{$lead->id}/convert", ['client_name' => 'Hamza Textile', 'amount' => '9000000', 'currency' => 'UZS'])
            ->assertSessionHasNoErrors();
        $deal = Deal::findOrFail($lead->fresh()->deal_id);
        $this->assertSame($published->id, $deal->smm_idea_id);
        $this->get("/sales/deals/{$deal->id}")->assertOk()->assertSee($published->title)->assertSee(t('crm.fields.smmIdea'));

        // Смена источника у лида на другой — ролик снимается
        $this->actingAsUser($this->manager);
        $this->post('/sales/leads', array_merge($data, ['smm_idea_id' => $published->id, 'phone' => '+998902222222']))->assertSessionHasNoErrors();
        $lead2 = Lead::latest('number')->first();
        $this->put("/sales/leads/{$lead2->id}", array_merge($data, ['source_id' => LeadSource::where('code', 'TELEGRAM')->value('id'), 'phone' => '+998902222222']))->assertSessionHasNoErrors();
        $this->assertNull($lead2->fresh()->smm_idea_id);
    }

    public function test_payment_accrues_smm_bonus_and_refund_reverses_it(): void
    {
        $rule = CommissionRule::where('applies_to', 'SMM')->firstOrFail();
        $this->assertSame('5', Numbers::rate($rule->value));
        $this->assertSame('PERCENT_OF_PAYMENT', $rule->calc_type);

        $video = $this->idea();
        $deal = $this->deal();
        // Ролик указывают в карточке сделки (форма редактирования)
        $this->actingAsUser($this->manager)->get("/sales/deals/{$deal->id}")->assertOk()->assertSee($video->title);
        $this->put("/sales/deals/{$deal->id}", ['title' => $deal->title, 'amount' => '9000000', 'currency' => 'UZS', 'smm_idea_id' => $this->idea('EDITING')->id])
            ->assertSessionHasErrors('smm_idea_id');
        $this->put("/sales/deals/{$deal->id}", ['title' => $deal->title, 'amount' => '9000000', 'currency' => 'UZS', 'smm_idea_id' => $video->id])->assertSessionHasNoErrors();
        $this->assertSame($video->id, $deal->fresh()->smm_idea_id);
        $this->assertTrue(AuditLog::where('action', 'deal.update')->where('entity_id', $deal->id)->exists());
        // Без поля в запросе ролик не сбрасывается
        $this->put("/sales/deals/{$deal->id}", ['title' => 'Новое название', 'amount' => '9000000', 'currency' => 'UZS'])->assertSessionHasNoErrors();
        $this->assertSame($video->id, $deal->fresh()->smm_idea_id);

        $this->post('/finance/payments', ['deal_id' => $deal->id, 'amount' => '9000000', 'currency' => 'UZS', 'type' => 'FULL', 'method' => 'CASH']);
        $p = Payment::firstOrFail();
        $this->actingAsUser($this->rop)->postJson("/finance/payments/{$p->id}/confirm")->assertOk();
        $c = Commission::where('payment_id', $p->id)->where('role', 'SMM')->firstOrFail();
        $this->assertSame($this->smm->id, $c->user_id);
        $this->assertSame('450000.00', (string) $c->amount_uzs);
        $this->assertSame('5', Numbers::rate($c->rate));
        $this->assertSame($rule->id, $c->rule_id);
        $this->assertSame(3, Commission::where('payment_id', $p->id)->count(), 'менеджер, РОП и SMM');

        $r = $this->postJson("/finance/payments/{$p->id}/refund", ['amount' => '1000000', 'method' => 'CASH', 'comment' => 'Частичный возврат'])->assertOk()->json('id');
        $this->assertSame('-50000.00', (string) Commission::where('payment_id', $r)->where('role', 'SMM')->value('amount_uzs'));
        $this->assertSame($this->smm->id, Commission::where('payment_id', $r)->where('role', 'SMM')->value('user_id'));

        // Комиссии видны в списке с ролью SMM; после утверждения — в зарплате
        $this->actingAsUser($this->ceo)->get('/finance/commissions')->assertOk()->assertSee(t('sales.commissions.role.SMM'));
        $ids = Commission::where('user_id', $this->smm->id)->pluck('id')->all();
        $this->post('/finance/commissions/approve', ['ids' => $ids])->assertSessionHasNoErrors();
        $period = Format::today()->format('Y-m');
        $this->post('/finance/payroll/calculate', ['period' => $period])->assertRedirect();
        $e = PayrollEntry::where('user_id', $this->smm->id)->where('period', $period)->firstOrFail();
        $this->assertSame('400000.00', (string) $e->commission);
        $this->assertSame('400000.00', (string) $e->final_salary);
    }

    public function test_smm_commission_rule_is_editable_in_settings(): void
    {
        $rule = CommissionRule::where('applies_to', 'SMM')->firstOrFail();
        foreach (['uz', 'ru', 'en'] as $locale) {
            $this->ceo->update(['locale' => $locale]);
            app()->setLocale($locale);
            $this->actingAsUser($this->ceo)->get('/settings/commission-rules')->assertOk()->assertSee(t('commissionRules.role.SMM'));
        }
        $this->put("/settings/commission-rules/{$rule->id}", [
            'name' => $rule->name, 'applies_to' => 'SMM', 'calc_type' => 'PERCENT_OF_PAYMENT', 'value' => '7', 'priority' => '0', 'is_active' => '1',
        ])->assertSessionHasNoErrors();
        $this->assertSame('7', Numbers::rate($rule->fresh()->value));

        $video = $this->idea();
        $deal = $this->deal();
        $deal->update(['smm_idea_id' => $video->id]);
        $this->actingAsUser($this->manager)->post('/finance/payments', ['deal_id' => $deal->id, 'amount' => '1000000', 'currency' => 'UZS', 'type' => 'FULL', 'method' => 'CASH']);
        $p = Payment::firstOrFail();
        $this->actingAsUser($this->rop)->postJson("/finance/payments/{$p->id}/confirm")->assertOk();
        $this->assertSame('70000.00', (string) Commission::where('payment_id', $p->id)->where('role', 'SMM')->value('amount_uzs'));
    }
}
