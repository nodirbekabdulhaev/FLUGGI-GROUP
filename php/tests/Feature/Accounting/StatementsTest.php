<?php

namespace Tests\Feature\Accounting;

use App\Models\AccAccount;
use App\Models\AccPayroll;
use App\Models\AccPerson;
use App\Models\AccStatementLine;
use App\Models\AccTaxAct;
use App\Models\AccTransaction;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Accounting\Accounting;
use App\Services\Accounting\Statements;
use Brick\Math\BigDecimal;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Баланс и финрезультаты: загрузка сданных форм (значения в тыс. сум), перенос остатков на следующий год, автоматические строки
 * из счетов, налогов, ведомостей и выплат, ручная правка и возврат к автоматическому, итоги и сальдирующая строка, права.
 */
class StatementsTest extends TestCase
{
    private User $accountant;

    private array $tmp = [];

    protected function setUp(): void
    {
        parent::setUp();
        Accounting::forgetRates();
        Accounting::saveSettings(['taxPayLag' => 0]);
        $this->accountant = $this->user('ACCOUNTANT');
    }

    protected function tearDown(): void
    {
        foreach ($this->tmp as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    /** Файл сданной формы: форма '1' (баланс: код, на начало, на конец) или '2' (код, прошлый год доход/расход, отчётный доход/расход), значения в тыс. сум. */
    private function form(string $form, int $year, array $rows): UploadedFile
    {
        $book = new Spreadsheet;
        $l1 = $book->getActiveSheet()->setTitle('list01');
        $l1->setCellValueExplicit('B3', $form === '1' ? 'Бухгалтерия баланси №1-сонли шакл' : 'Молиявий натижалар тўгрисида хисобот - 2-сонли шакл', DataType::TYPE_STRING);
        $l1->setCellValue($form === '1' ? 'C4' : 'C5', $year);
        $l2 = $book->createSheet()->setTitle('list02');
        foreach ($rows as $i => $r) {
            $n = 6 + $i;
            $l2->setCellValueExplicit('B'.$n, 'Строка '.$r[0], DataType::TYPE_STRING);
            $l2->setCellValueExplicit('C'.$n, $r[0], DataType::TYPE_STRING);
            foreach (array_slice($r, 1) as $k => $v) {
                $col = chr(ord('D') + $k);
                $v === 'x' ? $l2->setCellValueExplicit($col.$n, 'x', DataType::TYPE_STRING) : $l2->setCellValue($col.$n, $v);
            }
        }
        $path = tempnam(sys_get_temp_dir(), 'stm');
        (new Xlsx($book))->save($path);
        $this->tmp[] = $path;

        return new UploadedFile($path, '300000001_1_0_'.$year.'_N_4_'.($form === '1' ? '20001' : '20005').'_export.xlsx', null, null, true);
    }

    private function loadFiled(): void
    {
        $this->actingAsUser($this->accountant);
        $b = $this->form('1', 2025, [['010'], ['090', 258892, 258892], ['220', 37988.42, 0], ['270', 153.41, 263], ['340', 1162.28, 5040.2], ['410', 200000, 200000],
            ['450', 91631.11, -88494.39], ['560', 0, 3802.5], ['680', 675, 0], ['720', 5890, 148887.09]]);
        $f = $this->form('2', 2025, [['010', 257092.51, 'x', 55707.5, 'x'], ['050', 'x', 135000, 'x', 216000], ['060', 'x', 27460, 'x', 19833], ['270', 94632.51, 0, 0, 180125.5]]);
        $this->post('/accounting/statements/import', ['files' => [$b, $f]])->assertSessionHasNoErrors()->assertSessionHas('ok');
    }

    public function test_filed_forms_are_loaded_and_carried_over_to_next_year(): void
    {
        $this->loadFiled();
        // Значения из формы — в сумах (в форме тыс. сум), конец 2025 и начало 2025 (= конец 2024)
        $c25 = Statements::closing(2025);
        $this->assertSame(['5040200.00', 'file', '258892000.00', '148887090.00'], [(string) $c25['340']['v'], $c25['340']['src'], (string) $c25['090']['v'], (string) $c25['720']['v']]);
        $this->assertSame('1162280.00', (string) Statements::closing(2024)['340']['v']);
        // Итоги по формуле формы: 130 = 090; 210 = 220 + 270; 390 = 210 + 320; 400 = 130 + 390
        $this->assertSame('258892000.00', (string) $c25['130']['v']);
        $this->assertSame('263000.00', (string) $c25['210']['v']);   // 220 = 0 на конец, 270 = 263
        $this->assertSame('5303200.00', (string) $c25['390']['v']);
        $this->assertSame('264195200.00', (string) $c25['400']['v']);
        $this->assertSame('3802500.00', (string) $c25['490']['v']);
        $this->assertSame('148887090.00', (string) $c25['600']['v']);
        // Финрезультаты: отчётный и прошлый год из одной формы
        $r25 = Statements::results(2025);
        $r24 = Statements::results(2024);
        $this->assertSame(['55707500.00', '216000000.00', '19833000.00', '-180125500.00'], [(string) $r25['010']['v'], (string) $r25['050']['v'], (string) $r25['060']['v'], (string) $r25['270']['v']]);
        $this->assertSame(['257092510.00', '94632510.00'], [(string) $r24['010']['v'], (string) $r24['270']['v']]);
        $this->assertTrue(AuditLog::where('action', 'accounting.statement.import')->exists());

        // 2026: уставный капитал и оборудование переносятся; зарплата за 2025 — задолженность на начало года, уменьшается выплатами
        $bank = AccAccount::create(['name' => 'Bank', 'kind' => 'BANK', 'currency' => 'UZS', 'opening_balance' => '0', 'crm_methods' => []]);
        $person = AccPerson::create(['full_name' => 'Aliyev Aziz', 'monthly_salary' => '5000000']);
        AccPayroll::create(['person_id' => $person->id, 'period' => '2026-01', 'status' => 'APPROVED', 'gross' => '5000000', 'ndfl_rate' => '7.5', 'ndfl' => '375000', 'inps_rate' => '0.1', 'inps' => '5000', 'net' => '4620000']);
        AccTransaction::create(['account_id' => $bank->id, 'direction' => 'IN', 'category' => 'CLIENT_PAYMENT', 'amount' => '20000000', 'date' => '2026-02-01']);
        AccTransaction::create(['account_id' => $bank->id, 'direction' => 'OUT', 'category' => 'SALARY', 'amount' => '3000000', 'date' => '2026-01-20', 'person_id' => $person->id, 'period' => '2026-01']);
        $c26 = Statements::closing(2026);
        $this->assertSame(['200000000.00', 'carry'], [(string) $c26['410']['v'], $c26['410']['src']]);
        $this->assertSame(['258892000.00', 'carry'], [(string) $c26['090']['v'], $c26['090']['src']]);
        // 720: 148 887 090 + 4 620 000 − 3 000 000
        $this->assertSame(['150507090.00', 'auto'], [(string) $c26['720']['v'], $c26['720']['src']]);
        // Банк: 20 000 000 − 3 000 000 по счёту (остатки считаются из счетов)
        $this->assertSame(['17000000.00', 'auto'], [(string) $c26['340']['v'], $c26['340']['src']]);
        // Баланс сходится: актив = пассив, нераспределённая прибыль — сальдирующая
        $this->assertSame([true, 'balance'], [$c26['400']['v']->isEqualTo($c26['780']['v']), $c26['450']['src']]);
        $page = Statements::page(2026);
        $this->assertSame((string) $c26['720']['v'], (string) $page['close']['720']['v']);
        $this->assertSame('5040200.00', (string) Statements::closing(2025)['340']['v']);   // 2025 из формы, а не по счетам
    }

    public function test_taxes_budget_lines_and_manual_edit_with_reset(): void
    {
        $this->loadFiled();
        AccTaxAct::create(['year' => 2026, 'tax_code' => '46', 'tax_name' => 'НДФЛ', 'as_of' => '2026-11-09', 'year_end' => false, 'closing_debt' => '0', 'closing_overpay' => '0', 'accrued' => '0', 'paid' => '0']);
        // Налоги: долг НДФЛ → 680, переплата ИНПС → 280 (баланс налогов на конец месяца)
        $bank = AccAccount::create(['name' => 'Bank', 'kind' => 'BANK', 'currency' => 'UZS', 'opening_balance' => '10000000', 'crm_methods' => []]);
        $person = AccPerson::create(['full_name' => 'Aliyev Aziz', 'monthly_salary' => '5000000']);
        AccPayroll::create(['person_id' => $person->id, 'period' => '2026-01', 'status' => 'APPROVED', 'gross' => '5000000', 'ndfl_rate' => '7.5', 'ndfl' => '375000', 'inps_rate' => '0.1', 'inps' => '5000', 'net' => '4620000']);
        AccTransaction::create(['account_id' => $bank->id, 'direction' => 'OUT', 'category' => 'TAX_INPS', 'amount' => '300000', 'date' => '2026-03-10', 'period' => '2026-03']);
        $c = Statements::closing(2026);
        // Платёж 300 000 больше начисленного ИНПС (ведомость января и оценка по окладам до сегодня) — переплата в активе; НДФЛ не оплачен — долг перед бюджетом
        $this->assertTrue($c['280']['v']->isPositive() && $c['700']['v']->isZero() && $c['680']['v']->isPositive());
        $this->assertSame('auto', $c['680']['src']);

        // Ручная правка: сохраняется, переносится на следующий год, показывает значение системы
        $this->post('/accounting/statements/line', ['year' => 2026, 'form' => 'B', 'code' => '410', 'amount' => '250 000 000,50', 'note' => 'Дополнительный взнос'])->assertSessionHasNoErrors()->assertSessionHas('ok');
        $c = Statements::closing(2026);
        $this->assertSame(['250000000.50', 'manual', 'Дополнительный взнос'], [(string) $c['410']['v'], $c['410']['src'], $c['410']['note']]);
        $this->assertSame(['250000000.50', 'carry'], [(string) Statements::closing(2027)['410']['v'], Statements::closing(2027)['410']['src']]);
        $this->assertSame('200000000.00', (string) Statements::closing(2025)['410']['v']);   // прошлый год не тронут
        // Итог пересчитан, баланс сходится
        $this->assertTrue($c['400']['v']->isEqualTo($c['780']['v']));
        // Вернуть автоматическое — снова перенос
        $this->post('/accounting/statements/line', ['year' => 2026, 'form' => 'B', 'code' => '410', 'reset' => '1'])->assertSessionHasNoErrors();
        $this->assertSame(['200000000.00', 'carry'], [(string) Statements::closing(2026)['410']['v'], Statements::closing(2026)['410']['src']]);
        $this->assertSame(0, AccStatementLine::where(['year' => 2026, 'code' => '410'])->count());
        // Итоговую строку задать нельзя
        $this->post('/accounting/statements/line', ['year' => 2026, 'form' => 'B', 'code' => '400', 'amount' => '1'])->assertSessionHas('error');
        $this->assertTrue(AuditLog::where('action', 'accounting.statement.set')->exists());

        // Финрезультаты: ручная строка расходов по реализации, итоги и чистая прибыль
        $before = Statements::results(2026)['270']['v'];
        $this->post('/accounting/statements/line', ['year' => 2026, 'form' => 'F', 'code' => '050', 'amount' => '1000000'])->assertSessionHasNoErrors();
        $r = Statements::results(2026);
        $this->assertSame(['1000000.00', 'manual'], [(string) $r['050']['v'], $r['050']['src']]);
        $this->assertSame((string) $before->minus(1000000)->toScale(2), (string) $r['270']['v']->toScale(2));   // расход по реализации уменьшил чистую прибыль
    }

    public function test_pages_in_all_languages_and_permissions(): void
    {
        $this->loadFiled();
        foreach (['uz', 'ru', 'en'] as $locale) {
            $this->accountant->update(['locale' => $locale]);
            $this->actingAsUser($this->accountant);
            foreach (['/accounting/statements?year=2025', '/accounting/statements?year=2025&form=2', '/accounting/statements?year=2026&unit=k', '/accounting/statements?year=2026&form=2'] as $url) {
                $html = $this->get($url)->assertOk()->getContent();
                $this->assertDoesNotMatchRegularExpression('/(?<![\\/\\w])(accounting|common|import)\\.[a-z][A-Za-z_.]+/', strip_tags($html), "$locale $url: ключ без перевода");
            }
        }
        $this->accountant->update(['locale' => 'ru']);
        $this->actingAsUser($this->accountant);
        $this->get('/accounting/statements?year=2025')->assertSee('Задолженность по оплате труда')->assertSee('5 040 200,00')->assertSee('Сданная форма');
        $this->get('/accounting/statements?year=2025&unit=k')->assertSee('5 040,20');
        // Не форма
        $this->post('/accounting/statements/import', ['files' => [UploadedFile::fake()->create('x.xlsx', 1)]])->assertSessionHas('error');
        foreach (['ROP', 'MANAGER'] as $role) {
            $this->actingAsUser($this->user($role));
            $this->get('/accounting/statements')->assertForbidden();
            $this->post('/accounting/statements/line', ['year' => 2026, 'form' => 'B', 'code' => '410', 'amount' => '1'])->assertForbidden();
            $this->post('/accounting/statements/import', ['files' => []])->assertForbidden();
        }
        $this->assertTrue(BigDecimal::of('1')->isPositive());
    }
}
