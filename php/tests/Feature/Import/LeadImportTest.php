<?php

namespace Tests\Feature\Import;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Lead;
use App\Models\OutboxEvent;
use App\Models\SmmIdea;
use App\Models\StageHistory;
use App\Services\Import\LeadImporter;
use App\Services\Import\RowError;
use App\Services\Import\Values;
use App\Support\Format;

class LeadImportTest extends ImportTestCase
{
    private const HEADER = ['Название', 'Имя контакта', 'Компания', 'Телефон *', 'Email', 'Instagram', 'Источник *', 'Услуга *', 'Бюджет', 'Валюта', 'Желаемая дата', 'Ответственный (email)', 'Ролик SMM'];

    private function row(array $v): array
    {
        $v += array_fill_keys(range(0, 12), null);
        ksort($v);

        return array_values($v);
    }

    public function test_xlsx_preview_counts_reasons_and_import_like_manual_creation(): void
    {
        $existing = $this->newLead($this->manager, ['phone' => '+998 90 111-22-33', 'contact_name' => 'Old', 'company_name' => null]);
        $before = Lead::count();
        $date = Format::today()->addDays(10)->toDateString();
        $file = $this->xlsx([
            self::HEADER,
            // 2: корректная строка, дата — настоящая ячейка Excel, сумма с пробелами
            $this->row([1 => 'Ali', 2 => 'Ali Foods', 3 => '998901234500', 4 => 'ALI@foods.uz', 6 => 'Instagram', 7 => 'SMM', 8 => '12 500 000', 9 => 'UZS', 10 => ['date' => $date]]),
            // 3: дубль существующего лида по последним 9 цифрам телефона
            $this->row([1 => 'Dup', 3 => '90 111 22 33', 6 => 'INSTAGRAM', 7 => 'SMM']),
            // 4: дубль строки 2 внутри файла (email без учёта регистра)
            $this->row([1 => 'Ali 2', 3 => '+998 93 000-00-01', 4 => 'ali@FOODS.uz', 6 => 'Instagram', 7 => 'SMM']),
            // 5: нет источника
            $this->row([1 => 'No source', 3 => '+998 93 000-00-02', 7 => 'SMM']),
            // 6: источник по-узбекски, услуга по-английски, дата ДД.ММ.ГГГГ
            $this->row([2 => 'Sayt LLC', 3 => '+998 93 000-00-03', 6 => 'Tavsiya', 7 => 'Website', 10 => '01.12.2026', 9 => 'USD', 8 => '1500']),
            // 7: «Видео SMM» без ролика
            $this->row([1 => 'Video', 3 => '+998 93 000-00-04', 6 => 'SMM_VIDEO', 7 => 'SMM']),
            // 8: неверная дата
            $this->row([1 => 'Bad date', 3 => '+998 93 000-00-05', 6 => 'Instagram', 7 => 'SMM', 10 => '31.02.2026']),
            // 9: ни имени, ни компании
            $this->row([3 => '+998 93 000-00-06', 6 => 'Instagram', 7 => 'SMM']),
        ]);

        $token = $this->previewToken($this->manager, 'leads', $file);
        $p = $this->preview($this->manager, 'leads', $token);
        $this->assertSame('create', $p[2]['status']);
        $this->assertSame('duplicate', $p[3]['status']);
        $this->assertStringContainsString(Format::code('L', $existing->number), $p[3]['reason']);
        $this->assertSame('duplicate', $p[4]['status']);
        $this->assertStringContainsString('2', $p[4]['reason']);
        $this->assertSame('error', $p[5]['status']);
        $this->assertSame('Не заполнено: Источник', $p[5]['reason']);
        $this->assertSame('create', $p[6]['status']);
        $this->assertSame('error', $p[7]['status']);
        $this->assertSame(t('smm.errors.videoRequired'), $p[7]['reason']);
        $this->assertSame('error', $p[8]['status']);
        $this->assertStringContainsString('31.02.2026', $p[8]['reason']);
        $this->assertSame(t('leads.errors.nameOrCompany'), $p[9]['reason']);
        $this->assertSame($before, Lead::count(), 'предпросмотр ничего не создаёт');

        $this->get("/import/leads/preview/$token")->assertOk()
            ->assertSee('Будет создано')->assertSee('Пропуск: дубли')->assertSee('Не заполнено: Источник')->assertSee('Импортировать (2)');

        $rows = $this->confirm($this->manager, 'leads', $token);
        $this->assertSame('created', $rows[2]['status']);
        $this->assertSame('created', $rows[6]['status']);
        $this->assertSame($before + 2, Lead::count());

        $lead = Lead::where('contact_name', 'Ali')->firstOrFail();
        $this->assertSame($rows[2]['code'], Format::code('L', $lead->number));
        $this->assertSame($this->manager->id, $lead->owner_id, 'менеджер — ответственный по умолчанию');
        $this->assertSame($this->team1->id, $lead->team_id);
        $this->assertSame('12500000.00', (string) $lead->budget);
        $this->assertSame('ali@foods.uz', $lead->email);
        $this->assertSame($date, $lead->desired_date->format('Y-m-d'));
        $this->assertSame('NEW', $lead->stage->code);
        $this->assertGreaterThan(0, $lead->score, 'скоринг пересчитан');
        $this->assertSame(1, StageHistory::where('lead_id', $lead->id)->count());
        $this->assertTrue(AuditLog::where('action', 'lead.create')->where('entity_id', $lead->id)->exists());
        $this->assertTrue(OutboxEvent::where('type', 'lead.created')->where('payload->leadId', $lead->id)->exists());

        $usd = Lead::where('company_name', 'Sayt LLC')->firstOrFail();
        $this->assertSame('Sayt LLC — '.$usd->service->name_ru, $usd->title, 'название — как при ручном создании');
        $this->assertSame('REFERRAL', $usd->source->code);
        $this->assertSame('18975000.00', (string) $usd->budget_uzs);
        $this->assertSame('2026-12-01', $usd->desired_date->format('Y-m-d'));

        $audit = AuditLog::where('action', 'import.run')->latest('created_at')->firstOrFail();
        $this->assertSame('leads', $audit->changes['entity']['new']);
        $this->assertSame(2, $audit->changes['created']['new']);
        $this->assertSame(2, $audit->changes['duplicates']['new']);
        $this->assertSame(4, $audit->changes['errors']['new']);

        $this->get("/import/leads/result/$token")->assertOk()->assertSee($rows[2]['code'])->assertSee('Итог импорта');
    }

    public function test_duplicates_are_imported_when_checkbox_is_set(): void
    {
        $this->newLead($this->manager, ['phone' => '+998 90 111-22-33']);
        $file = $this->xlsx([
            self::HEADER,
            $this->row([1 => 'Dup', 3 => '+998901112233', 6 => 'Instagram', 7 => 'SMM']),
            $this->row([1 => 'Dup again', 3 => '901112233', 6 => 'Instagram', 7 => 'SMM']),
        ]);
        $token = $this->previewToken($this->manager, 'leads', $file, ['duplicates']);
        $p = $this->preview($this->manager, 'leads', $token);
        $this->assertSame('create', $p[2]['status']);
        $this->assertStringContainsString('Импортировать дубли тоже', $p[2]['reason']);
        $this->assertSame('create', $p[3]['status']);
        $rows = $this->confirm($this->manager, 'leads', $token);
        $this->assertSame(['created', 'created'], [$rows[2]['status'], $rows[3]['status']]);
        $this->assertSame(3, Lead::whereRaw("RIGHT(REPLACE(REPLACE(phone, ' ', ''), '-', ''), 9) = '901112233'")->count());
    }

    public function test_client_phone_counts_as_duplicate(): void
    {
        Client::create(['name' => 'Existing', 'phone' => '+998 97 555 44 33', 'owner_id' => $this->manager->id, 'team_id' => $this->team1->id]);
        $file = $this->xlsx([self::HEADER, $this->row([1 => 'X', 3 => '975554433', 6 => 'Instagram', 7 => 'SMM'])]);
        $token = $this->previewToken($this->manager, 'leads', $file);
        $this->assertSame('duplicate', $this->preview($this->manager, 'leads', $token)[2]['status']);
    }

    public function test_owner_must_be_assignable_within_importer_scope(): void
    {
        $file = fn () => $this->xlsx([
            self::HEADER,
            $this->row([1 => 'A', 3 => '+998 93 100-00-01', 6 => 'Instagram', 7 => 'SMM', 11 => $this->otherManager->email]),
            $this->row([1 => 'B', 3 => '+998 93 100-00-02', 6 => 'Instagram', 7 => 'SMM', 11 => $this->executor->email]),
            $this->row([1 => 'C', 3 => '+998 93 100-00-03', 6 => 'Instagram', 7 => 'SMM', 11 => $this->manager->email]),
            $this->row([1 => 'D', 3 => '+998 93 100-00-04', 6 => 'Instagram', 7 => 'SMM', 11 => 'nobody@fluggi.test']),
        ]);
        // Менеджер (OWN) не может назначить коллегу из другого отдела
        $p = $this->preview($this->manager, 'leads', $this->previewToken($this->manager, 'leads', $file()));
        $this->assertSame('error', $p[2]['status']);
        $this->assertStringContainsString('вне вашей зоны', $p[2]['reason']);
        $this->assertSame('error', $p[3]['status']);
        $this->assertStringContainsString('только менеджер или РОП', $p[3]['reason']);
        $this->assertSame('create', $p[4]['status']);
        $this->assertStringContainsString('не найден', $p[5]['reason']);

        // РОП: свой отдел — можно, чужой — нет
        $token = $this->previewToken($this->rop, 'leads', $file());
        $p = $this->preview($this->rop, 'leads', $token);
        $this->assertSame('error', $p[2]['status']);
        $this->assertSame('create', $p[4]['status']);
        $rows = $this->confirm($this->rop, 'leads', $token);
        $this->assertSame('created', $rows[4]['status']);
        $this->assertSame($this->manager->id, Lead::where('contact_name', 'C')->value('owner_id'));

        // CEO: любой менеджер
        $token = $this->previewToken($this->ceo, 'leads', $file());
        $rows = $this->confirm($this->ceo, 'leads', $token);
        $this->assertSame('created', $rows[2]['status']);
        $this->assertSame($this->otherManager->id, Lead::where('contact_name', 'A')->latest('number')->value('owner_id'));
    }

    public function test_smm_video_source_requires_published_video(): void
    {
        $smm = $this->user('EXECUTOR');
        $published = SmmIdea::create(['title' => 'Reel', 'status' => 'PUBLISHED', 'author_id' => $smm->id, 'assignee_id' => $smm->id, 'published_at' => now()])->refresh();
        $draft = SmmIdea::create(['title' => 'Draft', 'status' => 'IDEA', 'author_id' => $smm->id, 'assignee_id' => $smm->id])->refresh();
        $file = $this->xlsx([
            self::HEADER,
            $this->row([1 => 'V1', 3 => '+998 93 200-00-01', 6 => 'Видео SMM', 7 => 'SMM', 12 => Format::code('V', $published->number)]),
            $this->row([1 => 'V2', 3 => '+998 93 200-00-02', 6 => 'SMM_VIDEO', 7 => 'SMM', 12 => Format::code('V', $draft->number)]),
            $this->row([1 => 'V3', 3 => '+998 93 200-00-03', 6 => 'SMM_VIDEO', 7 => 'SMM', 12 => 'V-99999']),
        ]);
        $token = $this->previewToken($this->manager, 'leads', $file);
        $p = $this->preview($this->manager, 'leads', $token);
        $this->assertSame('create', $p[2]['status']);
        $this->assertSame(t('smm.errors.videoNotPublished'), $p[3]['reason']);
        $this->assertStringContainsString('V-99999', $p[4]['reason']);
        $this->confirm($this->manager, 'leads', $token);
        $this->assertSame($published->id, Lead::where('contact_name', 'V1')->value('smm_idea_id'));
    }

    public function test_example_row_of_template_is_not_imported(): void
    {
        app()->setLocale('ru');
        $importer = new LeadImporter;
        $this->as($this->manager);
        $rows = $importer->normalize([['n' => 2, 'cells' => array_values((fn () => $this->example())->call($importer))]], $importer->keys());
        $this->assertSame('error', $importer->preview($rows, [])[0]['status']);
    }

    public function test_phone_from_excel_text_cell_or_other_systems_is_cleaned(): void
    {
        $this->assertSame('+998909338500', Values::phone("'+998909338500", 'Телефон'));
        $this->assertSame('+998 90 933 85 00', Values::phone('="+998 90 933 85 00"', 'Телефон'));
        $this->assertSame('+998 90 123 45 67', Values::phone('+998.90.123.45.67', 'Телефон'));
        $this->assertSame('+998901234567', Values::phone('+998901234567, +998711234567', 'Телефон'));
        $this->assertNull(Values::phone("'", 'Телефон'));
        $this->expectException(RowError::class);
        Values::phone('звонить вечером', 'Телефон');
    }
}
