<?php

namespace Tests\Feature\Import;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Comment;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\ExchangeRate;
use App\Models\OutboxEvent;
use App\Models\StageHistory;
use App\Support\Format;

class DealClientImportTest extends ImportTestCase
{
    private const DEAL_HEADER = ['Title', 'Client', 'Amount', 'Currency', 'Stage', 'Service', 'Expected close date', 'Owner (email)', 'Comment'];

    private function client(string $name, array $extra = []): Client
    {
        return Client::create($extra + ['name' => $name, 'owner_id' => $this->manager->id, 'team_id' => $this->team1->id])->refresh();
    }

    public function test_deals_use_rate_stage_history_and_client_lookup(): void
    {
        $acme = $this->client('Acme LLC');
        $file = $this->xlsx([
            self::DEAL_HEADER,
            ['Site', Format::code('C', $acme->number), '1 500', 'USD', 'Переговоры', 'Website', '15.11.2026', null, 'From old CRM'],
            ['SMM', 'acme llc', '9 000 000,50', null, null, 'SMM', null, null, null],
            ['Paid one', 'Acme LLC', '100', 'UZS', 'Оплачено', null, null, null, null],
            ['Won one', 'Acme LLC', '100', 'UZS', 'WON', null, null, null, null],
            ['Ghost', 'Unknown Co', '100', 'UZS', null, null, null, null, null],
            ['Bad sum', 'Acme LLC', '12abc', 'UZS', null, null, null, null, null],
        ]);
        $token = $this->previewToken($this->manager, 'deals', $file);
        $p = $this->preview($this->manager, 'deals', $token);
        $this->assertSame('create', $p[2]['status']);
        $this->assertSame('create', $p[3]['status']);
        $this->assertSame(t('import.errors.stagePaid'), $p[4]['reason']);
        $this->assertSame(t('import.errors.stagePaid'), $p[5]['reason']);
        $this->assertStringContainsString('Unknown Co', $p[6]['reason']);
        $this->assertStringContainsString('12abc', $p[7]['reason']);

        $rows = $this->confirm($this->manager, 'deals', $token);
        $site = Deal::where('title', 'Site')->firstOrFail();
        $this->assertSame($rows[2]['code'], Format::code('D', $site->number));
        $this->assertSame('OPEN', $site->status);
        $this->assertSame('NEGOTIATION', $site->stage->code);
        $this->assertSame('12650.000000', (string) $site->exchange_rate);
        $this->assertSame('18975000.00', (string) $site->amount_uzs);
        $this->assertSame('2026-11-15', $site->expected_close_date->format('Y-m-d'));
        $this->assertSame($this->manager->id, $site->owner_id);
        $this->assertSame(2, StageHistory::where('deal_id', $site->id)->count(), 'создание + переход на этап из файла');
        $this->assertTrue(Comment::where('deal_id', $site->id)->where('body', 'From old CRM')->exists());
        $this->assertTrue(AuditLog::where('action', 'deal.create')->where('entity_id', $site->id)->exists());
        $this->assertTrue(OutboxEvent::where('type', 'deal.created')->where('payload->dealId', $site->id)->exists());

        $smm = Deal::where('title', 'SMM')->firstOrFail();
        $this->assertSame('9000000.50', (string) $smm->amount);
        $this->assertSame('NEED_DEFINED', $smm->stage->code);
        $this->assertTrue((bool) $smm->is_repeat, 'вторая сделка клиента — повторная');
    }

    public function test_usd_without_rate_is_a_row_error(): void
    {
        ExchangeRate::query()->delete();
        $acme = $this->client('Acme LLC');
        $file = $this->xlsx([self::DEAL_HEADER, ['Site', $acme->name, '1500', 'USD'], ['Local', $acme->name, '1500', 'UZS']]);
        $token = $this->previewToken($this->manager, 'deals', $file);
        $p = $this->preview($this->manager, 'deals', $token);
        $this->assertSame('error', $p[2]['status']);
        $this->assertSame(t('references.errors.noRate', ['currency' => 'USD']), $p[2]['reason']);
        $this->assertSame('create', $p[3]['status']);
        $rows = $this->confirm($this->manager, 'deals', $token);
        $this->assertSame(['error', 'created'], [$rows[2]['status'], $rows[3]['status']]);
        $this->assertFalse(Deal::where('title', 'Site')->exists());
    }

    public function test_missing_client_is_created_only_with_checkbox(): void
    {
        $file = fn () => $this->xlsx([self::DEAL_HEADER, ['A', 'New Client', '100'], ['B', 'new client', '200']]);
        $p = $this->preview($this->manager, 'deals', $this->previewToken($this->manager, 'deals', $file()));
        $this->assertSame('error', $p[2]['status']);

        $token = $this->previewToken($this->manager, 'deals', $file(), ['create_clients']);
        $p = $this->preview($this->manager, 'deals', $token);
        $this->assertSame('create', $p[2]['status']);
        $this->assertStringContainsString('New Client', $p[2]['reason']);
        $this->confirm($this->manager, 'deals', $token);
        $this->assertSame(1, Client::where('name', 'New Client')->count(), 'вторая строка использует клиента первой');
        $client = Client::where('name', 'New Client')->firstOrFail();
        $this->assertSame(2, Deal::where('client_id', $client->id)->count());
        $this->assertSame($this->manager->id, $client->owner_id);
    }

    public function test_deal_owner_outside_scope_and_invisible_client_are_row_errors(): void
    {
        $foreign = Client::create(['name' => 'Foreign', 'owner_id' => $this->otherManager->id, 'team_id' => $this->team2->id])->refresh();
        $mine = $this->client('Mine');
        $file = $this->xlsx([
            self::DEAL_HEADER,
            ['A', Format::code('C', $foreign->number), '100'],
            ['B', 'Mine', '100', null, null, null, null, $this->otherManager->email],
        ]);
        $p = $this->preview($this->rop, 'deals', $this->previewToken($this->rop, 'deals', $file));
        $this->assertSame('error', $p[2]['status']);
        $this->assertStringContainsString('не найден', $p[2]['reason']);
        $this->assertSame('error', $p[3]['status']);
        $this->assertStringContainsString('вне вашей зоны', $p[3]['reason']);
        $this->assertNotNull($mine);
    }

    public function test_clients_with_primary_contact_and_dedup(): void
    {
        $this->client('Old Corp', ['phone' => '+998 71 123 45 67']);
        $file = $this->xlsx([
            ['Название *', 'Тип клиента', 'Телефон', 'Email', 'Имя контакта', 'Должность контакта', 'Телефон контакта', 'Источник', 'Ответственный (email)'],
            ['Nova', 'Частное лицо', '+998 90 700 00 01', 'Hi@Nova.uz', 'Bek', 'CEO', '+998 90 700 00 02', 'Рекомендация', null],
            ['OLD CORP', null, null, null, null, null, null, null, null],
            ['Phone twin', null, '711234567', null, null, null, null, null, null],
            ['nova', null, null, null, null, null, null, null, null],
            ['Bad type', 'Robot', null, null, null, null, null, null, null],
            ['Contactless', null, null, null, null, 'Boss', null, null, null],
        ]);
        $token = $this->previewToken($this->manager, 'clients', $file);
        $p = $this->preview($this->manager, 'clients', $token);
        $this->assertSame('create', $p[2]['status']);
        $this->assertSame('duplicate', $p[3]['status']);
        $this->assertSame('duplicate', $p[4]['status']);
        $this->assertSame('duplicate', $p[5]['status'], 'дубль строки 2 по названию');
        $this->assertSame('error', $p[6]['status']);
        $this->assertSame(t('import.errors.contactName'), $p[7]['reason']);
        $rows = $this->confirm($this->manager, 'clients', $token);
        $nova = Client::where('name', 'Nova')->firstOrFail();
        $this->assertSame($rows[2]['code'], Format::code('C', $nova->number));
        $this->assertSame('PERSON', $nova->type);
        $this->assertSame('hi@nova.uz', $nova->email);
        $this->assertSame('REFERRAL', $nova->source->code);
        $this->assertSame($this->manager->id, $nova->owner_id);
        $contact = Contact::where('client_id', $nova->id)->firstOrFail();
        $this->assertTrue((bool) $contact->is_primary);
        $this->assertSame('CEO', $contact->position);
        $this->assertTrue(AuditLog::where('action', 'client.create')->where('entity_id', $nova->id)->exists());
    }
}
