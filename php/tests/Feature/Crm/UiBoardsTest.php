<?php

namespace Tests\Feature\Crm;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Deal;
use App\Models\DealStage;
use App\Models\Meeting;
use App\Models\Proposal;
use App\Services\Sales\Numbers;
use App\Support\BoardColors;
use App\Support\Navigation;
use App\Support\Settings;

/**
 * Интерфейс: цвета колонок Kanban (настройка, права, аудит), Kanban по умолчанию на страницах
 * «Лиды»/«Сделки», меню без встреч/КП/договоров, разделы встреч/КП/договоров в карточке клиента.
 */
class UiBoardsTest extends CrmTestCase
{
    public function test_board_colors_page_and_save_with_audit(): void
    {
        $this->actingAsUser($this->ceo)->get('/settings/board-colors')->assertOk()
            ->assertSee(t('boardColors.smm'))->assertSee(t('boardColors.tasks'))->assertSee(BoardColors::DEFAULTS['smm']['IDEA']);

        $this->actingAsUser($this->ceo)->put('/settings/board-colors/smm', ['colors' => ['IDEA' => '#F472B6', 'PUBLISHED' => '#2dd4bf']])
            ->assertSessionHasNoErrors()->assertRedirect();
        Settings::forget();
        $colors = BoardColors::for('smm');
        $this->assertSame('#f472b6', $colors['IDEA']);
        $this->assertSame('#2dd4bf', $colors['PUBLISHED']);
        $this->assertSame(BoardColors::DEFAULTS['smm']['APPROVED'], $colors['APPROVED']);
        $log = AuditLog::where('action', 'settings.board_colors')->latest('created_at')->firstOrFail();
        $this->assertEquals(['old' => BoardColors::DEFAULTS['smm']['IDEA'], 'new' => '#f472b6'], $log->changes['smm.IDEA']);
        $this->assertSame($this->ceo->id, $log->actor_id);

        // Цвет попадает в колонку доски SMM
        $this->actingAsUser($this->ceo)->get('/smm?view=kanban')->assertOk()->assertSee('--col: #f472b6', false);

        // Без изменений — без записи аудита
        $count = AuditLog::where('action', 'settings.board_colors')->count();
        $this->actingAsUser($this->ceo)->put('/settings/board-colors/smm', ['colors' => ['IDEA' => '#f472b6']])->assertSessionHasNoErrors();
        $this->assertSame($count, AuditLog::where('action', 'settings.board_colors')->count());
    }

    public function test_board_colors_validation(): void
    {
        foreach (['red', '#12345', '#GGGGGG', 'f472b6', '#f472b6;x'] as $bad) {
            $this->actingAsUser($this->ceo)->putJson('/settings/board-colors/tasks', ['colors' => ['TODO' => $bad]])
                ->assertStatus(422)->assertJsonValidationErrors('colors.TODO');
        }
        // Неизвестный статус и неизвестная доска
        $this->actingAsUser($this->ceo)->putJson('/settings/board-colors/tasks', ['colors' => ['NOPE' => '#ffffff']])->assertStatus(422);
        $this->actingAsUser($this->ceo)->putJson('/settings/board-colors/other', ['colors' => ['TODO' => '#ffffff']])->assertNotFound();
        $this->actingAsUser($this->ceo)->putJson('/settings/board-colors/tasks', ['colors' => ['DONE' => '#22C55E']])
            ->assertOk()->assertJsonPath('colors.DONE', '#22c55e');
        $this->assertSame(0, AuditLog::where('action', 'settings.board_colors')->where('changes', 'like', '%TODO%')->count());
    }

    public function test_board_colors_permissions(): void
    {
        foreach ([$this->manager, $this->executor] as $u) {
            $this->actingAsUser($u)->get('/settings/board-colors')->assertForbidden();
            $this->actingAsUser($u)->put('/settings/board-colors/smm', ['colors' => ['IDEA' => '#ffffff']])->assertForbidden();
        }
        $this->assertSame(BoardColors::DEFAULTS['smm']['IDEA'], BoardColors::for('smm')['IDEA']);
        // Вкладка в настройках — тем, у кого есть доступ
        $this->actingAsUser($this->ceo)->get('/settings')->assertOk()->assertSee('/settings/board-colors', false);
    }

    public function test_leads_page_shows_kanban_by_default_and_list_on_request(): void
    {
        $lead = $this->newLead($this->manager, ['contact_name' => 'Kanban Person', 'company_name' => 'Kanban Co']);
        $page = $this->actingAsUser($this->manager)->get('/sales/leads')->assertOk()
            ->assertSee('data-testid="kanban"', false)->assertDontSee('<table class="table">', false)
            ->assertSee(t('ui.view.kanban'))->assertSee(t('ui.view.list'));
        // Данные доски — только этапы лидов, с карточкой лида
        $board = $page->viewData('board');
        $this->assertSame(['LEAD'], collect($board['columns'])->pluck('entity')->unique()->values()->all());
        $this->assertSame([$lead->id], collect($board['columns'])->flatMap(fn ($c) => collect($c['items'])->pluck('id'))->all());

        $this->actingAsUser($this->manager)->get('/sales/leads?view=list')->assertOk()
            ->assertDontSee('data-testid="kanban"', false)->assertSee('<table class="table">', false)->assertSee($lead->title);
        // Массовый выбор и передача лидов — в режиме списка
        $this->actingAsUser($this->rop)->get('/sales/leads?view=list')->assertOk()->assertSee(t('leads.bulk.selectPage'), false);
    }

    public function test_deals_page_shows_kanban_by_default_and_list_on_request(): void
    {
        [, $dealId] = $this->convert($this->newLead($this->manager), $this->rop, ['client_name' => 'Kanban Client', 'amount' => '100']);
        $deal = Deal::findOrFail($dealId);
        $board = $this->actingAsUser($this->manager)->get('/sales/deals')->assertOk()
            ->assertSee('data-testid="kanban"', false)->assertDontSee('<table class="table">', false)->viewData('board');
        $this->assertSame(['DEAL'], collect($board['columns'])->pluck('entity')->unique()->values()->all());
        $this->assertSame([$deal->id], collect($board['columns'])->flatMap(fn ($c) => collect($c['items'])->pluck('id'))->all());
        $this->actingAsUser($this->manager)->get('/sales/deals?view=list')->assertOk()
            ->assertDontSee('data-testid="kanban"', false)->assertSee('<table class="table">', false)->assertSee($deal->title);
    }

    public function test_kanban_data_respects_entity_filters_and_scope(): void
    {
        $mine = $this->newLead($this->manager, ['contact_name' => 'Alpha', 'company_name' => 'Alpha LLC']);
        $other = $this->newLead($this->otherManager, ['contact_name' => 'Beta', 'company_name' => 'Beta LLC', 'phone' => '+998907654321']);
        $titles = fn ($json) => collect($json['columns'])->flatMap(fn ($c) => collect($c['items'])->pluck('title'))->all();

        $json = $this->actingAsUser($this->manager)->getJson('/sales/pipeline/data?only=LEAD')->assertOk()->json();
        $this->assertSame(['LEAD'], collect($json['columns'])->pluck('entity')->unique()->values()->all());
        $this->assertSame([$mine->title], $titles($json));

        // Фильтр страницы (поиск) применяется к доске
        $json = $this->actingAsUser($this->ceo)->getJson('/sales/pipeline/data?only=LEAD&q=Beta')->assertOk()->json();
        $this->assertSame([$other->title], $titles($json));
        // Кому нет права на сделки — доска сделок 403
        $this->actingAsUser($this->executor)->getJson('/sales/pipeline/data?only=DEAL')->assertForbidden();
        $this->actingAsUser($this->manager)->getJson('/sales/pipeline/data?only=XX')->assertStatus(422);
    }

    public function test_lead_created_from_kanban_column_gets_that_stage(): void
    {
        $lead = $this->newLead($this->manager, ['stage' => 'CONTACTED']);
        $this->assertSame('CONTACTED', $lead->stage->code);
        $this->assertTrue(AuditLog::where('action', 'lead.stage_change')->where('entity_id', $lead->id)->exists());
        // Этапы за «воротами» (нужна встреча) — нельзя
        $this->actingAsUser($this->manager)->post('/sales/leads', $this->leadData(['stage' => 'MEETING_SCHEDULED', 'phone' => '+998901112233']))
            ->assertSessionHasErrors('stage');
        // На доске «+» — только в колонках без «ворот» и при праве создания
        $cols = collect($this->actingAsUser($this->manager)->getJson('/sales/pipeline/data?only=LEAD')->json('columns'))->pluck('canCreate', 'code');
        $this->assertSame(['NEW' => true, 'CONTACTED' => true, 'QUALIFICATION' => true, 'MEETING_SCHEDULED' => false, 'MEETING_DONE' => false], $cols->all());
        $cols = collect($this->actingAsUser($this->executor)->getJson('/sales/pipeline/data')->json('columns') ?? [])->pluck('canCreate')->unique()->values()->all();
        $this->assertNotContains(true, $cols);
    }

    public function test_navigation_has_no_meetings_proposals_contracts_but_routes_work(): void
    {
        $hrefs = collect(Navigation::for($this->as($this->ceo)))
            ->flatMap(fn ($s) => isset($s['children']) ? collect($s['children'])->pluck('href') : [$s['href']])->all();
        $this->assertContains('/sales/leads', $hrefs);
        foreach (['/sales/meetings', '/sales/proposals', '/sales/contracts'] as $href) {
            $this->assertNotContains($href, $hrefs);
            $this->actingAsUser($this->ceo)->get($href)->assertOk();
        }
        $this->actingAsUser($this->ceo)->get('/sales/meetings/board')->assertOk();
    }

    public function test_client_card_shows_meetings_proposals_and_contracts(): void
    {
        $client = Client::create(['name' => 'Card Client', 'type' => 'COMPANY', 'owner_id' => $this->manager->id, 'team_id' => $this->team1->id]);
        $deal = Deal::create(['title' => 'Card Deal', 'client_id' => $client->id, 'owner_id' => $this->manager->id, 'team_id' => $this->team1->id,
            'amount' => '1000', 'amount_uzs' => '1000', 'currency' => 'UZS', 'stage_id' => DealStage::where('code', 'NEED_DEFINED')->value('id'),
            'status' => 'OPEN', 'created_by_id' => $this->manager->id]);
        Meeting::create(['deal_id' => $deal->id, 'manager_id' => $this->manager->id, 'team_id' => $this->team1->id, 'type' => 'OFFLINE',
            'status' => 'SCHEDULED', 'starts_at' => now()->addDay(), 'duration_min' => 45, 'created_by_id' => $this->manager->id]);
        Proposal::create(['deal_id' => $deal->id, 'client_id' => $client->id, 'manager_id' => $this->manager->id, 'title' => 'Card Proposal',
            'status' => 'SENT', 'subtotal' => '1000', 'total' => '1000', 'total_uzs' => '1000', 'currency' => 'UZS', 'current_version' => 1]);
        $contract = Contract::create(['deal_id' => $deal->id, 'client_id' => $client->id, 'status' => 'DRAFT', 'amount' => '1000', 'amount_uzs' => '1000',
            'currency' => 'UZS', 'contract_date' => now()->toDateString(), 'created_by_id' => $this->manager->id])->refresh();

        $this->actingAsUser($this->ceo)->get('/clients/'.$client->id)->assertOk()
            ->assertSee('data-section="client-meetings"', false)->assertSee('data-section="client-proposals"', false)
            ->assertSee('data-section="client-contracts"', false)
            ->assertSee(t('crm.common.minutes', ['n' => 45]))->assertSee('Card Proposal')
            ->assertSee(Numbers::contract($contract->number))
            ->assertSee(route('crm.meetings.board'), false)->assertSee('/sales/deals/'.$deal->id.'#proposals', false);

        // Клиент чужого отдела — 404
        $this->actingAsUser($this->otherManager)->get('/clients/'.$client->id)->assertNotFound();
    }
}
