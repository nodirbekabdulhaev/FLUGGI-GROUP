<?php

namespace Tests\Feature\Dashboard;

use App\Models\AccAccount;
use App\Models\AccTransaction;
use App\Models\DealStage;
use App\Models\ExchangeRate;
use App\Models\FollowUp;
use App\Models\Meeting;
use App\Models\Payment;
use App\Models\SmmIdea;
use App\Models\Task;
use App\Models\Todo;
use App\Models\User;
use App\Services\Dashboard\AgendaService;
use App\Services\Dashboard\ChartsService;
use App\Services\Dashboard\WalletsService;
use App\Services\Finance\Period;
use App\Support\Lang;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Notifications\CommsFixtures;
use Tests\TestCase;

/** Главная: «Мой день» (область видимости, границы периодов по Ташкенту), кошельки, данные графиков. */
class DashboardHomeTest extends TestCase
{
    use CommsFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtures();
    }

    private function meeting(User $manager, CarbonImmutable|string $at, array $participants = [], array $attrs = []): Meeting
    {
        $lead = $this->lead($manager);
        $m = Meeting::create($attrs + ['lead_id' => $lead->id, 'manager_id' => $manager->id, 'team_id' => $manager->team_id, 'starts_at' => $at,
            'type' => 'OFFLINE', 'status' => 'SCHEDULED', 'created_by_id' => $manager->id]);
        foreach (array_unique([$manager->id, ...array_map(fn ($u) => $u->id, $participants)]) as $uid) {
            DB::table('meeting_participants')->insert(['id' => (string) Str::uuid(), 'meeting_id' => $m->id, 'user_id' => $uid]);
        }

        return $m;
    }

    private function todo(User $owner, $due, string $title = 'Дело'): Todo
    {
        return Todo::create(['title' => $title, 'owner_id' => $owner->id, 'creator_id' => $owner->id, 'due_at' => $due]);
    }

    private function agenda(User $u, string $range = 'day', ?string $who = null, ?string $date = null): array
    {
        $q = http_build_query(array_filter(['range' => $range, 'who' => $who, 'date' => $date]));

        return $this->actingAsUser($u)->getJson('/dashboard/agenda?'.$q)->assertOk()->json();
    }

    private function ids(array $agenda, string $kind): array
    {
        $ids = array_column(array_filter($agenda['items'], fn ($i) => $i['kind'] === $kind), 'id');
        sort($ids);

        return $ids;
    }

    private static function sorted(array $ids): array
    {
        sort($ids);

        return $ids;
    }

    public function test_agenda_scoping_by_role_and_participants(): void
    {
        $at = CarbonImmutable::now('Asia/Tashkent')->startOfDay()->addHours(15)->utc();
        $own = $this->meeting($this->manager, $at);
        $foreign = $this->meeting($this->otherManager, $at->addHour());
        $invited = $this->meeting($this->otherManager, $at->addHours(2), [$this->manager]);
        $cancelled = $this->meeting($this->manager, $at, [], ['status' => 'CANCELLED']);
        $myTodo = $this->todo($this->manager, $at);
        $otherTodo = $this->todo($this->otherManager, $at);

        // Менеджер: свои встречи и те, где он участник; чужих и отменённых нет; «все» ему недоступно
        $m = $this->agenda($this->manager);
        $this->assertSame(self::sorted([$own->id, $invited->id]), $this->ids($m, 'meeting'));
        $this->assertSame([$myTodo->id], $this->ids($m, 'todo'));
        $this->assertFalse($m['canSeeAll']);
        $this->assertSame('me', $this->agenda($this->manager, who: 'all')['who']);
        $this->assertSame(self::sorted([$own->id, $invited->id]), $this->ids($this->agenda($this->manager, who: 'all'), 'meeting'));
        $item = collect($m['items'])->firstWhere('id', $invited->id);
        $this->assertSame('/sales/leads/'.$invited->lead_id, $item['href']);
        $this->assertCount(2, $item['people']);
        $this->assertSame(t('crm.meetingStatus.SCHEDULED'), $item['badge']['text']);

        // Участник из другого отдела видит встречу, в которую его пригласили
        $this->assertSame(self::sorted([$foreign->id, $invited->id]), $this->ids($this->agenda($this->otherManager), 'meeting'));

        // CEO: «мои» — пусто, «все» — вся компания в области ALL
        $this->assertSame([], $this->ids($this->agenda($this->ceo), 'meeting'));
        $all = $this->agenda($this->ceo, who: 'all');
        $this->assertTrue($all['canSeeAll']);
        $this->assertSame(self::sorted([$own->id, $foreign->id, $invited->id]), $this->ids($all, 'meeting'));
        $this->assertSame(self::sorted([$myTodo->id, $otherTodo->id]), $this->ids($all, 'todo'));

        // РОП: «все» — только свой отдел
        $rop = $this->agenda($this->rop, who: 'all');
        $this->assertSame([$own->id], $this->ids($rop, 'meeting'));
        $this->assertSame([$myTodo->id], $this->ids($rop, 'todo'));

        // Исполнитель без meeting.read — встреч в повестке нет вовсе
        $this->assertNotContains('meeting', $this->agenda($this->executor)['kinds']);
        $this->assertContains('todo', $this->agenda($this->executor)['kinds']);
    }

    public function test_agenda_tasks_payments_follow_ups_and_overdue(): void
    {
        $s = $this->sale($this->manager, 'Alfa');
        $today = CarbonImmutable::now('Asia/Tashkent')->startOfDay();
        $task = Task::create(['project_id' => $s['project']->id, 'title' => 'Контент-план', 'assignee_id' => $this->manager->id, 'creator_id' => $this->rop->id, 'deadline' => $today->addHours(23)->utc()]);
        $late = Task::create(['project_id' => $s['project']->id, 'title' => 'Отчёт', 'assignee_id' => $this->manager->id, 'creator_id' => $this->rop->id, 'deadline' => $today->subDays(3)->utc()]);
        Task::create(['project_id' => $s['project']->id, 'title' => 'Чужая', 'assignee_id' => $this->executor->id, 'creator_id' => $this->rop->id, 'deadline' => $today->addHours(20)->utc()]);
        $pay = Payment::create(['deal_id' => $s['deal']->id, 'client_id' => $s['client']->id, 'amount' => '1000000', 'amount_uzs' => '1000000', 'type' => 'PARTIAL', 'method' => 'BANK',
            'status' => 'PENDING', 'due_date' => $today->toDateString(), 'created_by_id' => $this->manager->id]);
        $fu = FollowUp::create(['client_id' => $s['client']->id, 'owner_id' => $this->manager->id, 'kind' => 'CONTACT', 'due_date' => $today->toDateString()]);
        $oldTodo = $this->todo($this->manager, $today->subDays(2)->utc(), 'Просроченное');

        $a = $this->agenda($this->manager);
        $this->assertSame([$task->id], $this->ids($a, 'task'));
        $this->assertSame([$pay->id], $this->ids($a, 'payment'));
        $this->assertSame([$fu->id], $this->ids($a, 'followUp'));
        $overdue = collect($a['overdue']);
        $this->assertEqualsCanonicalizing([$late->id, $oldTodo->id], $overdue->pluck('id')->all());
        $this->assertTrue($overdue->every(fn ($i) => $i['overdue']));
        $this->assertEquals(['task' => 1, 'payment' => 1, 'followUp' => 1], array_intersect_key($a['counts'], ['task' => 1, 'payment' => 1, 'followUp' => 1]));

        // Чужой менеджер не видит ни оплаты, ни follow-up, ни задачи
        $o = $this->agenda($this->otherManager);
        $this->assertSame([[], [], []], [$this->ids($o, 'payment'), $this->ids($o, 'followUp'), $this->ids($o, 'task')]);
        $this->assertSame([], $o['overdue']);
    }

    public function test_agenda_smm_ideas_for_smm_users_only(): void
    {
        $date = CarbonImmutable::now('Asia/Tashkent')->toDateString();
        $idea = SmmIdea::create(['title' => 'Рилс', 'status' => 'SCHEDULED', 'author_id' => $this->ceo->id, 'assignee_id' => $this->ceo->id, 'planned_publish_date' => $date]);
        SmmIdea::create(['title' => 'Готово', 'status' => 'PUBLISHED', 'author_id' => $this->ceo->id, 'assignee_id' => $this->ceo->id, 'planned_publish_date' => $date]);
        $this->assertSame([$idea->id], $this->ids($this->agenda($this->ceo), 'smm'));
        $this->assertNotContains('smm', $this->agenda($this->manager)['kinds']);
    }

    public function test_agenda_range_boundaries_in_tashkent_time(): void
    {
        // 2026-11-06 20:00 UTC = 2026-11-07 01:00 по Ташкенту (суббота)
        $now = CarbonImmutable::parse('2026-11-06T20:00:00Z');
        $r = fn (string $range, ?string $date = null) => AgendaService::range($range, $date, $now);
        $iso = fn (CarbonImmutable $d) => $d->toIso8601ZuluString();
        $day = $r('day');
        $this->assertSame(['2026-11-07', '2026-11-06T19:00:00Z', '2026-11-07T19:00:00Z', '2026-11-06', '2026-11-08'], [$day['today'], $iso($day['from']), $iso($day['to']), $day['prev'], $day['next']]);
        $week = $r('week');
        $this->assertSame(['2026-11-02', '2026-11-01T19:00:00Z', '2026-11-08T19:00:00Z', '2026-10-26', '2026-11-09'], [$week['fromDate'], $iso($week['from']), $iso($week['to']), $week['prev'], $week['next']]);
        $month = $r('month');
        $this->assertSame(['2026-10-31T19:00:00Z', '2026-11-30T19:00:00Z', '2026-10-01', '2026-12-01'], [$iso($month['from']), $iso($month['to']), $month['prev'], $month['next']]);
        $year = $r('year', '2026-03-15');
        $this->assertSame(['2025-12-31T19:00:00Z', '2026-12-31T19:00:00Z', '2025-01-01', '2027-01-01'], [$iso($year['from']), $iso($year['to']), $year['prev'], $year['next']]);
        $this->assertSame('day', $r('bogus', 'not-a-date')['range']);
        $this->assertSame('2026-11-07', $r('day', '2026-02-31')['anchor']);

        // Встреча в 23:30 по Ташкенту — ещё этот день, в 00:30 — уже следующий
        $late = $this->meeting($this->manager, '2026-11-06T18:30:00Z');
        $next = $this->meeting($this->manager, '2026-11-06T19:30:00Z');
        $d6 = $this->agenda($this->manager, 'day', date: '2026-11-06');
        $this->assertSame([$late->id], $this->ids($d6, 'meeting'));
        $this->assertSame('23:30', $d6['items'][0]['time']);
        $this->assertSame([$next->id], $this->ids($this->agenda($this->manager, 'day', date: '2026-11-07'), 'meeting'));
        $this->assertSame(self::sorted([$late->id, $next->id]), $this->ids($this->agenda($this->manager, 'week', date: '2026-11-04'), 'meeting'));
        $this->assertSame([], $this->ids($this->agenda($this->manager, 'week', date: '2026-11-09'), 'meeting'));
        $this->assertSame(['2026-11-01', '2026-11-30'], array_values(array_intersect_key($this->agenda($this->manager, 'month', date: '2026-11-20'), ['from' => 1, 'to' => 1])));
        $y = $this->agenda($this->manager, 'year', date: '2026-01-10');
        $this->assertSame(['2026-01-01', '2026-12-31', 2], [$y['from'], $y['to'], count($this->ids($y, 'meeting'))]);
        $this->assertSame([], $this->ids($this->agenda($this->manager, 'year', date: '2027-01-01'), 'meeting'));
    }

    public function test_wallets_only_with_accounting_read(): void
    {
        ExchangeRate::create(['currency' => 'USD', 'date' => now()->toDateString(), 'rate_to_uzs' => '12000']);
        $bank = AccAccount::create(['name' => 'Kapitalbank', 'kind' => 'BANK', 'bank_name' => 'Kapitalbank', 'currency' => 'UZS', 'opening_balance' => '1000000', 'is_active' => true]);
        AccAccount::create(['name' => 'Карта USD', 'kind' => 'CARD', 'currency' => 'USD', 'opening_balance' => '100', 'is_active' => true]);
        $closed = AccAccount::create(['name' => 'Закрытый', 'kind' => 'CASH', 'currency' => 'UZS', 'opening_balance' => '999', 'is_active' => false]);
        // Приход в кассу в «Приходы с начала года» не входит — только банковские счета
        AccTransaction::create(['account_id' => $closed->id, 'direction' => 'IN', 'category' => 'OTHER_INCOME', 'amount' => '700000', 'date' => CarbonImmutable::now('Asia/Tashkent')->toDateString()]);
        AccTransaction::create(['account_id' => $bank->id, 'direction' => 'IN', 'category' => 'CLIENT_PAYMENT', 'amount' => '500000', 'date' => CarbonImmutable::now('Asia/Tashkent')->toDateString()]);

        $w = WalletsService::get($this->as($this->ceo));
        $this->assertSame(['Kapitalbank', 'Карта USD'], array_column($w['accounts'], 'name'));
        $this->assertSame(['1500000.00', '1200000.00'], array_column($w['accounts'], 'balanceUzs'));
        $this->assertSame(['2700000.00', false, '500000.00'], [$w['totalUzs'], $w['missingRate'], $w['ytd']['income']]);
        $this->assertNull(WalletsService::get($this->as($this->manager)));

        $this->actingAsUser($this->ceo)->get('/dashboard')->assertOk()->assertSee('data-testid="wallets"', false)->assertSee(money('2700000.00'));
        $accountant = $this->user('ACCOUNTANT');
        $this->actingAsUser($accountant)->get('/dashboard')->assertOk()->assertSee('data-testid="wallets"', false);
        $this->actingAsUser($this->manager)->get('/dashboard')->assertOk()->assertDontSee('data-testid="wallets"', false)->assertDontSee('Kapitalbank');
    }

    public function test_chart_data_matches_seeded_records_and_scope(): void
    {
        $this->sale($this->manager, 'Alfa', '5000000');
        $this->sale($this->otherManager, 'Beta', '3000000');
        $client = $this->client($this->manager);
        $this->deal($client, $this->manager, '2000000');
        $this->deal($client, $this->manager, '4000000', ['stage_id' => DealStage::where('code', 'NEGOTIATION')->value('id')]);
        $now = CarbonImmutable::now();
        $month = $now->setTimezone('Asia/Tashkent')->format('Y-m');

        $this->as($this->ceo);
        $sales = ChartsService::sales12($now);
        $this->assertCount(12, $sales['months']);
        $this->assertSame($month, $sales['months'][11]);
        $this->assertSame(['8000000.00', '8000000.00', '8000000.00', false], [$sales['revenue'][11], $sales['collected'][11], $sales['revenueTotal'], $sales['empty']]);
        $this->assertSame('0.00', $sales['revenue'][0]);
        $stages = collect(ChartsService::stages()['rows'])->keyBy('name');
        $this->assertSame(2, ChartsService::stages()['total']);
        $need = DealStage::where('code', 'NEED_DEFINED')->first();
        $this->assertSame([1, '2000000.00', 50.0], [$stages[$need->name_ru]['count'], $stages[$need->name_ru]['amountUzs'], (float) $stages[$need->name_ru]['pct']]);
        $period = Period::resolve('month');
        $this->assertSame(2, ChartsService::sources($period)['total']);

        // Менеджер — только свои продажи и сделки
        $this->as($this->manager);
        $this->assertSame('5000000.00', ChartsService::sales12($now)['revenue'][11]);
        $this->assertSame(1, ChartsService::sources($period)['total']);
        $this->as($this->otherManager);
        $this->assertSame([0, '3000000.00'], [ChartsService::stages()['total'], ChartsService::sales12($now)['revenueTotal']]);

        // SMM — опубликованные ролики по месяцам; у менеджера блока нет
        SmmIdea::create(['title' => 'Ролик', 'status' => 'PUBLISHED', 'author_id' => $this->ceo->id, 'assignee_id' => $this->ceo->id, 'published_at' => now()]);
        $charts = ChartsService::get($this->as($this->ceo), $period, $now);
        $this->assertSame([1, 1], [$charts['smm']['counts'][11], $charts['smm']['total']]);
        $this->assertNotNull($charts['expenses']);
        $mgr = ChartsService::get($this->as($this->manager), $period, $now);
        $this->assertNull($mgr['smm']);
        $this->assertNull($mgr['expenses']);
        $this->assertNull(ChartsService::get($this->as($this->executor), $period, $now)['sales']);
    }

    public function test_plan_ring_uses_revenue_targets(): void
    {
        $this->sale($this->manager, 'Alfa', '5000000');
        DB::table('kpi_targets')->insert(['id' => (string) Str::uuid(), 'user_id' => $this->manager->id, 'period' => CarbonImmutable::now('Asia/Tashkent')->format('Y-m'),
            'metric' => 'REVENUE', 'target_value' => '20000000', 'currency' => 'UZS', 'set_by_id' => $this->ceo->id, 'updated_at' => now()]);
        $this->as($this->manager);
        $plan = ChartsService::planFact(CarbonImmutable::now());
        $this->assertSame(['20000000.00', '5000000.00', '25.0'], [$plan['plan'], $plan['fact'], $plan['pct']]);
        $this->as($this->otherManager);
        $this->assertNull(ChartsService::planFact(CarbonImmutable::now()));
    }

    public function test_dashboard_and_analytics_render_in_all_languages(): void
    {
        $this->sale($this->manager, 'Alfa');
        $this->meeting($this->manager, CarbonImmutable::now('Asia/Tashkent')->startOfDay()->addHours(16)->utc());
        foreach (['ru', 'uz', 'en'] as $loc) {
            foreach ([$this->ceo, $this->rop, $this->manager, $this->executor] as $u) {
                $u->update(['locale' => $loc]);
                $this->actingAsUser($u)->get('/dashboard')->assertOk()
                    ->assertSee(Lang::get('home.agenda.title', [], $loc))
                    ->assertSee('data-testid="agenda"', false);
            }
            $this->actingAsUser($this->ceo)->get('/dashboard')->assertSee(Lang::get('home.charts.salesTitle', [], $loc))->assertSee('x-data="chart(', false);
            foreach (['sales', 'funnel', 'sources', 'forecast'] as $tab) {
                $this->actingAsUser($this->ceo)->get('/analytics?tab='.$tab.'&period=month')->assertOk()->assertSee('x-data="chart(', false);
            }
            $this->actingAsUser($this->manager)->getJson('/dashboard/agenda?range=week')->assertOk()->assertJsonPath('range', 'week');
        }
        $this->flushSession();
        $this->get('/dashboard/agenda')->assertRedirect();
    }
}
