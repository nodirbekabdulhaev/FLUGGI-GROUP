<?php

namespace Tests\Feature\Todos;

use App\Models\AccAccount;
use App\Models\AccTransaction;
use App\Models\Deal;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\Payment;
use App\Services\Finance\Input;
use App\Support\Format;
use App\Support\Settings;
use Tests\Feature\Notifications\CommsFixtures;
use Tests\TestCase;

/**
 * Суммы с разделителями тысяч: браузер показывает «10 000 000», а сервер принимает и «10 000 000»,
 * и «10 000 000,50» (на случай выключенного JavaScript) на всех денежных формах.
 */
class MoneyInputTest extends TestCase
{
    use CommsFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtures();
    }

    public function test_input_normalizer_strips_regular_and_non_breaking_spaces(): void
    {
        $this->assertSame('10000000', Input::money('10 000 000'));
        $this->assertSame('10000000.50', Input::money("10\u{00A0}000\u{202F}000,50"));
        $this->assertSame('-1500.5', Input::money(' -1 500,5 '));
        $this->assertNull(Input::money('   '));
        $this->assertNull(Input::money(null));
    }

    public function test_deal_amount_and_deal_filter_accept_spaces(): void
    {
        $client = $this->client($this->manager);
        $this->actingAsUser($this->manager)->post('/sales/deals', ['client_id' => $client->id, 'title' => 'Sayt', 'amount' => '10 000 000'])->assertSessionHasNoErrors();
        $deal = Deal::where('title', 'Sayt')->firstOrFail();
        $this->assertSame('10000000.00', (string) $deal->amount);
        $this->put("/sales/deals/{$deal->id}", ['title' => 'Sayt', 'currency' => 'UZS', 'amount' => '10 000 000,50'])->assertSessionHasNoErrors();
        $this->assertSame('10000000.50', (string) $deal->fresh()->amount);
        $this->get('/sales/deals?amount_min=9 000 000&amount_max=11 000 000,00')->assertOk()->assertSessionHasNoErrors()->assertSee('Sayt');
    }

    public function test_payment_and_expense_accept_spaces(): void
    {
        $deal = $this->deal($this->client($this->manager), $this->manager, '20000000');
        $this->actingAsUser($this->manager)->post('/finance/payments', ['deal_id' => $deal->id, 'amount' => '10 000 000,50', 'currency' => 'UZS', 'type' => 'PREPAYMENT', 'method' => 'BANK'])
            ->assertSessionHasNoErrors();
        $this->assertSame('10000000.50', (string) Payment::where('deal_id', $deal->id)->value('amount'));

        $this->actingAsUser($this->ceo)->post('/finance/expenses', ['scope' => 'COMPANY', 'category' => 'SERVICES', 'amount' => '10 000 000', 'currency' => 'UZS',
            'expense_date' => Format::today()->toDateString()])->assertSessionHasNoErrors();
        $this->assertSame('10000000.00', (string) Expense::latest('created_at')->value('amount'));
    }

    public function test_accounting_operation_payroll_salaries_and_automation_threshold_accept_spaces(): void
    {
        $accountant = $this->user('ACCOUNTANT');
        $bank = AccAccount::create(['name' => 'Bank', 'kind' => 'BANK', 'currency' => 'UZS', 'opening_balance' => '0', 'crm_methods' => []]);
        $this->actingAsUser($accountant)->post('/accounting/operations', ['account_id' => $bank->id, 'date' => Format::today()->toDateString(),
            'direction' => 'IN', 'category' => 'OTHER_INCOME', 'amount' => '10 000 000,50'])->assertSessionHasNoErrors();
        $this->assertSame('10000000.50', (string) AccTransaction::where('account_id', $bank->id)->value('amount'));

        $this->actingAsUser($this->ceo)->put('/finance/payroll/salaries', ['salaries' => [$this->manager->id => '10 000 000,50']])->assertSessionHasNoErrors();
        $this->assertSame('10000000.50', (string) Employee::where('user_id', $this->manager->id)->value('base_salary'));

        $this->put('/settings/automation', ['largeAmountUzs' => '75 000 000'])->assertSessionHasNoErrors();
        Settings::forget();
        $this->assertSame(75000000, Settings::automation()['largeAmountUzs']);
    }

    /** Поля сумм не бывают type="number": в таком поле нельзя показать пробелы между тысячами. */
    public function test_views_have_no_numeric_money_inputs(): void
    {
        $money = '/(amount|price|budget|salary|sum|rate_uzs|rate_to_uzs|bonus|penalty|balance|gross|cost|fee)/i';
        $bad = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views')));
        foreach ($files as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }
            preg_match_all('/<(?:input|x-field)\b[^>]*>/s', file_get_contents($file->getPathname()), $tags);
            foreach ($tags[0] as $tag) {
                if (preg_match('/type="number"/', $tag) && preg_match('/:?name="([^"]+)"/', $tag, $m) && preg_match($money, $m[1])) {
                    $bad[] = str_replace(resource_path('views').'/', '', $file->getPathname()).': '.$m[1];
                }
                // Денежные поля помечены data-money (форматирование при вводе)
                if (preg_match('/inputmode="decimal"/', $tag) && preg_match('/:?name="([^"]+)"/', $tag, $m) && preg_match($money, $m[1]) && ! str_contains($tag, 'data-money')) {
                    $bad[] = str_replace(resource_path('views').'/', '', $file->getPathname()).': '.$m[1].' (нет data-money)';
                }
            }
        }
        $this->assertSame([], $bad);
    }

    public function test_money_inputs_render_with_data_money(): void
    {
        $deal = $this->deal($this->client($this->manager), $this->manager);
        $html = $this->actingAsUser($this->manager)->get("/sales/deals/{$deal->id}")->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/name="amount"[^>]*data-money|data-money[^>]*name="amount"/', $html);
    }
}
