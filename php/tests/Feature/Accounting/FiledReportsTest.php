<?php

namespace Tests\Feature\Accounting;

use App\Models\AccAccount;
use App\Models\AccPerson;
use App\Models\AccPersonSalary;
use App\Models\AccTaxAct;
use App\Models\AccTaxFiling;
use App\Models\AccTransaction;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Accounting\Accounting;
use App\Services\Accounting\BankRules;
use App\Services\Accounting\FiledReports;
use App\Services\Accounting\Recategorize;
use App\Services\Accounting\TaxesService;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;
use ZipArchive;

/**
 * Сданные отчёты: чтение файлов формы (столбцы по заголовкам), НДФЛ месяца по приросту «с начала года», покрытые месяцы,
 * пересчёты задним числом, создание людей по ПИНФЛ, сальдо с оплатами, повторная загрузка, права. Все люди и ПИНФЛ — вымышленные.
 */
class FiledReportsTest extends TestCase
{
    private User $accountant;

    private array $tmp = [];

    protected function setUp(): void
    {
        parent::setUp();
        Accounting::forgetRates();
        $this->accountant = $this->user('ACCOUNTANT');
    }

    protected function tearDown(): void
    {
        foreach ($this->tmp as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    /**
     * Файл отчёта в форме 2025 года. Строка: [ФИО, должность, ПИНФЛ, статус, дата, доход с начала года, доход за месяц, НДФЛ с начала года, НДФЛ за месяц].
     *
     * @param  list<array>  $rows
     */
    private function report(string $period, array $rows, bool $zip = false): UploadedFile
    {
        [$y, $m] = explode('-', $period);
        $name = '300000001_111111111_46_'.$y.'_M_'.(int) $m.'_11101_18_ilova.xlsx';
        $book = new Spreadsheet;
        $ws = $book->getActiveSheet()->setTitle('list01');
        $heads = ['B' => 'Т/р', 'C' => 'Жисмоний шахснинг Ф.И.Ш.', 'D' => 'Лавозими', 'E' => 'ЖШ ШИР', 'F' => 'Иш ўрни ташкил қилинган сана (Иш ўрни 01.01.2025)', 'G' => 'Жисмоний шахс тури (1 - резидент)',
            'H' => 'Ҳисобот даврида ходимнинг ҳолати (1 - ишлаётган)', 'I' => 'Шартнома тури (1 - меҳнат шартномаси)', 'J' => 'Иш вақти (ставкада 0,25; 0,5; 1 ва ҳ.к)', 'K' => 'Иш ўрни (1 - доимий)',
            'L' => 'Жами ҳисобланган меҳнатга ҳақ тўлаш тарзидаги даромадлар', 'M' => 'шундан, ҳисобот даврида ҳисобланган меҳнатга ҳақ тўлаш тарзидаги даромадлар', 'N' => 'Жисмоний шахслардан олинадиган даромад солиғи'];
        foreach ($heads as $c => $v) {
            $ws->setCellValueExplicit($c.'10', $v, DataType::TYPE_STRING);
        }
        $ws->setCellValueExplicit('N11', 'Жами ҳисобланган солиқ суммаси', DataType::TYPE_STRING);
        $ws->setCellValueExplicit('O11', 'шундан, ҳисобот даврида ҳисобланган солиқ суммаси', DataType::TYPE_STRING);
        $ws->setCellValueExplicit('P11', 'имтиёз рақами', DataType::TYPE_STRING);
        $ws->setCellValueExplicit('Q11', 'Жами солиққа тортилмайдиган меҳнатга ҳақ тўлаш тарзидаги даромадлар', DataType::TYPE_STRING);
        foreach ($rows as $i => $r) {
            $n = 14 + $i;
            $ws->setCellValue('B'.$n, $i + 1);
            $ws->setCellValueExplicit('C'.$n, $r[0], DataType::TYPE_STRING);
            $ws->setCellValueExplicit('D'.$n, $r[1], DataType::TYPE_STRING);
            $ws->setCellValueExplicit('E'.$n, $r[2], DataType::TYPE_STRING);
            $ws->setCellValueExplicit('F'.$n, $r[4], DataType::TYPE_STRING);
            $ws->setCellValue('G'.$n, 1);
            $ws->setCellValue('H'.$n, $r[3]);
            $ws->setCellValue('I'.$n, 1);
            $ws->setCellValue('J'.$n, 1);
            $ws->setCellValue('K'.$n, 1);
            $ws->setCellValue('L'.$n, $r[5]);
            $ws->setCellValue('M'.$n, $r[6]);
            $ws->setCellValue('N'.$n, $r[7]);
            $ws->setCellValue('O'.$n, $r[8]);
        }
        $path = tempnam(sys_get_temp_dir(), 'frt');
        (new Xlsx($book))->save($path);
        $this->tmp[] = $path;

        return new UploadedFile($path, $name, null, null, true);
    }

    /**
     * Акт сверки с налоговой (xls): по строке на налог — [код, название, сальдо на начало: долг, переплата, на конец: долг, переплата, начислено, оплачено].
     *
     * @param  list<array>  $taxes
     */
    private function act(int $year, array $taxes): UploadedFile
    {
        $book = new Spreadsheet;
        $ws = $book->getActiveSheet();
        $ws->setCellValueExplicit('A1', '"FLUGGI" MCHJнинг (СТИР: 300000001) '.$year.' йил учун 31.12.'.$year.'га Наманган вилояти, Наманган шаҳардаги СОЛИҚ ВА ЙИҒИМЛАР БЎЙИЧА СОЛИШТИРМА ДАЛОЛАТНОМА', DataType::TYPE_STRING);
        $ws->setCellValueExplicit('A2', 'Шакллантирилган сана ва вақти: 09.11.2026 00:17:01    Манба: Электрон солиқ хизматлари портали', DataType::TYPE_STRING);
        $ws->setCellValueExplicit('A4', 'Ҳудуд', DataType::TYPE_STRING);
        foreach ($taxes as $i => $t) {
            $r = 6 + $i;
            $ws->setCellValueExplicit('A'.$r, 'Наманган', DataType::TYPE_STRING);
            $ws->setCellValueExplicit('B'.$r, (string) $t[0], DataType::TYPE_STRING);
            $ws->setCellValueExplicit('C'.$r, $t[1], DataType::TYPE_STRING);
            // D..: начало (текущий долг, продлённый долг, пеня, продлённая пеня, переплата), конец (то же), начислено (расчёт, акт, разово, баёнлар, всего, пеня), оплачено (платежи, возвращено, пеня, всего)
            $vals = [$t[2], 0, 0, 0, $t[3], $t[4], 0, 0, 0, $t[5], $t[6], 0, 0, 0, $t[6], 0, $t[7], 0, 0, $t[7]];
            foreach ($vals as $k => $v) {
                $ws->setCellValue(Coordinate::stringFromColumnIndex(4 + $k).$r, $v);
            }
        }
        $path = tempnam(sys_get_temp_dir(), 'fra');
        (new Xls($book))->save($path);
        $this->tmp[] = $path;

        return new UploadedFile($path, '09.11.2026_00-19-'.$year.'.xls', null, null, true);
    }

    private function zip(array $uploads): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'frz');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        foreach ($uploads as $u) {
            $zip->addFile($u->getRealPath(), $u->getClientOriginalName());
        }
        $zip->close();
        $this->tmp[] = $path;

        return new UploadedFile($path, 'reports.zip', null, null, true);
    }

    /** Окт 2024 → дек 2024 (нояб. нет) → янв, фев 2025 (в феврале пересчёт задним числом). Две человека и пробная строка без дохода. */
    private function sample(): array
    {
        $a = ['ALIYEV VALI KARIM O‘G‘LI', 'Директор', '10000000000001'];
        $b = ['KARIMOVA ZILOLA AZIZOVNA', 'Менеджер', '10000000000002'];
        $x = ['abc', 'ishchi', '10000000000099'];

        return [
            '2024-10' => [[...$a, 1, '01.01.2024', 1000000, 1000000, 100000, 100000], [...$x, 1, '01.01.2024', 0, 0, 0, 0]],
            '2024-12' => [[...$a, 1, '01.01.2024', 3000000, 1000000, 300000, 100000]],
            '2025-01' => [[...$a, 1, '01.01.2025', 1000000, 1000000, 100000, 100000], [...$b, 1, '01.01.2025', 2000000, 2000000, 200000, 200000]],
            // Февраль: у второго человека пересчёт — «с начала года» меньше, чем было + за месяц
            '2025-02' => [[...$a, 1, '01.01.2025', 2000000, 1000000, 200000, 100000], [...$b, 2, '01.01.2025', 2000000, 0, 180000, 0]],
        ];
    }

    private function load(): void
    {
        $r = FiledReports::read(array_map(fn ($p, $rows) => $this->report($p, $rows), array_keys($this->sample()), $this->sample()));
        $this->assertSame([], $r['errors']);
        $this->as($this->accountant);
        FiledReports::apply($r['months']);
    }

    public function test_reads_forms_and_computes_tax_from_year_to_date_growth(): void
    {
        $this->load();
        $this->assertSame(7, AccTaxFiling::count());
        $map = FiledReports::map();
        $this->assertSame(['100000.00', '0.00', '200000.00', '300000.00', '80000.00'], array_map(fn ($p) => $p === null ? null : (string) $p->toScale(2),
            [FiledReports::due('2024-10', $map), FiledReports::due('2024-11', $map), FiledReports::due('2024-12', $map), FiledReports::due('2025-01', $map), FiledReports::due('2025-02', $map)]));
        $this->assertNull(FiledReports::due('2025-03', $map));   // после последнего отчёта года — по ведомости/окладам

        // Февраль: сумма «за месяц» в файле 100 000, прирост с начала года — 80 000 (пересчёт у второго человека)
        $feb = TaxesService::forPeriod('2025-02');
        $this->assertTrue($feb['filed']);
        $this->assertSame(['79000.00', '1000000.00'], [$feb['taxes']['TAX_NDFL']['due'], $feb['taxes']['TAX_NDFL']['base']]);
        $nov = TaxesService::forPeriod('2024-11');
        $this->assertSame(['0.00', true], [$nov['taxes']['TAX_NDFL']['due'], $nov['filedCovered']]);
    }

    public function test_people_are_created_from_reports_and_existing_are_completed(): void
    {
        // Уже есть второй человек с ручным окладом: оклад и имя не трогаем, пустое дополняем
        $existing = AccPerson::create(['full_name' => 'Каримова Зилола', 'pinfl' => '10000000000002', 'monthly_salary' => '2500000', 'is_active' => true]);
        $this->load();

        $a = AccPerson::where('pinfl', '10000000000001')->firstOrFail();
        $this->assertSame(['Aliyev Vali Karim O\'g\'li', 'ALIYEV VALI KARIM O\'G\'LI', 'Директор', '2024-10-01', true, '1000000.00'],
            [$a->full_name, $a->passport_name, $a->position, $a->hired_at->toDateString(), $a->is_active, (string) $a->monthly_salary]);
        $this->assertSame([['2024-10', '1000000.00']], $a->salaries->map(fn ($h) => [$h->effective_from, (string) $h->amount])->all());
        // Пробная строка без дохода человеком не стала, но в отчётах осталась
        $this->assertNull(AccPerson::where('pinfl', '10000000000099')->first());
        $this->assertSame(1, AccTaxFiling::where('pinfl', '10000000000099')->count());
        $this->assertSame($a->id, AccTaxFiling::where('pinfl', '10000000000001')->first()->person_id);

        $b = $existing->refresh();
        $this->assertSame(['Каримова Зилола', 'KARIMOVA ZILOLA AZIZOVNA', '2500000.00', '2025-01-01', '2025-01-31', false], [$b->full_name, $b->passport_name, (string) $b->monthly_salary,
            $b->hired_at->toDateString(), $b->fired_at->toDateString(), $b->is_active]);   // статус «ушёл» в феврале → уволена на конец последнего месяца с доходом
        $this->assertSame(1, AccPersonSalary::where('person_id', $b->id)->count());
        $this->assertTrue(AuditLog::where('action', 'accounting.filings.import')->exists());

        // Повторная загрузка ничего не дублирует
        $this->load();
        $this->assertSame([7, 2], [AccTaxFiling::count(), AccPerson::count()]);
        $this->assertSame(1, AccPersonSalary::where('person_id', $a->id)->count());
    }

    public function test_ledger_runs_cumulatively_with_payments_and_opening_balance(): void
    {
        $this->load();
        $acc = AccAccount::create(['name' => 'Bank', 'kind' => 'BANK', 'currency' => 'UZS', 'opening_balance' => '0', 'crm_methods' => []]);
        // Дек 2024 оплачено 250 000 (период по дате платежа — январь), январь 2025 — 150 000 без периода
        AccTransaction::create(['account_id' => $acc->id, 'direction' => 'OUT', 'category' => 'TAX_NDFL', 'amount' => '250000', 'date' => '2025-01-10', 'period' => '2024-12']);
        AccTransaction::create(['account_id' => $acc->id, 'direction' => 'OUT', 'category' => 'TAX_NDFL', 'amount' => '150000', 'date' => '2025-02-12']);

        $l = TaxesService::ndflLedger('2025-02');
        $this->assertSame('2024-10', $l['from']);
        $by = collect($l['rows'])->keyBy('period');
        $this->assertSame(['filed', 'covered', 'filed', 'filed', 'filed'], array_map(fn ($p) => $by[$p]['source'], ['2024-10', '2024-11', '2024-12', '2025-01', '2025-02']));
        $this->assertSame(['99000.00', '99000.00', '48000.00', '345000.00', '274000.00'], array_map(fn ($p) => $by[$p]['balance'], ['2024-10', '2024-11', '2024-12', '2025-01', '2025-02']));
        $this->assertSame(['674000.00', '400000.00', '274000.00'], [$l['totalDue'], $l['totalPaid'], $l['balance']]);
        $this->assertSame('100000.00', $by['2025-02']['filedMonth']);   // в файле «за месяц» 100 000, в сальдо — прирост 80 000

        // Остаток переходит в налоги месяца; сальдо на начало из настроек
        $t = TaxesService::forPeriod('2025-02')['taxes']['TAX_NDFL'];
        $this->assertSame(['345000.00', '79000.00', '150000.00', '274000.00'], [$t['opening'], $t['due'], $t['paid'], $t['balance']]);
        // Сверка по годам: как в личном кабинете налогоплательщика — начислено, оплачено, долг и переплата на конец года
        $y = TaxesService::yearly('2025-02');
        $this->assertSame([2024, 2025], array_keys($y['years']));
        $this->assertSame(['298000.00', '250000.00', '50000.00', '0.00'], [$y['years']['2024']['ndflDue'], $y['years']['2024']['ndflPaid'], $y['years']['2024']['debt'], $y['years']['2024']['over']]);
        $this->assertSame(['376000.00', '150000.00'], [$y['years']['2025']['ndflDue'], $y['years']['2025']['ndflPaid']]);
        $this->actingAsUser($this->accountant)->get('/accounting/taxes?period=2025-02')->assertOk()->assertSee(t('accounting.taxes.yearly.title'));
        // Учёт начат позже первого отчёта — предупреждение (иначе платежи прошлых начислений дают ложную переплату)
        Accounting::saveSettings(['taxBalanceFrom' => '2025-01']);
        $this->actingAsUser($this->accountant)->get('/accounting/taxes?period=2025-02')->assertOk()->assertSee(t('accounting.taxes.startLate', ['from' => Accounting::monthLabel('2025-01'), 'first' => Accounting::monthLabel('2024-10')]));
        Accounting::saveSettings(['taxBalanceFrom' => '', 'taxOpeningNdfl' => '-20000']);
        $this->assertSame('2024-10', TaxesService::ndflLedger('2025-02')['from']);
        Accounting::saveSettings(['taxOpeningNdfl' => '-20000']);
        $this->assertSame('254000.00', TaxesService::ndflLedger('2025-02')['balance']);
    }

    public function test_upload_preview_confirm_zip_and_permissions(): void
    {
        $files = array_map(fn ($p, $rows) => $this->report($p, $rows), array_keys($this->sample()), $this->sample());
        $this->actingAsUser($this->accountant);
        foreach (['uz', 'ru', 'en'] as $locale) {
            $this->accountant->update(['locale' => $locale]);
            $this->actingAsUser($this->accountant);
            $html = $this->get('/accounting/filed-reports')->assertOk()->getContent();
            $this->assertDoesNotMatchRegularExpression('/(?<![\/\w])(accounting|common|import)\.[a-z][A-Za-z_.]+/', strip_tags($html), "$locale: ключ без перевода");
        }
        // Два файла и архив с двумя
        $resp = $this->post('/accounting/filed-reports', ['files' => [$files[0], $files[1], $this->zip([$files[2], $files[3]])]]);
        $resp->assertRedirect();
        $url = $resp->headers->get('Location');
        $this->assertMatchesRegularExpression('#/accounting/filed-reports/[A-Za-z0-9]{40}$#', $url);
        foreach (['uz', 'ru', 'en'] as $locale) {
            $this->accountant->update(['locale' => $locale]);
            $this->actingAsUser($this->accountant);
            $html = $this->get($url)->assertOk()->assertSee('KARIMOVA ZILOLA AZIZOVNA')->getContent();
            $this->assertDoesNotMatchRegularExpression('/(?<![\/\w])(accounting|common|import)\.[a-z][A-Za-z_.]+/', strip_tags($html), "$locale: ключ без перевода");
        }
        $this->assertSame(0, AccTaxFiling::count());   // до подтверждения ничего не записано
        $this->post($url.'/confirm')->assertRedirect('/accounting/filed-reports')->assertSessionHas('ok');
        $this->assertSame([7, 2], [AccTaxFiling::count(), AccPerson::count()]);
        $this->post($url.'/confirm')->assertRedirect('/accounting/filed-reports')->assertSessionHas('error');   // второй раз — ничего
        $this->assertSame(7, AccTaxFiling::count());
        $this->get('/accounting/filed-reports?to=2025-02')->assertOk()->assertSee(t('accounting.filed.sources.covered'));

        // Не отчёт и чужая вкладка
        $bad = UploadedFile::fake()->create('notes.xlsx', 1);
        $this->post('/accounting/filed-reports', ['files' => [$bad]])->assertSessionHasErrors('files');
        $this->post('/accounting/filed-reports', ['files' => [UploadedFile::fake()->create('x.pdf', 1)]])->assertSessionHasErrors('files.0');

        // Права: смотреть — бухгалтерия, загружать — управление; остальные роли — нет
        foreach (['ROP', 'MANAGER', 'EXECUTOR'] as $role) {
            $this->actingAsUser($this->user($role));
            $this->get('/accounting/filed-reports')->assertForbidden();
            $this->post('/accounting/filed-reports', ['files' => [$files[0]]])->assertForbidden();
            $this->post($url.'/confirm')->assertForbidden();
        }
    }

    public function test_reconciliation_acts_anchor_the_balance_and_are_compared(): void
    {
        $this->load();
        $acc = AccAccount::create(['name' => 'Bank', 'kind' => 'BANK', 'currency' => 'UZS', 'opening_balance' => '0', 'crm_methods' => []]);
        AccTransaction::create(['account_id' => $acc->id, 'direction' => 'OUT', 'category' => 'TAX_NDFL', 'amount' => '250000', 'date' => '2025-01-10', 'period' => '2024-12']);
        $a2024 = $this->act(2024, [['46', 'Жисмоний шахслар даромадидан олинадиган солиқ', 0, 0, 20000, 0, 298000, 250000], ['101', 'ИНПС', 0, 0, 0, 0, 2000, 2000],
            ['199', 'Пеня', 0, 0, 0, 0, 0, 0]]);
        $a2025 = $this->act(2025, [['46', 'Жисмоний шахслар даромадидан олинадиган солиқ', 20000, 0, 0, 5000, 376000, 401000]]);
        $r = FiledReports::read([$a2024, $a2025]);
        $this->assertSame([], $r['errors']);
        $this->assertSame([2024, 2025], array_keys($r['acts']));
        $this->assertSame(['2024-12-31', true, '20000.00', '298000.00', '250000.00'], [$r['acts'][2024]['asOf'], $r['acts'][2024]['yearEnd'], $r['acts'][2024]['rows']['46']['closingDebt'], $r['acts'][2024]['rows']['46']['accrued'], $r['acts'][2024]['rows']['46']['paid']]);
        FiledReports::apply([], $r['acts']);
        $this->assertSame(4, AccTaxAct::count());
        FiledReports::apply([], $r['acts']);   // повторная загрузка заменяет
        $this->assertSame(4, AccTaxAct::count());

        // Январь 2025: сальдо на начало года — из акта 2024 (долг 20 000), а не по цепочке с 2024-10
        $l = TaxesService::ndflLedger('2025-02');
        $by = collect($l['rows'])->keyBy('period');
        $this->assertSame([true, '20000.00', '317000.00'], [$by['2025-01']['reset'], $by['2025-01']['opening'], $by['2025-01']['balance']]);
        $this->assertFalse($by['2024-12']['reset']);
        $t = TaxesService::forPeriod('2025-02');
        $this->assertTrue($t['anchored']);
        $this->assertSame(['2025-01', '317000.00', '79000.00'], [$t['balanceFrom'], $t['taxes']['TAX_NDFL']['opening'], $t['taxes']['TAX_NDFL']['due']]);

        // Сверка по актам: 2024 сходится, в 2025 расхождения в начислено и оплачено
        $c = collect(TaxesService::actCompare('2025-02'))->keyBy(fn ($x) => $x['year'].'-'.$x['code']);
        $this->assertSame(['298000.00', '298000.00', '0.00', '250000.00', '250000.00', '0.00'], [$c['2024-46']['actDue'], $c['2024-46']['ourDue'], $c['2024-46']['diffDue'], $c['2024-46']['actPaid'], $c['2024-46']['ourPaid'], $c['2024-46']['diffPaid']]);
        $this->assertSame(['376000.00', '376000.00', '0.00', '401000.00', '0.00', '-401000.00'], [$c['2025-46']['actDue'], $c['2025-46']['ourDue'], $c['2025-46']['diffDue'], $c['2025-46']['actPaid'], $c['2025-46']['ourPaid'], $c['2025-46']['diffPaid']]);
        $this->assertSame(['-5000.00'], [$c['2025-46']['actBalance']]);
        $this->assertFalse($c->has('2024-199'));   // пеня только в акте

        // Сверка по годам равна акту налоговой: начислено, оплачено, долг и переплата по всем налогам акта
        $y = TaxesService::yearly('2025-02');
        $this->assertSame(['300000.00', '252000.00', '20000.00', '0.00'], [$y['years'][2024]['due'], $y['years'][2024]['paid'], $y['years'][2024]['debt'], $y['years'][2024]['over']]);
        $this->assertSame(['376000.00', '401000.00', '0.00', '5000.00'], [$y['years'][2025]['due'], $y['years'][2025]['paid'], $y['years'][2025]['debt'], $y['years'][2025]['over']]);
        $this->assertSame([2024, 2025], array_keys($y['acts']));

        // Ручное начало учёта отключает привязку к актам
        Accounting::saveSettings(['taxBalanceFrom' => '2024-10']);
        $this->assertFalse(TaxesService::forPeriod('2025-02')['anchored']);
        Accounting::saveSettings(['taxBalanceFrom' => '']);

        // Страницы на всех языках
        foreach (['uz', 'ru', 'en'] as $locale) {
            $this->accountant->update(['locale' => $locale]);
            $this->actingAsUser($this->accountant);
            foreach (['/accounting/filed-reports?to=2025-02', '/accounting/taxes?period=2025-02'] as $url) {
                $html = $this->get($url)->assertOk()->getContent();
                $this->assertDoesNotMatchRegularExpression('/(?<![\/\w])(accounting|common|import)\.[a-z][A-Za-z_.]+/', strip_tags($html), "$locale $url: ключ без перевода");
            }
        }
        $this->get('/accounting/filed-reports?to=2025-02')->assertSee(t('accounting.filed.compareTitle'))->assertSee(t('accounting.filed.reset', ['sum' => money('20000.00')]));

        // Загрузка актов через форму вместе с архивом
        $resp = $this->post('/accounting/filed-reports', ['files' => [$a2024, $this->zip([$a2025])]]);
        $url = $resp->headers->get('Location');
        $this->get($url)->assertOk()->assertSee(t('accounting.filed.actsTitle'));
        $this->post($url.'/confirm')->assertSessionHas('ok');
        $this->assertSame(4, AccTaxAct::count());
        $this->post('/accounting/filed-reports', ['files' => [UploadedFile::fake()->create('notact.xls', 1)]])->assertSessionHasErrors('files');
    }

    public function test_recategorize_moves_payee_groups_and_rules_are_stricter(): void
    {
        $acc = AccAccount::create(['name' => 'Bank', 'kind' => 'BANK', 'currency' => 'UZS', 'opening_balance' => '0', 'crm_methods' => []]);
        $op = fn (string $cat, string $cp, string $amount, string $date, string $desc, string $dir = 'OUT') => AccTransaction::create(['account_id' => $acc->id, 'direction' => $dir, 'category' => $cat, 'amount' => $amount,
            'date' => $date, 'period' => $cat === 'TAX_OTHER' ? substr($date, 0, 7) : null, 'counterparty' => $cp, 'description' => $desc]);
        $op('TAX_OTHER', 'OOO Supplier', '5000000', '2024-03-05', 'Оплата за товар по договору, в т.ч. налог');
        $op('TAX_OTHER', 'OOO Supplier', '1000000', '2025-04-05', 'Оплата за услуги, налог включён');
        $op('TAX_OTHER', 'Давлат ғазначилиги', '300000', '2025-04-10', 'Бюджетга тўлов');
        // Один получатель — разные платежи: пеня налоговой отдельно от обычного платежа
        $op('RENT', 'OOO Landlord', '9508.77', '2022-05-10', 'Пеня по налогу');
        $op('RENT', 'OOO Landlord', '800000', '2022-06-10', 'Оплата по договору аренды');
        // Свои переводы между счетами компании не расход
        $op('OTHER', '"FLUGGI" MChJ', '5687748.25', '2024-10-03', 'П/О, ПЕРЕВОД СРЕДСТВ СО ВТОРИЧНЫХ СЧЕТОВ НА ОСНОВНОЙ СЧЕТ ПОСЛЕ ОТЧЁТА');
        $op('OTHER_INCOME', '"FLUGGI" MChJ', '5687748.25', '2024-10-03', 'П/О, ПЕРЕВОД СРЕДСТВ СО ВТОРИЧНЫХ СЧЕТОВ НА ОСНОВНОЙ СЧЕТ ПОСЛЕ ОТЧЁТА', 'IN');

        // Правила: слово «налог» в назначении платежа поставщику налогом не считается, у получателя — считается
        $rule = fn (string $cp, string $desc, string $dir = 'OUT') => BankRules::suggest(['direction' => $dir, 'purpose' => $desc, 'counterparty' => $cp, 'inn' => null], collect(), null)['category'];
        $this->assertSame('SUPPLIER', $rule('OOO Supplier', 'Оплата за товар, в т.ч. налог'));
        $this->assertSame('TAX_OTHER', $rule('Налоговый комитет', 'Платёж'));
        $this->assertSame('TAX_OTHER', $rule('Молия вазирлиги ғазначилиги', 'Платёж'));
        $this->assertSame('TAX_TURNOVER', $rule('Давлат ғазначилиги', 'Айланмадан олинадиган солиқ'));
        $this->assertSame('OTHER', $rule('OOO Supplier', 'Оборотные средства'));
        $this->assertSame('TRANSFER_OUT', $rule('X', 'ПЕРЕВОД СРЕДСТВ СО ВТОРИЧНЫХ СЧЕТОВ НА ОСНОВНОЙ СЧЕТ'));
        // Поступление на основной счёт — выручка, сам в переводы не попадает; перенос остатка по-прежнему перевод
        $this->assertSame('OTHER_INCOME', $rule('X', 'ПЕРЕВОД СРЕДСТВ СО ВТОРИЧНЫХ СЧЕТОВ НА ОСНОВНОЙ СЧЕТ', 'IN'));
        $this->assertSame('TRANSFER_IN', $rule('X', 'Перенос остатка', 'IN'));

        $g = Recategorize::groups('OUT', 'TAX_OTHER');
        $this->assertSame(['OOO Supplier', 2, '6000000.00', ['2024' => '5000000.00', '2025' => '1000000.00']], [$g[0]['cp'], $g[0]['count'], (string) $g[0]['total']->toScale(2), array_map(fn ($v) => (string) $v->toScale(2), $g[0]['years'])]);
        // Пеня и обычный платёж одного получателя — разные группы с разными подсказками
        $rent = Recategorize::groups('OUT', 'RENT');
        $this->assertSame([2, ['SUPPLIER', 'TAX_OTHER']], [$rent->count(), $rent->pluck('suggest')->sort()->values()->all()]);
        $html = $this->actingAsUser($this->accountant)->get('/accounting/recategorize?category=RENT')->assertOk()->assertSee('Landlord')->getContent();
        $this->assertDoesNotMatchRegularExpression('/(?<![\/\w])(accounting|common)\.[a-z][A-Za-z_.]+/', strip_tags($html));
        $this->get('/accounting/recategorize?direction=IN&category=OTHER_INCOME')->assertOk()->assertSee('FLUGGI');
        $this->get('/accounting/other-taxes')->assertRedirect('/accounting/recategorize?category=TAX_OTHER');

        // Применяем подсказки: пеня → прочие налоги, платёж по договору → поставщик, свои переводы → перевод (оба направления)
        $payload = fn (string $dir, string $cat) => ['direction' => $dir, 'category' => $cat, 'rows' => Recategorize::groups($dir, $cat)
            ->map(fn ($x) => ['key' => $x['key'], 'category' => $x['suggest']])->values()->all()];
        $this->post('/accounting/recategorize', $payload('OUT', 'RENT'))->assertSessionHas('ok');
        $this->post('/accounting/recategorize', $payload('OUT', 'OTHER'))->assertSessionHas('ok');
        $this->post('/accounting/recategorize', $payload('IN', 'OTHER_INCOME'))->assertSessionHas('ok');
        $this->assertSame(['TAX_OTHER', '2022-05'], [AccTransaction::where('description', 'Пеня по налогу')->value('category'), AccTransaction::where('description', 'Пеня по налогу')->value('period')]);
        $this->assertSame('SUPPLIER', AccTransaction::where('description', 'Оплата по договору аренды')->value('category'));
        $this->assertSame(['TRANSFER_OUT', 'OTHER_INCOME'], [AccTransaction::where('direction', 'OUT')->where('amount', '5687748.25')->value('category'), AccTransaction::where('direction', 'IN')->value('category')]);
        $this->assertSame(0, AccTransaction::where('category', 'RENT')->count());
        $this->assertTrue(AuditLog::where('action', 'accounting.operations.recategorize')->exists());
        // Неверная статья и чужое направление не принимаются
        $this->post('/accounting/recategorize', ['direction' => 'OUT', 'category' => 'TAX_OTHER', 'rows' => [['key' => str_repeat('a', 40), 'category' => 'NOPE']]])->assertSessionHasErrors('rows.0.category');

        foreach (['ROP', 'MANAGER'] as $role) {
            $this->actingAsUser($this->user($role));
            $this->get('/accounting/recategorize')->assertForbidden();
            $this->post('/accounting/recategorize', ['rows' => [['key' => str_repeat('a', 40), 'category' => 'OTHER']]])->assertForbidden();
        }
    }
}
