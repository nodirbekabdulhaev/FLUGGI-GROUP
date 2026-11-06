<?php

namespace Tests\Feature\Accounting;

use App\Models\AccAccount;
use App\Models\AccCategory;
use App\Models\AccTransaction;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use App\Services\Accounting\Accounting;
use App\Services\Accounting\AccountsService;
use App\Services\Accounting\BankRules;
use App\Services\Accounting\BankStatement;
use App\Services\Accounting\TaxesService;
use App\Support\Settings;
use Brick\Math\BigDecimal;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/** Загрузка банковской выписки: оба формата, направление, дубли, автокатегории, счёт по номеру, права, аудит. */
class BankImportTest extends TestCase
{
    private const NUMBER = '20208000900000000001';

    private User $accountant;

    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        Accounting::forgetRates();
        $this->accountant = $this->user('ACCOUNTANT');
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    private function account(array $attrs = []): AccAccount
    {
        return AccAccount::create($attrs + ['name' => 'Bank '.uniqid(), 'kind' => 'BANK', 'currency' => 'UZS', 'opening_balance' => '0', 'crm_methods' => []]);
    }

    /** Файл XLSX из строк (вымышленные данные). */
    private function xlsx(array $rows, string $sheet = 'Page 1'): string
    {
        $book = new Spreadsheet;
        $ws = $book->getActiveSheet()->setTitle($sheet);
        $ws->fromArray($rows, null, 'A1', true);
        $path = tempnam(sys_get_temp_dir(), 'bank').'.xlsx';
        (new Xlsx($book))->save($path);
        $this->files[] = $path;

        return $path;
    }

    private function upload(string $path, string $name, ?string $accountId = null)
    {
        return $this->post('/accounting/bank-import', ['file' => new UploadedFile($path, $name, null, null, true), 'account_id' => $accountId]);
    }

    private function token($response): string
    {
        $this->assertMatchesRegularExpression('#/accounting/bank-import/([A-Za-z0-9]{40})$#', $response->headers->get('Location'));

        return basename($response->headers->get('Location'));
    }

    /** «Выписка лицевых счетов»: шапка, таблица с переносами строк в заголовках, итог. */
    private function classic(array $extra = []): string
    {
        $rows = [
            [null], ['Выписка лицевых счетов'], ['за 01.08.2026 по 30.09.2026'], [null],
            ['№ счёта '.self::NUMBER, null, null, 'Ответисполнитель TEST'],
            ['Наименование счёта "TEST COMPANY" MCHJ'],
            ['Остаток:', 'Начало дня', 0, 'Конец дня', 9000000],
            [null],
            ["№\nпп", "Дата\nдокумента", "Дата\nобработки", '№ док', 'Наименование счёта', 'ИНН', '№ счёта', 'МФО', "Обороты по\nдебету", "Обороты по\nкредиту", 'Назначение платежа'],
            [1, '10.08.2026', '10.08.2026', '9001', 'Счет миграции для филиала', '', '19997000900000000001', '01000', 0, 5000000, 'Сворачивание/разворачивание остатка при переносе счета на другой филиал'],
            [2, '11.08.2026', '11.08.2026', '11', 'Молия вазирлиги ?азначилиги', '200000001', '23402000300100001010', '00014', 3000, 0, '~100~ 46 Жисмоний шахслар даромадидан олинадиган соли? учун олдиндан тўлов $TAX_ID$1'],
            [3, '11.08.2026', '11.08.2026', '12', 'Молия вазирлиги ?азначилиги', '200000001', '23402000300100001010', '00014', 500, 0, '199 СОРЖ га ундириладиган 40% пеня учун олдиндан тўлов $TAX_ID$2'],
            [4, '14.08.2026', '14.08.2026', '13', 'ITPARK UZBEKISTAN', '300000002', '20208000000000000002', '00401', 20000, 0, '00111 IT Park rezidenti tomonidan 1%  ajratma'],
            [5, '14.08.2026', '14.08.2026', '14', 'Начисленные %% "TEST COMPANY" MCHJ', '300000009', '16401000000000000001', '01000', 1120, 0, 'За документ №13 Тариф-ТАРИФ "CORPORATE"-1 120,00 UZS'],
            [6, '19.08.2026', '19.08.2026', '15', 'Казначейство Министерства финансов', '200000001', '23402000300100001010', '00014', 140000, 0, 'Задолженность по Налог на доходы с физических лиц втч пеня: 0 $BUDJET$300000009'],
            [7, '20.08.2026', '20.08.2026', '16', 'Шахар ДСИ', '200000003', '22628000000000000003', '01125', 19400, 0, '101 Фу?ароларнинг жам?ариб бориладиган пенсия бадалига ажратма учун олдиндан тўлов'],
            [8, '20.08.2026', '20.08.2026', '17', 'Шахар ДСИ', '200000003', '22628000000000000003', '01125', 19400, 0, '101 Фу?ароларнинг жам?ариб бориладиган пенсия бадалига ажратма учун олдиндан тўлов'],
            [9, '27.08.2026', '27.08.2026', '18', '21*"TEST COMPANY" MCHJ', '300000009', '23106000000000000009', '01000', 1250000, 0, '00634 Аванс август 2026'],
            [10, '05.09.2026', '05.09.2026', '19', '"ALFA SAVDO" MCHJ', '300000010', '20208000000000000010', '00401', 0, 4000000, '00111 05.09.2026 йилдаги №7 шартномага асосан дизайн хизматлари учун тулов. Итого без НДС'],
            [11, '07.09.2026', '07.09.2026', '20', 'Молия вазирлиги ?азначилиги', '200000001', '23402000300100001010', '00014', 1, 0, '36 Ижтимоий соли? учун олдиндан тўлов'],
            [12, '08.09.2026', '08.09.2026', '21', '"TEST COMPANY" MChJ', '300000009', '20208000000000000099', '00401', 0, 1000000, 'П/О, ASOSIY XISOB RAQAMNI TOLDIRISH UCHUN'],
            [13, '09.09.2026', '09.09.2026', '22', 'Частное лицо', '', '', '', 0, 1500, 'Возврат переплаты'],
            [14, '09.09.2026', '09.09.2026', '23', 'Мебель МЧЖ', '300000011', '20208000000000000011', '00401', 500000, 0, 'Оплата за стулья по договору №5'],
            ...$extra,
            [15, 'Итого за весь период', null, null, null, null, null, null, 0, 0, 'Входящее сальдо: 0.00'],
            [null], ['Выписка лицевых счетов за 01.08.2026 по 30.09.2026'],
        ];

        return $this->xlsx($rows);
    }

    private function app(): string
    {
        return $this->xlsx([
            ['Raqami', 'Turi', 'Holati', 'Summa', 'Sana', "To'lovchi", 'Qabul qiluvchi', 'Tafsilotlar'],
            ['000000002211', 'Chiqim', "O'tkazilgan", 4000000, '2026-10-08 00:00', '"TEST COMPANY" MChJ', 'VALIYEV ALI', 'П/О, ЗАРПЛАТА 8600000000000000 VALIYEV ALI OKTYABR OYI AVANS'],
            ['674164', 'Kirim', "O'tkazilgan", 1600000, '2026-10-07 00:00', '"OMEGA TEXTILE" MChJ', '"TEST COMPANY" MCHJ', '00111 №F-10 шартномага асосан Дастурлаш хизматлари учун тулов'],
            ['602434', 'Kirim', "O'tkazilgan", 4000000, '2026-10-06 00:00', 'OOO "BETA GROUP"', 'OOO "TEST COMPANY"', '00111 за услуги программирования к договору № F-01'],
            ['602500', 'Kirim', 'Bekor qilingan', 999000, '2026-10-06 00:00', 'OOO "BETA GROUP"', 'OOO "TEST COMPANY"', 'отменён'],
            ['602501', 'Chiqim', "O'tkazilgan", 75000, '2026-10-06 00:00', '"TEST COMPANY" MChJ', 'Ofis MChJ', 'Ofis uchun suv'],
        ], 'Operatsiyalar');
    }

    /** Пользователь только с просмотром бухгалтерии. */
    private function reader(): User
    {
        DB::table('role_permissions')->insertOrIgnore(['role_id' => Role::where('code', 'HR_ADMIN')->value('id'),
            'permission_id' => DB::table('permissions')->where('code', 'accounting.read')->value('id'), 'scope' => 'ALL']);

        return $this->user('HR_ADMIN');
    }

    public function test_parsers_read_both_formats_with_direction_and_completed_rows(): void
    {
        $a = BankStatement::read($this->classic(), 'xlsx');
        $this->assertSame(['CLASSIC', self::NUMBER, '0.00', '9000000.00', '2026-08-01', '2026-09-30'], [$a['format'], $a['accountNumber'], $a['opening'], $a['closing'], $a['from'], $a['to']]);
        $this->assertCount(14, $a['rows']);   // строка «Итого» и подвал не попадают
        $this->assertSame(['2026-08-10', 'IN', '5000000.00', '9001'], [$a['rows'][0]['date'], $a['rows'][0]['direction'], $a['rows'][0]['amount'], $a['rows'][0]['doc']]);
        $this->assertSame(['OUT', '3000.00', '200000001'], [$a['rows'][1]['direction'], $a['rows'][1]['amount'], $a['rows'][1]['inn']]);
        // «Итого» в назначении платежа строку с датой не обрывает
        $this->assertSame('IN', $a['rows'][9]['direction']);

        $b = BankStatement::read($this->app(), 'xlsx');
        $this->assertSame(['APP', null, 1], [$b['format'], $b['accountNumber'], $b['notCompleted']]);
        $this->assertSame([['2026-10-08', 'OUT', '4000000.00', 'VALIYEV ALI'], ['2026-10-07', 'IN', '1600000.00', '"OMEGA TEXTILE" MChJ']],
            array_map(fn ($r) => [$r['date'], $r['direction'], $r['amount'], $r['counterparty']], array_slice($b['rows'], 0, 2)));
        $this->assertCount(4, $b['rows']);

        // CSV (разделитель «;») тоже читается
        $csv = tempnam(sys_get_temp_dir(), 'bank');
        $this->files[] = $csv;
        file_put_contents($csv, "Raqami;Turi;Holati;Summa;Sana;To'lovchi;Qabul qiluvchi;Tafsilotlar\n1;Kirim;O'tkazilgan;1 500 000,50;07.10.2026;A;B;x\n");
        $c = BankStatement::read($csv, 'csv');
        $this->assertSame(['IN', '1500000.50', '2026-10-07'], [$c['rows'][0]['direction'], $c['rows'][0]['amount'], $c['rows'][0]['date']]);
    }

    public function test_auto_category_rules(): void
    {
        $s = fn (string $dir, string $purpose, string $cp = '', ?string $inn = null, $articles = null) => BankRules::suggest(
            ['direction' => $dir, 'purpose' => $purpose, 'counterparty' => $cp, 'inn' => $inn], $articles ?? collect(), '300000009')['category'];
        $this->assertSame('TAX_ITPARK', $s('OUT', '00111 IT Park rezidenti tomonidan 1% ajratma', 'ITPARK UZBEKISTAN'));
        $this->assertSame('TAX_NDFL', $s('OUT', '46 Жисмоний шахслар даромадидан олинадиган соли? учун'));
        $this->assertSame('TAX_NDFL', $s('OUT', 'Задолженность по Налог на доходы с физических лиц втч пеня: 0 $BUDJET$1'));
        $this->assertSame('TAX_INPS', $s('OUT', '101 Фу?ароларнинг жам?ариб бориладиган пенсия бадалига ажратма'));
        $this->assertSame('TAX_OTHER', $s('OUT', '199 СОРЖ га ундириладиган 40% пеня учун'));
        $this->assertSame('TAX_OTHER', $s('OUT', '36 Ижтимоий соли? учун олдиндан тўлов'));
        $this->assertSame('BANK_FEE', $s('OUT', 'За документ №1 Тариф-ТАРИФ "CORPORATE"-0,1%.', 'Начисленные %% "TEST" MCHJ', '300000009'));
        $this->assertSame('SALARY', $s('OUT', '00634 Аванс август 2026', '21*"TEST"', '300000009'));
        $this->assertSame('SALARY', $s('OUT', 'П/О, ЗАРПЛАТА OKTYABR OYI'));
        $this->assertSame('SUPPLIER', $s('OUT', 'Оплата по договору №5'));
        $this->assertSame('OTHER', $s('OUT', 'Ofis uchun suv'));
        $this->assertSame('CLIENT_PAYMENT', $s('IN', '00111 шартномага асосан дизайн хизматлари учун тулов'));
        $this->assertSame('CLIENT_PAYMENT', $s('IN', 'за услуги программирования к договору № F-01'));
        $this->assertSame('TRANSFER_IN', $s('IN', 'Сворачивание/разворачивание остатка при переносе счета'));
        $this->assertSame('TRANSFER_IN', $s('IN', 'Пополнение', '"TEST" MChJ', '300000009'));     // свой ИНН
        $this->assertSame('TRANSFER_IN', $s('IN', 'ASOSIY XISOB RAQAMNI TOLDIRISH UCHUN'));
        $this->assertSame('OTHER_INCOME', $s('IN', 'Возврат переплаты'));
        // Своя статья с ключевым словом важнее встроенных правил
        $art = new AccCategory(['direction' => 'OUT', 'name' => 'Ombor', 'base' => 'RENT', 'keywords' => 'itpark, склад']);
        $art->id = '00000000-0000-4000-8000-000000000001';
        $this->assertSame('RENT', $s('OUT', 'IT Park 1%', 'ITPARK', null, collect([$art])));
        $this->assertSame('OTHER_INCOME', $s('IN', 'IT Park', 'ITPARK', null, collect([$art])));   // статья другого направления не подходит
    }

    public function test_classic_statement_import_matches_account_and_skips_duplicates(): void
    {
        Settings::save('company', ['inn' => '300000009'] + Settings::company());
        $bank = $this->account(['name' => 'Asosiy', 'account_number' => self::NUMBER, 'opening_balance' => '0', 'opening_date' => '2026-08-01']);
        $this->account(['name' => 'Kassa', 'kind' => 'CASH']);
        // Вручную уже внесён расход 500 000 за стулья — строка выписки станет «возможным дублем»
        AccTransaction::create(['account_id' => $bank->id, 'direction' => 'OUT', 'category' => 'SUPPLIER', 'amount' => '500000', 'date' => '2026-09-09', 'counterparty' => 'Мебель']);

        $this->actingAsUser($this->accountant);
        $this->get('/accounting/bank-import')->assertOk()->assertSee(t('accounting.bank.autoAccount'));
        $this->get('/accounting/operations')->assertSee(t('accounting.bank.button'));
        $token = $this->token($this->upload($this->classic(), 'vypiska.xlsx'));
        $page = $this->get('/accounting/bank-import/'.$token)->assertOk()->assertSee(t('accounting.bank.matched'));
        $this->assertSame($bank->id, $page->viewData('account')->id);
        $rows = collect($page->viewData('rows'));
        $this->assertSame(['new' => 13, 'duplicate' => 0, 'possible' => 1, 'error' => 0], $page->viewData('totals'));
        $this->assertSame([
            'TRANSFER_IN', 'TAX_NDFL', 'TAX_OTHER', 'TAX_ITPARK', 'BANK_FEE', 'TAX_NDFL', 'TAX_INPS', 'TAX_INPS', 'SALARY',
            'CLIENT_PAYMENT', 'TAX_OTHER', 'TRANSFER_IN', 'OTHER_INCOME', 'SUPPLIER',
        ], $rows->pluck('category')->all());

        // Подтверждение: строка 10 — другая категория, НДФЛ за июль, возможный дубль не отмечен
        $payload = [9 => ['on' => true, 'category' => 'OTHER_INCOME', 'period' => '2026-08'], 1 => ['on' => true, 'category' => 'TAX_NDFL', 'period' => '2026-07'], 13 => ['on' => false, 'category' => 'SUPPLIER', 'period' => '']];
        $this->post('/accounting/bank-import/'.$token.'/confirm', ['payload' => json_encode($payload)])->assertRedirect('/accounting/bank-import/'.$token.'/result');
        $imported = AccTransaction::where('source', 'BANK_IMPORT')->orderBy('date')->get();
        $this->assertCount(13, $imported);
        $this->assertSame(13, $imported->pluck('external_ref')->unique()->count());
        $this->assertSame(['IN', 'TRANSFER_IN', '5000000.00'], [$imported[0]->direction, $imported[0]->category, (string) $imported[0]->amount]);
        $this->assertSame('2026-07', $imported->firstWhere('amount', '3000.00')->period);
        $this->assertSame('2026-08', $imported->firstWhere('category', 'TAX_ITPARK')->period);
        $this->assertSame('OTHER_INCOME', $imported->firstWhere('amount', '4000000.00')->category);
        $this->assertStringContainsString('200000001', $imported->firstWhere('amount', '3000.00')->description);
        $this->assertSame(0, AccTransaction::where('source', 'BANK_IMPORT')->where('amount', '500000')->count());
        // Перенос остатка и пополнение своего счёта — не доход и не база IT Park
        $this->assertSame('4001500.00', TaxesService::bankIncome('2026-09')['total']->toScale(2)->__toString());
        $this->assertSame('0.00', (string) TaxesService::bankIncome('2026-08')['total']->toScale(2));

        $audit = AuditLog::where('action', 'accounting.bank_import')->where('entity_id', $bank->id)->firstOrFail();
        $this->assertSame(['vypiska.xlsx', 13, 1, 'BANK_IMPORT'], [$audit->changes['import']['new']['file'], $audit->changes['import']['new']['created'], $audit->changes['import']['new']['unchecked'], $audit->changes['import']['new']['source']]);

        // Итог: сверка остатка с «Конец дня»
        $res = $this->get('/accounting/bank-import/'.$token.'/result')->assertOk();
        $check = $res->viewData('result')['check'];
        $this->assertSame(['9000000.00', AccountsService::balance($bank->id)], [$check['statement'], $check['account']]);
        $this->assertSame((string) BigDecimal::of($check['account'])->minus('9000000')->toScale(2), $check['difference']);
        // Повторная отправка той же формы ничего не добавляет
        $this->post('/accounting/bank-import/'.$token.'/confirm', ['payload' => '{}'])->assertRedirect('/accounting/bank-import/'.$token.'/result');
        $this->assertSame(13, AccTransaction::where('source', 'BANK_IMPORT')->count());

        // Повторная загрузка пересекающейся выписки: старые строки — «уже загружены», новая — добавляется
        $extra = [[15, '30.09.2026', '30.09.2026', '24', 'ITPARK UZBEKISTAN', '300000002', '20208000000000000002', '00401', 40000, 0, 'IT Park rezidenti 1% ajratma']];
        $token2 = $this->token($this->upload($this->classic($extra), 'vypiska2.xlsx'));
        $page2 = $this->get('/accounting/bank-import/'.$token2)->assertOk();
        $this->assertSame(['new' => 1, 'duplicate' => 13, 'possible' => 1, 'error' => 0], $page2->viewData('totals'));
        $this->post('/accounting/bank-import/'.$token2.'/confirm', ['payload' => ''])->assertRedirect();
        $this->assertSame(14, AccTransaction::where('source', 'BANK_IMPORT')->count());
        $this->assertSame(1, AccTransaction::where('source', 'BANK_IMPORT')->where('amount', '40000')->count());

        // Удалённая импортированная операция при новой загрузке не возвращается
        AccTransaction::where('source', 'BANK_IMPORT')->where('amount', '40000')->first()->delete();
        $token3 = $this->token($this->upload($this->classic($extra), 'vypiska3.xlsx'));
        $this->assertSame(14, $this->get('/accounting/bank-import/'.$token3)->viewData('totals')['duplicate']);
    }

    public function test_app_export_account_choice_articles_and_errors(): void
    {
        $bank = $this->account(['name' => 'Ilova', 'opening_date' => '2026-10-07']);
        $other = $this->account(['name' => 'Boshqa']);
        $art = AccCategory::create(['direction' => 'IN', 'name' => 'Dasturlash', 'base' => 'CLIENT_PAYMENT', 'keywords' => 'дастурлаш, программирован', 'is_active' => true, 'sort' => 0]);

        $this->actingAsUser($this->accountant);
        // Номера счёта нет — счёт не выбран, загрузить нельзя
        $token = $this->token($this->upload($this->app(), 'operatsiyalar.xlsx'));
        $page = $this->get('/accounting/bank-import/'.$token)->assertOk()->assertSee(t('accounting.bank.chooseAccount'));
        $this->assertNull($page->viewData('account'));
        $this->post('/accounting/bank-import/'.$token.'/confirm', ['payload' => '{}'])->assertSessionHasErrors('account_id');
        $this->assertSame(0, AccTransaction::count());

        // Выбор счёта — новый предпросмотр; строка до даты начального остатка — ошибка
        $res = $this->post('/accounting/bank-import/'.$token.'/account', ['account_id' => $bank->id]);
        $token2 = $this->token($res);
        $this->assertNotSame($token, $token2);
        $page = $this->get('/accounting/bank-import/'.$token2)->assertOk();
        $rows = collect($page->viewData('rows'));
        $this->assertSame(['SALARY', $art->id, $art->id, 'OTHER'], $rows->pluck('category')->all());
        $this->assertSame(['new', 'new', 'error', 'error'], $rows->pluck('status')->all());
        $this->assertSame(1, $page->viewData('data')['notCompleted']);

        // Своя статья выбрана вручную; статья чужого направления — ошибка строки
        $payload = [0 => ['on' => true, 'category' => $art->id], 1 => ['on' => true, 'category' => $art->id]];
        $this->post('/accounting/bank-import/'.$token2.'/confirm', ['payload' => json_encode($payload)]);
        $result = $this->get('/accounting/bank-import/'.$token2.'/result')->assertOk()->viewData('result');
        $this->assertSame([1, 3], [$result['totals']['created'], $result['totals']['error']]);
        $this->assertNull($result['check']);
        $row = AccTransaction::where('source', 'BANK_IMPORT')->firstOrFail();
        $this->assertSame(['IN', 'CLIENT_PAYMENT', $art->id, '1600000.00', $bank->id], [$row->direction, $row->category, $row->category_id, (string) $row->amount, $row->account_id]);
        $this->assertSame(0, AccTransaction::where('account_id', $other->id)->count());
        $this->get('/accounting/operations?account='.$bank->id)->assertOk()->assertSee('Dasturlash')->assertSee(t('accounting.operations.fromBank'));

        // Статья с ключевыми словами: слова сохраняются без повторов, в нижнем регистре
        $this->post('/accounting/categories', ['direction' => 'OUT', 'name' => 'Ombor', 'base' => 'RENT', 'keywords' => "Склад, склад\nChilonzor"])->assertSessionHasNoErrors();
        $this->assertSame('склад, chilonzor', AccCategory::where('name', 'Ombor')->value('keywords'));

        // Неизвестный файл и чужой пользователь
        $bad = $this->xlsx([['Foo', 'Bar'], [1, 2]]);
        $this->upload($bad, 'x.xlsx')->assertSessionHasErrors('file');
        $this->upload($bad, 'x.pdf')->assertSessionHasErrors('file');
        $this->actingAsUser($this->user('ACCOUNTANT'))->get('/accounting/bank-import/'.$token2)->assertRedirect('/accounting/bank-import');
    }

    public function test_permissions(): void
    {
        $bank = $this->account(['account_number' => self::NUMBER]);
        foreach ([$this->reader(), $this->user('MANAGER'), $this->user('ROP')] as $u) {
            $this->actingAsUser($u);
            $this->get('/accounting/bank-import')->assertForbidden();
            $this->upload($this->classic(), 'v.xlsx', $bank->id)->assertForbidden();
            $this->post('/accounting/bank-import/'.str_repeat('a', 40).'/confirm')->assertForbidden();
            $this->get('/accounting/bank-import/'.str_repeat('a', 40).'/result')->assertForbidden();
        }
        $this->assertSame(0, AccTransaction::count());
        $this->assertSame(0, AuditLog::where('action', 'accounting.bank_import')->count());
    }
}
