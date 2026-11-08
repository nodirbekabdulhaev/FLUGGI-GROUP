<?php

namespace Tests\Feature\Accounting;

use App\Models\AccPayroll;
use App\Models\AccPerson;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use App\Services\Accounting\Accounting;
use App\Services\Accounting\TaxesService;
use App\Services\Accounting\TaxPayrollService;
use App\Services\Accounting\TaxReport;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use Tests\TestCase;

/**
 * Отчёт в налоговую (приложение 4 к расчёту НДФЛ): доход и НДФЛ с начала года и за месяц, льготы IT Park,
 * история оклада, ведомость как источник, увольнение, файл по шаблону, права. Все люди и ПИНФЛ — вымышленные.
 */
class TaxReportTest extends TestCase
{
    private User $accountant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->accountant = $this->user('ACCOUNTANT');
    }

    private function person(array $attrs): AccPerson
    {
        return AccPerson::create($attrs + ['position' => 'Менеджер', 'is_active' => true]);
    }

    private function row(array $report, string $personId): ?array
    {
        return collect($report['rows'])->firstWhere('personId', $personId);
    }

    /** L, M, N, O, P, Q, R */
    private function cols(?array $r): array
    {
        $this->assertNotNull($r);
        $b = $r['benefits'][0] ?? ['code' => null, 'ytd' => null, 'month' => null];

        return [$r['ytdIncome'], $r['monthIncome'], $r['ytdNdfl'], $r['monthNdfl'], $b['code'], $b['ytd'], $b['month']];
    }

    public function test_it_park_example_scenario_year_to_date(): void
    {
        $this->as($this->accountant);
        // Работает с прошлого года: 4 500 000 × 9 месяцев
        $a = $this->person(['full_name' => 'Testov Ali', 'passport_name' => 'TESTOV ALI VALIYEVICH', 'pinfl' => '10000000000001', 'monthly_salary' => '4500000', 'hired_at' => '2025-05-01', 'position' => 'Директор']);
        // Принят 01.03, уволен 31.03: доход только в марте
        $b = $this->person(['full_name' => 'SINOV BOBUR', 'pinfl' => '10000000000002', 'monthly_salary' => '1300000', 'hired_at' => '2026-03-01', 'fired_at' => '2026-03-31', 'is_active' => false]);
        // Принят 01.06: июнь…сентябрь
        $c = $this->person(['full_name' => 'NAMUNA KAMOLA', 'pinfl' => '10000000000003', 'monthly_salary' => '1400000', 'hired_at' => '2026-06-01', 'workplace_type' => 3]);
        // Принят 16.09: 15 из 30 дней
        $d = $this->person(['full_name' => 'YANGI DILSHOD', 'pinfl' => '10000000000004', 'monthly_salary' => '6000000', 'hired_at' => '2026-09-16']);
        // Уволен до начала года и неактивный без даты увольнения — не попадают
        $old = $this->person(['full_name' => 'ESKI XODIM', 'monthly_salary' => '3000000', 'hired_at' => '2024-01-01', 'fired_at' => '2025-12-31']);
        $off = $this->person(['full_name' => 'OCHIRILGAN', 'monthly_salary' => '3000000', 'is_active' => false]);
        // Принят после отчётного месяца
        $future = $this->person(['full_name' => 'KELAJAK', 'monthly_salary' => '3000000', 'hired_at' => '2026-10-01']);

        $r = TaxReport::build('2026-09');
        $this->assertSame(['40500000.00', '4500000.00', '3037500.00', '337500.00', '103848', '15187500.00', '1687500.00'], $this->cols($this->row($r, $a->id)));
        $this->assertSame(['1300000.00', '0.00', '97500.00', '0.00', '103848', '487500.00', '0.00'], $this->cols($this->row($r, $b->id)));
        $this->assertSame(['5600000.00', '1400000.00', '420000.00', '105000.00', '103848', '2100000.00', '525000.00'], $this->cols($this->row($r, $c->id)));
        $this->assertSame(['3000000.00', '3000000.00', '225000.00', '225000.00', '103848', '1125000.00', '1125000.00'], $this->cols($this->row($r, $d->id)));
        foreach ([$old, $off, $future] as $p) {
            $this->assertNull($this->row($r, $p->id), $p->full_name);
        }
        $this->assertCount(4, $r['rows']);
        // Кто не попал и почему (уволенный до начала года не показывается)
        $this->assertSame([[$future->id, 'hiredLater'], [$off->id, 'inactive']], collect($r['skipped'])->sortBy('reason')->map(fn ($x) => [$x['personId'], $x['reason']])->values()->all());

        // Остальные столбцы: имя как в паспорте, дата рабочего места, коды
        $ra = $this->row($r, $a->id);
        $this->assertSame(['TESTOV ALI VALIYEVICH', 'Директор', '10000000000001', '01.01.2026', 1, 1, 1, '1', 1], [$ra['name'], $ra['position'], $ra['pinfl'], $ra['workplaceDate'], $ra['resident'], $ra['status'], $ra['contract'], $ra['rate'], $ra['workplaceType']]);
        $rb = $this->row($r, $b->id);
        $this->assertSame(['01.03.2026', 2], [$rb['workplaceDate'], $rb['status']]);
        $this->assertSame(['01.06.2026', 3], [$this->row($r, $c->id)['workplaceDate'], $this->row($r, $c->id)['workplaceType']]);
        // Итоги
        $this->assertSame(['50400000.00', '8900000.00', '3780000.00', '667500.00'], [$r['totals']['ytdIncome'], $r['totals']['monthIncome'], $r['totals']['ytdNdfl'], $r['totals']['monthNdfl']]);
        $this->assertSame(['18900000.00', '3337500.00'], [$r['totals']['benefits'][0]['ytd'], $r['totals']['benefits'][0]['month']]);

        // Зарплата и налоги считают так же: 12 % × (начислено − 37,5 %) = 7,5 %
        TaxPayrollService::calculate('2026-09');
        $this->assertSame('337500.00', (string) AccPayroll::where('person_id', $a->id)->value('ndfl'));
        $this->assertSame('7.500', (string) AccPayroll::where('person_id', $a->id)->value('ndfl_rate'));
        $this->assertSame('667500.00', TaxesService::forPeriod('2026-09')['taxes']['TAX_NDFL']['due']);   // = НДФЛ месяца в отчёте
        $this->assertSame('7.5', Accounting::settings()['ndflRate']);
    }

    public function test_salary_change_affects_only_that_month_and_later(): void
    {
        $this->actingAsUser($this->accountant);
        $this->post('/accounting/people', ['full_name' => 'Ozgarish Erkin', 'passport_name' => 'ozgarish erkin', 'pinfl' => '20000000000001', 'position' => 'Менеджер',
            'monthly_salary' => '5 000 000', 'hired_at' => '2026-01-01', 'is_active' => 1, 'benefits_mode' => 'default'])->assertSessionHasNoErrors();
        $p = AccPerson::where('full_name', 'Ozgarish Erkin')->firstOrFail();
        $this->assertSame('OZGARISH ERKIN', $p->passport_name);
        $this->assertSame(['2026-01'], $p->salaries()->pluck('effective_from')->all());

        $this->put("/accounting/people/{$p->id}", ['full_name' => 'Ozgarish Erkin', 'pinfl' => '20000000000001', 'position' => 'Менеджер',
            'monthly_salary' => '8000000', 'salary_from' => '2026-07', 'hired_at' => '2026-01-01', 'is_active' => 1, 'benefits_mode' => 'default'])->assertSessionHasNoErrors();
        $p->refresh();
        $this->assertSame('8000000.00', (string) $p->monthly_salary);
        $this->assertSame(['2026-01' => '5000000.00', '2026-07' => '8000000.00'], $p->salaries()->pluck('amount', 'effective_from')->map(fn ($v) => (string) $v)->all());

        // Сентябрь: 5 млн × 6 + 8 млн × 3; июнь — по старому окладу
        $this->assertSame(['54000000.00', '8000000.00'], array_slice($this->cols($this->row(TaxReport::build('2026-09'), $p->id)), 0, 2));
        $this->assertSame(['30000000.00', '5000000.00'], array_slice($this->cols($this->row(TaxReport::build('2026-06'), $p->id)), 0, 2));
        $this->assertTrue(AuditLog::where('action', 'accounting.person.update')->where('entity_id', $p->id)->exists());

        // «Рассчитать месяц» тоже берёт оклад месяца
        $this->post('/accounting/payroll/calculate', ['period' => '2026-06'])->assertSessionHasNoErrors();
        $this->assertSame('5000000.00', (string) AccPayroll::where('person_id', $p->id)->where('period', '2026-06')->value('gross'));

        // Ошибочное изменение удаляется — снова 5 млн во всех месяцах
        $h = $p->salaries()->where('effective_from', '2026-07')->first();
        $this->delete("/accounting/people/{$p->id}/salaries/{$h->id}")->assertSessionHasNoErrors();
        $this->assertSame('5000000.00', (string) $p->refresh()->monthly_salary);
        $this->assertSame('45000000.00', $this->row(TaxReport::build('2026-09'), $p->id)['ytdIncome']);
        $last = $p->salaries()->first();
        $this->delete("/accounting/people/{$p->id}/salaries/{$last->id}")->assertSessionHas('error');

        // Человек, созданный без истории (до этой версии): смена оклада сохраняет прежний для прошлых месяцев
        $legacy = $this->person(['full_name' => 'Eski Karta', 'monthly_salary' => '2000000', 'hired_at' => '2025-01-01']);
        $this->put("/accounting/people/{$legacy->id}", ['full_name' => 'Eski Karta', 'monthly_salary' => '3000000', 'salary_from' => '2026-09', 'hired_at' => '2025-01-01', 'is_active' => 1])->assertSessionHasNoErrors();
        $this->assertSame(['19000000.00', '3000000.00'], array_slice($this->cols($this->row(TaxReport::build('2026-09'), $legacy->id)), 0, 2));   // 2 млн × 8 + 3 млн
    }

    public function test_payroll_overrides_salary_and_benefit_options(): void
    {
        $this->as($this->accountant);
        $a = $this->person(['full_name' => 'VEDOMOST ANVAR', 'pinfl' => '30000000000001', 'monthly_salary' => '4500000', 'hired_at' => '2025-01-01']);
        TaxPayrollService::calculate('2026-09');
        $row = AccPayroll::where('person_id', $a->id)->firstOrFail();
        TaxPayrollService::updateGross($row->id, '5000000');   // бухгалтер поправил начисление
        $this->assertSame(['41000000.00', '5000000.00', '3075000.00', '375000.00', '103848', '15375000.00', '1875000.00'], $this->cols($this->row(TaxReport::build('2026-09'), $a->id)));

        // Неактивный без даты увольнения, но с ведомостью — попадает по ведомости
        $a->update(['is_active' => false]);
        $this->assertSame(['5000000.00', '5000000.00'], array_slice($this->cols($this->row(TaxReport::build('2026-09'), $a->id)), 0, 2));

        // Без льгот: 12 % со всего дохода; своя льгота — фиксированная сумма в месяц
        $none = $this->person(['full_name' => 'IMTIYOZSIZ', 'pinfl' => '30000000000002', 'monthly_salary' => '1000000', 'hired_at' => '2026-09-01', 'benefits' => []]);
        $fixed = $this->person(['full_name' => 'QATTIQ SUMMA', 'pinfl' => '30000000000003', 'monthly_salary' => '1000000', 'hired_at' => '2026-08-01',
            'benefits' => [['code' => '100100', 'amount' => '300000'], ['code' => '100200', 'percent' => '50']]]);
        $r = TaxReport::build('2026-09');
        $this->assertSame(['1000000.00', '1000000.00', '120000.00', '120000.00', null, null, null], $this->cols($this->row($r, $none->id)));
        $rf = $this->row($r, $fixed->id);
        // 1 000 000: 300 000 фиксировано + 50 % (500 000) не облагается → НДФЛ 12 % × 200 000
        $this->assertSame(['2000000.00', '48000.00', '24000.00'], [$rf['ytdIncome'], $rf['ytdNdfl'], $rf['monthNdfl']]);
        $this->assertSame([['code' => '100100', 'ytd' => '600000.00', 'month' => '300000.00'], ['code' => '100200', 'ytd' => '1000000.00', 'month' => '500000.00']], $rf['benefits']);
        $this->assertSame(2, $r['slots']);

        // Ведомость с другим НДФЛ, чем дают льготы, — предупреждение
        AccPayroll::where('id', $row->id)->update(['ndfl' => '1']);
        $this->assertSame(['09.2026'], $this->row(TaxReport::build('2026-09'), $a->id)['mismatch']);
    }

    public function test_preview_warnings_download_file_and_permissions(): void
    {
        Settings::save('company', ['inn' => '300000001'] + Settings::company());
        $this->as($this->accountant);
        Accounting::saveSettings(['taxReportCode' => '555000111']);
        $a = $this->person(['full_name' => 'FAYL SINOV ALIYEVICH', 'pinfl' => '01234567890123', 'monthly_salary' => '4500000', 'hired_at' => '2025-01-01', 'position' => 'Директор']);
        $b = $this->person(['full_name' => 'Кириллица Тест', 'monthly_salary' => '1400000', 'hired_at' => '2026-06-01', 'position' => null]);
        $this->person(['full_name' => 'OFF XODIM', 'monthly_salary' => '1000000', 'is_active' => false]);

        $r = TaxReport::build('2026-09');
        $this->assertSame([], $this->row($r, $a->id)['warnings']);
        $this->assertSame(['pinflMissing', 'nameNotLatin', 'positionMissing'], $this->row($r, $b->id)['warnings']);
        $this->assertSame('300000001_555000111_46_2026_M_9_11101_20_ilova.xlsx', $r['filename']);
        $this->assertSame([], $r['warnings']);

        // Просмотр на всех языках
        foreach (['uz', 'ru', 'en'] as $locale) {
            $this->accountant->update(['locale' => $locale]);
            $this->actingAsUser($this->accountant);
            foreach (['/accounting/tax-report?period=2026-09', '/accounting/tax-report?period=2020-01', '/accounting/settings', '/accounting/people?edit='.$a->id] as $url) {
                $html = $this->get($url)->assertOk()->getContent();
                $this->assertDoesNotMatchRegularExpression('/(?<![\/\w])(accounting|common)\.[a-z][A-Za-z_.]+/', strip_tags($html), "$locale $url: ключ без перевода");
            }
        }
        $this->get('/accounting/tax-report?period=2026-09')->assertSee('FAYL SINOV ALIYEVICH')->assertSee('01234567890123')->assertSee('OFF XODIM')->assertSee('/accounting/tax-report/download?period=2026-09', false);

        // Файл: шаблон сохранён, данные с 15-й строки
        $res = $this->get('/accounting/tax-report/download?period=2026-09')->assertOk();
        $this->assertStringContainsString('filename="300000001_555000111_46_2026_M_9_11101_20_ilova.xlsx"', $res->headers->get('Content-Disposition'));
        $tmp = tempnam(sys_get_temp_dir(), 'tax').'.xlsx';
        file_put_contents($tmp, $res->getContent());
        $book = IOFactory::load($tmp);
        unlink($tmp);
        $this->assertSame(['list01', 'list02'], $book->getSheetNames());
        $ws = $book->getSheetByName('list01');
        $plain = fn (string $c) => ($v = $ws->getCell($c)->getValue()) instanceof RichText ? $v->getPlainText() : $v;
        $this->assertSame('Т/р', $plain('B10'));
        $this->assertSame('=SUM(L15:L1048576)', $ws->getCell('L12')->getValue());
        $this->assertSame('ЖАМИ', $plain('K12'));
        $this->assertStringContainsString('01.01.2026', $plain('F10'));
        $this->assertSame(11, $ws->getCell('L13')->getValue());
        $this->assertContains('P10:AS10', array_keys($ws->getMergeCells()));
        // Строки по алфавиту: «FAYL…», затем «КИРИЛЛИЦА…»
        $this->assertSame([1, 'FAYL SINOV ALIYEVICH', 'Директор', '01234567890123', '01.01.2026', 1, 1, 1, 1, 1, 40500000, 4500000, 3037500, 337500, 103848, 15187500, 1687500],
            array_map(fn ($c) => $ws->getCell($c.'15')->getValue(), ['B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M', 'N', 'O', 'P', 'Q', 'R']));
        $this->assertSame(DataType::TYPE_STRING, $ws->getCell('E15')->getDataType());   // ПИНФЛ с ведущим нулём — текстом
        $this->assertSame(DataType::TYPE_STRING, $ws->getCell('F15')->getDataType());
        $this->assertSame([2, 'КИРИЛЛИЦА ТЕСТ', null, '01.06.2026', 5600000, 1400000, 420000, 105000, 103848, 2100000, 525000],
            array_map(fn ($c) => $ws->getCell($c.'16')->getValue(), ['B', 'C', 'E', 'F', 'L', 'M', 'N', 'O', 'P', 'Q', 'R']));
        $this->assertNull($ws->getCell('S15')->getValue());
        $this->assertNull($ws->getCell('B17')->getValue());
        $this->assertNull($book->getSheetByName('list02')->getCell('B15')->getValue());
        $this->assertSame('Fluggi CRM', $book->getProperties()->getCreator());
        $log = AuditLog::where('action', 'export.run')->where('entity_type', 'acc_tax_form')->latest('created_at')->first();
        $this->assertNotNull($log);
        $this->assertSame($this->accountant->id, $log->actor_id);
        $this->assertEquals(['period' => '2026-09', 'rows' => 2, 'filename' => '300000001_555000111_46_2026_M_9_11101_20_ilova.xlsx'], $log->changes['export']['new']);

        // Права: РОП — нет; с правом просмотра бухгалтерии — смотреть можно, скачать нельзя
        $rop = $this->user('ROP');
        $this->actingAsUser($rop)->get('/accounting/tax-report')->assertForbidden();
        $this->get('/accounting/tax-report/download?period=2026-09')->assertForbidden();
        DB::table('role_permissions')->insert(['role_id' => Role::where('code', 'ROP')->value('id'), 'permission_id' => DB::table('permissions')->where('code', 'accounting.read')->value('id'), 'scope' => 'ALL']);
        $this->actingAsUser($rop->fresh())->get('/accounting/tax-report?period=2026-09')->assertOk()->assertSee('FAYL SINOV ALIYEVICH')->assertDontSee('/accounting/tax-report/download', false);
        $this->get('/accounting/tax-report/download?period=2026-09')->assertForbidden();
        $this->actingAsUser($this->user('CEO'))->get('/accounting/tax-report/download?period=2026-09')->assertOk();
    }

    public function test_settings_and_person_form_validation(): void
    {
        $this->actingAsUser($this->accountant);
        $this->put('/accounting/settings', ['ndflBaseRate' => '12', 'benefitCode' => '103848', 'benefitPercent' => '37,5', 'inpsRate' => '0.1', 'itparkRate' => '1',
            'companyInn' => '123456789', 'taxReportCode' => '42', 'taxReportFileName' => '{inn}_{code}_{year}_{mm}.xlsx'])->assertSessionHasNoErrors();
        $s = Accounting::settings();
        $this->assertSame(['12', '103848', '37.5', '7.5', '123456789'], [$s['ndflBaseRate'], $s['benefitCode'], $s['benefitPercent'], $s['ndflRate'], $s['companyInn']]);
        $this->assertSame('123456789_42_2026_03.xlsx', TaxReport::filename('2026-03', $s));
        $this->put('/accounting/settings', ['ndflBaseRate' => '12', 'benefitPercent' => '101', 'companyInn' => '12', 'taxReportFileName' => '../x'])
            ->assertSessionHasErrors(['benefitPercent', 'companyInn', 'taxReportFileName']);
        // Без льготы компании — эффективная ставка равна ставке НДФЛ
        $this->put('/accounting/settings', ['ndflBaseRate' => '12', 'benefitCode' => '', 'benefitPercent' => '37.5', 'inpsRate' => '0.1', 'itparkRate' => '1'])->assertSessionHasNoErrors();
        $this->assertSame('12', Accounting::settings()['ndflRate']);
        $this->assertSame([], TaxReport::benefits(null, Accounting::settings()));

        $base = ['full_name' => 'Forma Test', 'monthly_salary' => '1000000', 'is_active' => 1];
        $this->post('/accounting/people', $base + ['benefits_mode' => 'custom', 'benefits' => [['code' => '', 'kind' => 'percent', 'value' => '']]])->assertSessionHasErrors('benefits');
        $this->post('/accounting/people', $base + ['benefits_mode' => 'custom', 'benefits' => [['code' => '1', 'kind' => 'percent', 'value' => '150']]])->assertSessionHasErrors('benefits.0.value');
        $this->post('/accounting/people', $base + ['resident_type' => 3, 'work_rate' => '2'])->assertSessionHasErrors(['resident_type', 'work_rate']);
        $this->post('/accounting/people', $base + ['resident_type' => 2, 'contract_type' => 3, 'workplace_type' => 4, 'work_rate' => '0,5',
            'benefits_mode' => 'custom', 'benefits' => [['code' => '100100', 'kind' => 'amount', 'value' => '300 000'], ['code' => '100200', 'kind' => 'percent', 'value' => '10']]])->assertSessionHasNoErrors();
        $p = AccPerson::where('full_name', 'Forma Test')->firstOrFail();
        $this->assertSame([2, 3, 4, '0.50'], [$p->resident_type, $p->contract_type, $p->workplace_type, (string) $p->work_rate]);
        $this->assertSame([['code' => '100100', 'amount' => '300000'], ['code' => '100200', 'percent' => '10']], $p->benefits);
        $this->put("/accounting/people/{$p->id}", $base + ['benefits_mode' => 'none'])->assertSessionHasNoErrors();
        $this->assertSame([], $p->refresh()->benefits);
        $this->put("/accounting/people/{$p->id}", $base + ['benefits_mode' => 'default'])->assertSessionHasNoErrors();
        $this->assertNull($p->refresh()->benefits);
        $this->actingAsUser($this->user('ROP'))->delete("/accounting/people/{$p->id}/salaries/{$p->salaries()->first()->id}")->assertForbidden();
    }

    /** Миграция настроек: эффективная ставка 7,5 % → 12 % + льгота IT Park; своя ставка — без льготы; повторный запуск безопасен. */
    public function test_settings_migration_keeps_the_same_tax(): void
    {
        $migration = require database_path('migrations/2026_11_09_000000_acc_tax_report.php');
        foreach ([['7.5', ['12', '103848', '37.5', '7.5']], ['10', ['10', '', '0', '10']]] as [$old, $expected]) {
            DB::table('settings')->updateOrInsert(['key' => 'accounting'], ['value' => json_encode(['ndflRate' => $old, 'inpsRate' => '0.1', 'itparkRate' => '1']), 'updated_at' => now()]);
            $migration->up();
            $migration->up();
            Settings::forget();
            $s = Accounting::settings();
            $this->assertSame($expected, [$s['ndflBaseRate'], $s['benefitCode'], $s['benefitPercent'], $s['ndflRate']], $old);
            $this->assertArrayNotHasKey('ndflRate', json_decode(DB::table('settings')->where('key', 'accounting')->value('value'), true));
        }
    }

    /** Шаблон в репозитории — без персональных данных: с 15-й строки пусто, автор файла не указан. */
    public function test_template_in_repository_has_no_personal_data(): void
    {
        $book = IOFactory::load(resource_path(TaxReport::TEMPLATE));
        $this->assertSame(['list01', 'list02'], $book->getSheetNames());
        foreach ($book->getWorksheetIterator() as $ws) {
            foreach ($ws->getCellCollection()->getCoordinates() as $coord) {
                if ((int) preg_replace('/\D/', '', $coord) >= TaxReport::FIRST_ROW) {
                    $this->assertNull($ws->getCell($coord)->getValue(), $ws->getTitle().'!'.$coord);
                }
                $v = (string) $ws->getCell($coord)->getValue();
                $this->assertDoesNotMatchRegularExpression('/\d{14}/', $v, $ws->getTitle().'!'.$coord);
            }
        }
        $props = $book->getProperties();
        $this->assertSame(['', '', ''], [$props->getCreator(), $props->getLastModifiedBy(), $props->getCompany()]);
    }
}
