<?php

namespace Tests\Feature\Accounting;

use App\Models\AccAccount;
use App\Models\AccPerson;
use App\Models\AccTransaction;
use App\Models\AuditLog;
use App\Services\Accounting\Accounting;
use App\Services\Accounting\AnnualReport;
use App\Services\Accounting\TaxPayrollService;
use Tests\TestCase;

/** Годовой отчёт для налоговой: форма №2 по данным бухгалтерии, баланс сходится с прибылью года. */
class AnnualReportTest extends TestCase
{
    private function account(array $attrs): AccAccount
    {
        return AccAccount::create($attrs + ['currency' => 'UZS', 'opening_balance' => '0', 'crm_methods' => []]);
    }

    private function op(AccAccount $acc, string $dir, string $cat, string $amount, string $date, array $extra = []): void
    {
        AccTransaction::create($extra + ['account_id' => $acc->id, 'direction' => $dir, 'category' => $cat, 'amount' => $amount, 'date' => $date]);
    }

    public function test_annual_report_forms_and_download(): void
    {
        Accounting::forgetRates();
        $accountant = $this->user('ACCOUNTANT');
        $this->as($accountant);
        $bank = $this->account(['name' => 'Bank', 'kind' => 'BANK', 'opening_balance' => '5000000', 'opening_date' => '2025-12-31']);
        $cash = $this->account(['name' => 'Kassa', 'kind' => 'CASH', 'opening_balance' => '1000000', 'opening_date' => '2025-12-31']);
        $person = AccPerson::create(['full_name' => 'Aliyev Aziz', 'monthly_salary' => '10000000', 'hired_at' => '2026-01-01', 'fired_at' => '2026-01-31']);

        // Январь 2026: выручка 40 млн на банк, возврат 2 млн, прочий доход 1 млн, расходы
        $this->op($bank, 'IN', 'CLIENT_PAYMENT', '40000000', '2026-01-10');
        $this->op($bank, 'OUT', 'CLIENT_PAYMENT', '2000000', '2026-01-12');
        $this->op($bank, 'IN', 'OTHER_INCOME', '1000000', '2026-01-15');
        $this->op($bank, 'OUT', 'SUPPLIER', '3000000', '2026-01-20', ['counterparty' => 'Audit MChJ']);
        $this->op($cash, 'OUT', 'RENT', '2000000', '2026-01-25');
        $this->op($cash, 'OUT', 'UTILITIES', '300000', '2026-01-26');
        $this->op($bank, 'OUT', 'BANK_FEE', '50000', '2026-01-31');
        TaxPayrollService::calculate('2026-01');   // 10 млн: НДФЛ 750 000, ИНПС 10 000, на руки 9 240 000
        $this->op($bank, 'OUT', 'SALARY', '9240000', '2026-02-05', ['person_id' => $person->id, 'period' => '2026-01']);
        $this->op($bank, 'OUT', 'TAX_NDFL', '750000', '2026-02-10', ['period' => '2026-01']);
        // ИНПС и IT Park (1 % от 41 млн = 410 000) не оплачены — остаются задолженностью

        $lines = collect(AnnualReport::results(2026))->mapWithKeys(fn ($r) => [$r[0] => (string) $r[2]->toScale(2)]);
        $this->assertSame('38000000.00', $lines['010']);             // 40 − 2 (возврат)
        $this->assertSame('3000000.00', $lines['020']);
        $this->assertSame('12300000.00', $lines['060']);             // ФОТ 10 млн + аренда 2 млн + вода/Wi-Fi 0,3 млн
        $this->assertSame('460000.00', $lines['070']);               // IT Park 410 000 + банк 50 000
        $this->assertSame('1000000.00', $lines['090']);
        $this->assertSame('23240000.00', $lines['270']);             // 35 − 12,76 + 1

        $b = AnnualReport::balance(2026)['rows'];
        $find = fn ($acc, $name = null) => collect($b)->first(fn ($r) => $r[0] === $acc && ($name === null || str_contains($r[1], $name)));
        $equity = $find('8710');
        $this->assertSame('6000000.00', (string) $equity[2]->toScale(2));   // на 01.01: остатки 5 + 1 млн
        // Прирост собственных средств за год = чистая прибыль (баланс сходится с формой №2)
        $this->assertSame($lines['270'], (string) $equity[3]->minus($equity[2])->toScale(2));
        $this->assertSame(['10000.00', '410000.00', '0.00', '0.00'], [
            (string) $find('6520')[3]->toScale(2), (string) $find('6990')[3]->toScale(2), (string) $find('6410')[3]->toScale(2), (string) $find('6710')[3]->toScale(2),
        ]);

        // Скачивание: бухгалтер и CEO — да, РОП — нет
        $res = $this->actingAsUser($accountant)->get('/accounting/annual-report?year=2026')->assertOk();
        $this->assertStringContainsString('fluggi-annual-report-2026.xlsx', $res->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('PK', $res->getContent());
        $this->assertTrue(AuditLog::where('entity_type', 'acc_annual_report')->exists());
        $this->actingAsUser($this->user('ROP'))->get('/accounting/annual-report?year=2026')->assertForbidden();
        $this->actingAsUser($accountant)->get('/accounting/taxes')->assertOk()->assertSee(t('accounting.annual.button'));
    }
}
