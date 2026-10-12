<?php

namespace Tests\Feature\Analytics;

use App\Http\Controllers\Analytics\AnalyticsController;
use App\Models\AuditLog;
use App\Models\Contract;
use App\Models\Expense;
use App\Models\LeadSource;
use App\Models\LossReason;
use App\Models\Meeting;
use App\Models\Notification;
use App\Models\Payment;
use App\Models\Proposal;
use App\Services\Analytics\AnalyticsService;
use App\Services\Analytics\ClientInsights;
use App\Services\Analytics\Period;
use App\Services\Analytics\SearchService;
use App\Services\Automation\Scheduler;
use App\Support\Format;
use App\Support\Lang;
use App\Support\Settings;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Feature\Notifications\CommsFixtures;
use Tests\TestCase;

class AnalyticsTest extends TestCase
{
    use CommsFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtures();
    }

    private function svc($user): AnalyticsService
    {
        $this->as($user);

        return new AnalyticsService;
    }

    public function test_sales_funnel_sources_losses_and_scopes(): void
    {
        $s = $this->sale($this->manager, 'Alfa');
        Meeting::create(['lead_id' => $s['lead']->id, 'manager_id' => $this->manager->id, 'starts_at' => now(), 'type' => 'OFFLINE', 'status' => 'DONE', 'created_by_id' => $this->manager->id]);
        Proposal::create(['deal_id' => $s['deal']->id, 'client_id' => $s['client']->id, 'manager_id' => $this->manager->id, 'title' => 'КП', 'status' => 'SENT', 'sent_at' => now()]);
        $src2 = LeadSource::where('code', 'INSTAGRAM')->value('id');
        $this->lead($this->manager, ['source_id' => $src2]);
        $reason = LossReason::first();
        $this->lead($this->manager, ['source_id' => $src2, 'status' => 'LOST', 'loss_reason_id' => $reason->id, 'closed_at' => now()]);
        $this->lead($this->otherManager);
        [$from, $to] = Period::resolve('month');

        $sales = $this->svc($this->ceo)->sales($from, $to);
        $this->assertSame(['revenue' => '5000000.00', 'collected' => '5000000.00', 'won' => 1, 'leads' => 4, 'avgCheck' => '5000000.00'], $sales['totals']);
        $this->assertSame('day', $sales['granularity']);
        $day = collect($sales['points'])->firstWhere('key', Format::today()->toDateString());
        $this->assertSame(['5000000.00', '5000000.00', 1, 4], [$day['revenue'], $day['collected'], $day['won'], $day['leads']]);
        $this->assertNull($sales['change']['revenue']);

        $funnel = $this->svc($this->ceo)->funnel($from, $to);
        $this->assertSame([4, 1, 1, 1, 1, 1], array_column($funnel, 'count'));
        $this->assertEquals([25, 25], [$funnel[1]['fromPrev'], $funnel[1]['fromStart']]);
        $this->assertEquals(['paid', 100, 25], [$funnel[5]['key'], $funnel[5]['fromPrev'], $funnel[5]['fromStart']]);

        $sources = $this->svc($this->ceo)->breakdown($from, $to, 'source');
        $this->assertSame(['leads' => 2, 'won' => 1, 'revenue' => '5000000.00'], array_intersect_key($sources[0], ['leads' => 1, 'won' => 1, 'revenue' => 1]));
        $this->assertEquals(50, $sources[0]['conversion']);
        $this->assertSame(2, collect($sources)->firstWhere('id', $src2)['leads']);

        $losses = $this->svc($this->ceo)->losses($from, $to);
        $this->assertSame([['id' => $reason->id, 'name' => $reason->name_ru, 'leads' => 1, 'deals' => 0, 'amount' => '0.00']], $losses);

        // Менеджер — только своё, РОП — свой отдел; фильтр по сотруднику не расширяет область
        $this->assertSame(3, $this->svc($this->manager)->sales($from, $to)['totals']['leads']);
        $other = $this->svc($this->otherManager)->sales($from, $to)['totals'];
        $this->assertSame([1, '0.00'], [$other['leads'], $other['revenue']]);
        $this->assertSame(3, $this->svc($this->rop)->funnel($from, $to)[0]['count']);
        $this->as($this->manager);
        $this->assertSame(0, (new AnalyticsService(null, $this->otherManager->id))->sales($from, $to)['totals']['leads']);
        $this->actingAsUser($this->executor)->get('/analytics')->assertForbidden();

        foreach (['ru', 'uz'] as $loc) {
            $this->ceo->update(['locale' => $loc]);
            foreach (AnalyticsController::TABS as $tab) {
                $this->actingAsUser($this->ceo)->get('/analytics?tab='.$tab.'&period=month')->assertOk()->assertSee(Lang::get('analytics.tabs.'.$tab, [], $loc));
            }
        }
        $this->actingAsUser($this->manager)->get('/analytics?tab=sales&period=custom&from=2026-01-01&to=2026-01-31')->assertOk();
    }

    public function test_forecast_without_double_count(): void
    {
        $s = $this->sale($this->manager, 'Alfa');
        $deal = $this->deal($s['client'], $this->manager, '10000000', ['probability_override' => 40]);
        $deal2 = $this->deal($s['client'], $this->manager, '3000000');
        Payment::create(['deal_id' => $deal2->id, 'client_id' => $s['client']->id, 'amount' => '3000000', 'amount_uzs' => '3000000', 'type' => 'PREPAYMENT', 'method' => 'BANK', 'created_by_id' => $this->manager->id]);
        $f = $this->svc($this->ceo)->forecast();
        $this->assertCount(3, $f['months']);
        $this->assertSame(['collected' => '5000000.00', 'scheduled' => '3000000.00', 'weighted' => '4000000.00', 'total' => '12000000.00', 'plan' => null],
            array_intersect_key($f['months'][0], ['collected' => 1, 'scheduled' => 1, 'weighted' => 1, 'total' => 1, 'plan' => 1]));
        $this->assertSame(['0.00', '0.00', '0.00'], [$f['months'][1]['collected'], $f['months'][1]['scheduled'], $f['months'][1]['weighted']]);
        $this->assertSame(['13000000.00', 2], [$f['pipeline'], $f['openDeals']]);
    }

    public function test_client_health_ltv_and_risk_notification(): void
    {
        $s = $this->sale($this->manager, 'Alfa');
        $silent = $this->client($this->manager, ['name' => 'Silent LLC']);
        $silent->forceFill(['created_at' => now()->subDays(400)])->save();
        $risky = $this->client($this->manager, ['name' => 'Risky LLC']);
        $risky->forceFill(['created_at' => now()->subDays(70)])->save();
        $d = $this->deal($risky, $this->manager, '1000000');
        $d->forceFill(['created_at' => now()->subDays(70)])->save();
        Payment::create(['deal_id' => $d->id, 'client_id' => $risky->id, 'amount' => '1000000', 'amount_uzs' => '1000000', 'type' => 'PREPAYMENT', 'method' => 'BANK', 'due_date' => '2020-01-01', 'created_by_id' => $this->manager->id]);
        // Активные проекты у Alfa и сделка у Risky — «в работе»; закроем проект Alfa, чтобы проверить расчёт
        $this->as($this->ceo);
        $list = ClientInsights::list('health', null, 1);
        $by = collect($list['items'])->keyBy('id');
        $this->assertSame(3, $list['total']);
        $this->assertSame('RISK', $by[$risky->id]['health']['level']);
        $this->assertSame(['Нет контакта 70 дн.', 'Просрочено оплат: 1'], $by[$risky->id]['health']['reasons']);
        $this->assertSame($risky->id, $list['items'][0]['id']);
        $this->assertSame('LOST', $by[$silent->id]['health']['level']);
        $this->assertSame(['5000000.00', 1, '5000000.00'], [$by[$s['client']->id]['ltv'], $by[$s['client']->id]['paidDeals'], $by[$s['client']->id]['avgCheck']]);
        $this->assertSame('HEALTHY', $by[$s['client']->id]['health']['level']);
        $this->assertSame(['HEALTHY' => 1, 'ATTENTION' => 0, 'RISK' => 1, 'LOST' => 1], $list['summary']['byHealth']);
        $this->assertSame('5000000.00', $list['summary']['avgLtv']);
        $this->assertSame(1, ClientInsights::list('ltv', 'RISK', 1)['total']);
        $this->assertSame('5000000.00', $this->actingAsUser($this->manager)->getJson('/clients/'.$s['client']->id.'/insight')->assertOk()->json('ltv'));
        $this->actingAsUser($this->otherManager)->getJson('/clients/'.$s['client']->id.'/insight')->assertNotFound();
        $this->as($this->otherManager);
        $this->assertSame(0, ClientInsights::list('ltv', null, 1)['total']);
        $this->assertSame(['Muddati oʻtgan toʻlovlar: 1'], (function () {
            app()->setLocale('uz');

            return ClientInsights::reasonTexts(['Просрочено оплат: 1']);
        })());
        app()->setLocale('ru');

        $this->assertNull(Scheduler::runNow('client-health')->error);
        $this->assertSame('RISK', $risky->fresh()->health);
        $this->assertStringContainsString('Risky LLC', Notification::where('user_id', $this->manager->id)->where('type', 'client.risk')->value('body'));
        Scheduler::runNow('client-health');
        $this->assertSame(1, Notification::where('type', 'client.risk')->count());
    }

    public function test_global_search_within_rights(): void
    {
        $this->assertSame(12, SearchService::parseNumber('D-00012'));
        $this->assertSame(7, SearchService::parseNumber('ДГ-7'));
        $this->assertSame(42, SearchService::parseNumber('42'));
        $this->assertNull(SearchService::parseNumber('Alfa'));
        $s = $this->sale($this->manager, 'Alfa Market');
        $hits = $this->actingAsUser($this->manager)->getJson('/search?q=alfa')->assertOk()->json();
        $this->assertEqualsCanonicalizing(['client', 'lead', 'deal', 'project', 'contract'], array_values(array_unique(array_column($hits, 'kind'))));
        $this->assertSame('/sales/deals/'.$s['deal']->id, collect($hits)->firstWhere('kind', 'deal')['link']);
        $this->assertContains($s['deal']->id, array_column($this->getJson('/search?q=D-'.$s['deal']->number)->json(), 'id'));
        $this->assertContains('lead', array_column($this->getJson('/search?q=901234500')->json(), 'kind'));
        $this->actingAsUser($this->otherManager)->getJson('/search?q=alfa')->assertExactJson([]);
        $u = $this->actingAsUser($this->ceo)->getJson('/search?q=manager2')->json();
        $this->assertSame(['user', '/team/employees?q=manager2%40test.uz'], [$u[0]['kind'], $u[0]['link']]);
        $this->getJson('/search?q=a')->assertStatus(422);
    }

    public function test_exports_rights_scope_and_audit(): void
    {
        $s = $this->sale($this->manager, '=HYPERLINK("x")');
        $this->lead($this->otherManager, ['title' => 'Other']);
        $this->actingAsUser($this->manager)->get('/exports/leads')->assertForbidden();

        $csv = $this->actingAsUser($this->rop)->get('/exports/leads?format=csv')->assertOk();
        $this->assertStringContainsString('text/csv', $csv->headers->get('Content-Type'));
        $this->assertMatchesRegularExpression('/attachment; filename="fluggi-leads-\d{4}-\d{2}-\d{2}\.csv"/', $csv->headers->get('Content-Disposition'));
        $text = $csv->getContent();
        $this->assertStringStartsWith("\u{FEFF}", $text);
        $lines = explode("\r\n", trim($text));
        $this->assertStringContainsString('Номер;Название;Контакт', $lines[0]);
        $this->assertCount(2, $lines);
        $this->assertStringContainsString('"\'=HYPERLINK(""x"") лид"', $lines[1]);
        $csv->assertHeader('X-Export-Rows', '1');

        $x = $this->actingAsUser($this->ceo)->get('/exports/payments?format=xlsx')->assertOk();
        $tmp = tempnam(sys_get_temp_dir(), 'x').'.xlsx';
        file_put_contents($tmp, $x->getContent());
        $ws = IOFactory::load($tmp)->getSheetByName('Оплаты');
        @unlink($tmp);
        $this->assertSame('Номер', $ws->getCell('A1')->getValue());
        $this->assertSame(2, $ws->getHighestRow());
        $this->assertEquals(5000000, $ws->getCell('J2')->getValue());
        $this->assertSame('Оплачено', $ws->getCell('F2')->getValue());

        foreach (['deals', 'clients', 'expenses', 'projects', 'tasks'] as $e) {
            $this->get('/exports/'.$e.'?format=csv&period=month')->assertOk();
        }
        $this->get('/exports/users')->assertStatus(422);
        $this->assertSame(7, AuditLog::where('action', 'export.run')->count());
        $this->ceo->update(['locale' => 'uz']);
        $this->assertStringContainsString('Raqam;Nomi', $this->actingAsUser($this->ceo)->get('/exports/leads?format=csv')->getContent());
    }

    public function test_accountant_package(): void
    {
        $s = $this->sale($this->manager, 'Alfa');
        Contract::create(['deal_id' => $s['deal']->id, 'client_id' => $s['client']->id, 'contract_date' => now()->toDateString(), 'amount' => '2000000',
            'amount_uzs' => '2000000', 'status' => 'SIGNED', 'signed_at' => now(), 'created_by_id' => $this->manager->id]);
        $today = Format::today()->toDateString();
        foreach ([['PROJECT', 'DESIGN', '700000', $s['project']->id], ['COMPANY', 'ADS', '300000', null], ['COMPANY', 'SERVICES', '200000', null]] as [$scope, $cat, $amount, $pid]) {
            Expense::create(['scope' => $scope, 'project_id' => $pid, 'category' => $cat, 'amount' => $amount, 'exchange_rate' => 1, 'amount_uzs' => $amount, 'expense_date' => $today, 'created_by_id' => $this->ceo->id]);
        }
        Settings::save('company', ['name' => 'ООО «Fluggi»', 'inn' => '123456789', 'taxRegime' => 'IT_PARK'] + Settings::company());
        $year = (int) substr($today, 0, 4);
        $this->actingAsUser($this->rop)->get('/exports/accountant-package?year='.$year)->assertForbidden();
        $this->actingAsUser($this->ceo)->getJson('/exports/accountant-package?year=1999')->assertStatus(422);
        $res = $this->get('/exports/accountant-package?year='.$year)->assertOk();
        $this->assertSame('attachment; filename="fluggi-buhgalter-'.$year.'.xlsx"', $res->headers->get('Content-Disposition'));
        $tmp = tempnam(sys_get_temp_dir(), 'a').'.xlsx';
        file_put_contents($tmp, $res->getContent());
        $book = IOFactory::load($tmp);
        @unlink($tmp);
        $this->assertSame(['Пояснения', 'Сводка (форма 2)', 'Договоры', 'Оплаты', 'Прочие поступления', 'Расчёты с клиентами 31.12', 'Расходы', 'Зарплата', 'Комиссии', 'Проекты'], $book->getSheetNames());
        $value = function ($sheet, $label, $col = 'B', $labelCol = 'A') use ($book) {
            foreach ($book->getSheetByName($sheet)->getRowIterator() as $row) {
                $r = $row->getRowIndex();
                if (trim((string) $book->getSheetByName($sheet)->getCell($labelCol.$r)->getValue()) === $label) {
                    return $book->getSheetByName($sheet)->getCell($col.$r)->getValue();
                }
            }
        };
        $this->assertSame('ООО «Fluggi»', $value('Пояснения', 'Компания'));
        $this->assertSame('Резидент IT-Park', $value('Пояснения', 'Налоговый режим'));
        $this->assertEquals(7000000, $value('Пояснения', 'Подписано договоров, UZS'));
        $this->assertEquals(5000000, $value('Пояснения', 'Получено оплат (за вычетом возвратов), UZS'));
        $this->assertEquals(700000, $value('Пояснения', 'Расходы на проекты (себестоимость), UZS'));
        $this->assertEquals(500000, $value('Пояснения', 'Расходы компании, UZS'));
        $this->assertEquals(2000000, $value('Пояснения', 'Дебиторская задолженность клиентов на 31.12, UZS'));
        $this->assertEquals(5000000, $value('Сводка (форма 2)', 'Чистая выручка (по оплатам)', 'C', 'B'));
        $this->assertEquals(4300000, $value('Сводка (форма 2)', 'Валовая прибыль', 'C', 'B'));
        $this->assertEquals(300000, $value('Сводка (форма 2)', 'расходы по реализации (реклама компании)', 'C', 'B'));
        $this->assertEquals(3800000, $value('Сводка (форма 2)', 'Прибыль от основной деятельности', 'C', 'B'));
        $this->assertSame('Себестоимость услуг (9130)', $book->getSheetByName('Расходы')->getCell('E2')->getValue());
        $this->assertEquals(2000000, $book->getSheetByName('Расчёты с клиентами 31.12')->getCell('E2')->getValue());
        $this->assertSame(1, AuditLog::where('entity_type', 'accountant_package')->count());
    }
}
