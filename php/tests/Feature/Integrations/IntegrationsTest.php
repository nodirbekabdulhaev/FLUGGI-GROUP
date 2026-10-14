<?php

namespace Tests\Feature\Integrations;

use App\Models\Activity;
use App\Models\FormSubmission;
use App\Models\Lead;
use App\Models\LeadForm;
use App\Models\Notification;
use App\Models\Service;
use App\Models\SocialMessage;
use App\Models\SocialThread;
use App\Services\Integrations\IntakeService;
use App\Services\Integrations\MetaService;
use App\Support\Lang;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Notifications\CommsFixtures;
use Tests\TestCase;

class IntegrationsTest extends TestCase
{
    use CommsFixtures;

    private array $graph = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtures();
        config(['fluggi.meta.app_secret' => 'app-secret', 'fluggi.meta.verify_token' => 'verify-me', 'fluggi.meta.page_access_token' => 'page-token']);
        Http::fake(function (Request $r) {
            $path = parse_url($r->url(), PHP_URL_PATH);
            $this->graph[] = ['path' => $path, 'body' => $r->data()];
            if (str_ends_with($path, '/IGUSER1')) {
                return Http::response(['name' => 'Malika', 'username' => 'malika.shop']);
            }
            if (str_ends_with($path, '/LEAD123')) {
                return Http::response(['campaign_name' => 'SMM октябрь', 'ad_name' => 'Ролик', 'platform' => 'ig', 'field_data' => [
                    ['name' => 'full_name', 'values' => ['Азиз Каримов']], ['name' => 'phone_number', 'values' => ['+998 90 777-66-55']],
                    ['name' => 'какой_бюджет', 'values' => ['1000$']],
                ]]);
            }

            return Http::response(['message_id' => 'm.out', 'id' => 'c.out']);
        });
    }

    private function signed(array $body)
    {
        $raw = json_encode($body);

        return $this->call('POST', '/hooks/meta', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $raw, 'app-secret'),
        ], $raw);
    }

    public function test_site_form_creates_lead_dedupes_and_filters_bots(): void
    {
        $smm = Service::where('code', 'SMM')->firstOrFail();
        $this->actingAsUser($this->ceo)->post('/settings/integrations/forms', [
            'name' => 'Главная — SMM', 'title' => 'Оставьте заявку', 'service_id' => $smm->id, 'team_id' => $this->team1->id, 'is_active' => '1',
            'fields' => [
                ['key' => 'name', 'label' => 'Имя', 'type' => 'text', 'required' => '1'],
                ['key' => 'phone', 'label' => 'Телефон', 'type' => 'phone', 'required' => '1'],
                ['key' => 'budget', 'label' => 'Бюджет', 'type' => 'select', 'options' => 'до 500$; больше 500$', 'required' => '0'],
                ['key' => 'message', 'label' => 'Сообщение', 'type' => 'textarea', 'required' => '0'],
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $form = LeadForm::firstOrFail();
        $this->assertSame(['до 500$', 'больше 500$'], $form->fields[2]['options']);
        // Только CEO настраивает формы; без телефона/email — нельзя
        $this->actingAsUser($this->manager)->get('/settings/integrations')->assertForbidden();
        $this->actingAsUser($this->ceo)->postJson('/settings/integrations/forms', ['name' => 'x', 'title' => 'x', 'fields' => [['key' => 'name', 'label' => 'Имя', 'type' => 'text']]])->assertStatus(422);
        $this->post('/logout');

        // Посетитель сайта: страница формы (встраивается iframe) и CORS
        $page = $this->get('/f/'.$form->key)->assertOk()->assertSee('Оставьте заявку');
        $this->assertNull($page->headers->get('X-Frame-Options'));
        $this->get('/f/unknown')->assertNotFound();
        $this->call('OPTIONS', '/hooks/intake/'.$form->key)->assertNoContent()->assertHeader('Access-Control-Allow-Origin', '*');

        $send = fn (array $data, array $extra = []) => $this->postJson('/hooks/intake/'.$form->key, ['data' => $data, 'page' => 'https://fluggi.uz/smm', 'utm' => ['utm_source' => 'google']] + $extra);
        $missing = $send(['name' => 'Ali'])->assertStatus(422);
        $this->assertArrayHasKey('data.phone', $missing->json('errors'));
        $send(['name' => 'Ali', 'phone' => '+998 90 123-45-67', 'budget' => 'нет такого'])->assertStatus(422);

        $ok = $send(['name' => 'Ali', 'phone' => '+998 90 123-45-67', 'budget' => 'больше 500$', 'message' => 'Нужен SMM'])->assertOk();
        $this->assertStringContainsString('Спасибо', $ok->json('message'));
        $lead = Lead::with('source')->firstOrFail();
        $this->assertSame(['Ali', '+998 90 123-45-67', $smm->id, $this->manager->id, 'WEBSITE', 'NEW'], [$lead->contact_name, $lead->phone, $lead->service_id, $lead->owner_id, $lead->source->code, $lead->stage->code]);
        $this->assertGreaterThan(0, $lead->number);
        $this->assertStringContainsString('Нужен SMM', $lead->comment);
        $this->assertStringContainsString('Бюджет: больше 500$', $lead->comment);
        $this->assertStringContainsString('UTM: utm_source=google', $lead->comment);
        $this->assertTrue(Activity::where('lead_id', $lead->id)->where('type', 'lead.created')->exists());
        $this->drain();
        $this->assertGreaterThan(0, Notification::where('user_id', $this->manager->id)->where('type', 'lead.created')->count());
        $this->assertGreaterThan(0, Notification::where('user_id', $this->ceo->id)->where('type', 'lead.created')->count());

        // Повтор с тем же номером в другом формате — без нового лида, отметка в найденном
        $send(['name' => 'Ali', 'phone' => '901234567'])->assertOk();
        $this->assertSame(1, Lead::count());
        $this->assertSame(1, Notification::where('type', 'lead.inbound_repeat')->count());
        // Бот: скрытое поле или слишком быстрая отправка
        $send(['name' => 'Bot', 'phone' => '+998901112233'], ['website' => 'spam.com'])->assertOk();
        $send(['name' => 'Bot2', 'phone' => '+998901112234'], ['renderedAt' => (int) (microtime(true) * 1000)])->assertOk();
        $this->assertSame(1, Lead::count());

        // Обычная HTML-форма на WordPress (поля плоско)
        $html = $this->call('POST', '/hooks/intake/'.$form->key, ['name' => 'Dilshod', 'phone' => '+998935556677', 'utm_campaign' => 'wp'], [], [], ['HTTP_ACCEPT' => 'text/html', 'CONTENT_TYPE' => 'application/x-www-form-urlencoded']);
        $html->assertOk()->assertSee('Спасибо');
        $this->assertSame(2, Lead::count());

        $this->assertSame(['DUPLICATE', 'LEAD_CREATED', 'LEAD_CREATED', 'SPAM', 'SPAM'], FormSubmission::orderBy('result')->pluck('result')->sort()->values()->all());
        $subs = $this->actingAsUser($this->ceo)->getJson('/settings/integrations/forms/'.$form->id.'/submissions')->assertOk()->json();
        $this->assertCount(5, $subs);
        $this->get('/settings/integrations')->assertOk()->assertSee('Главная — SMM')->assertSee('5 / 2');
        // Выключенная форма не принимает заявки
        $form->update(['is_active' => false]);
        $this->postJson('/hooks/intake/'.$form->key, ['data' => ['phone' => '+998901111111']])->assertNotFound();
        $this->actingAsUser($this->ceo)->delete('/settings/integrations/forms/'.$form->id)->assertRedirect();
        $this->assertSoftDeleted('lead_forms', ['id' => $form->id]);
    }

    public function test_meta_webhook_verify_and_signature(): void
    {
        $this->get('/hooks/meta?hub.mode=subscribe&hub.verify_token=verify-me&hub.challenge=42')->assertOk()->assertSeeText('42');
        $this->get('/hooks/meta?hub.mode=subscribe&hub.verify_token=wrong&hub.challenge=42')->assertForbidden();
        $this->withHeader('X-Hub-Signature-256', 'sha256=deadbeef')->postJson('/hooks/meta', ['object' => 'instagram', 'entry' => []])->assertForbidden();
        $this->postJson('/hooks/meta', ['object' => 'instagram'])->assertForbidden();
    }

    public function test_direct_comments_reply_and_target_leads(): void
    {
        $dm = ['object' => 'instagram', 'entry' => [['id' => 'PAGE1', 'messaging' => [['sender' => ['id' => 'IGUSER1'], 'recipient' => ['id' => 'PAGE1'],
            'timestamp' => (int) (microtime(true) * 1000), 'message' => ['mid' => 'mid.1', 'text' => 'Здравствуйте, сколько стоит SMM?']]]]]];
        $this->signed($dm)->assertOk();
        $this->signed($dm)->assertOk(); // повтор события — без дубля
        $thread = SocialThread::with('lead')->firstOrFail();
        $this->assertSame(['INSTAGRAM_DM', 'malika.shop', 1], [$thread->channel, $thread->peer_username, $thread->unread]);
        $this->assertSame('malika.shop', $thread->lead->instagram);
        $this->assertSame(1, SocialMessage::count());

        // Ответственный видит свою переписку, другой отдел — нет
        $owner = $thread->owner;
        $this->actingAsUser($owner)->get('/inbox')->assertOk()->assertSee('@malika.shop');
        $other = $owner->id === $this->otherManager->id ? $this->manager : $this->otherManager;
        $this->actingAsUser($other)->get('/inbox')->assertOk()->assertDontSee('@malika.shop');
        $this->getJson('/inbox/'.$thread->id.'/messages')->assertNotFound();

        // Ответ в Директ уходит в Graph API
        $reply = $this->actingAsUser($owner)->postJson('/inbox/'.$thread->id.'/reply', ['text' => 'От 650$'])->assertOk()->json();
        $this->assertSame(['IN', 'OUT'], array_column($reply, 'direction'));
        $call = collect($this->graph)->first(fn ($c) => str_ends_with($c['path'], '/me/messages'));
        $this->assertSame(['id' => 'IGUSER1'], $call['body']['recipient']);
        $this->assertSame(0, $thread->fresh()->unread);

        // Комментарии: без ключевого слова — только переписка; «цена» — лид
        $comment = fn ($id, $from, $text) => ['object' => 'instagram', 'entry' => [['id' => 'PAGE1', 'changes' => [['field' => 'comments',
            'value' => ['id' => $id, 'text' => $text, 'from' => ['id' => $from, 'username' => strtolower($from)], 'media' => ['id' => 'POST1']]]]]]];
        $this->signed($comment('c1', 'FAN1', 'Красиво 🔥'))->assertOk();
        $this->signed($comment('c2', 'BUYER1', 'Какая цена?'))->assertOk();
        $this->assertSame(['buyer1', 'malika.shop'], Lead::orderBy('instagram')->pluck('instagram')->all());
        // Лид вручную из переписки
        $fan = SocialThread::where('peer_id', 'FAN1')->firstOrFail();
        $made = $this->actingAsUser($this->ceo)->postJson('/inbox/'.$fan->id.'/lead')->assertOk()->json();
        $this->assertNotNull($made['lead']);
        // Публичный ответ на комментарий; чужой комментарий — нельзя
        $buyer = SocialThread::where('peer_id', 'BUYER1')->firstOrFail();
        $this->postJson('/inbox/'.$buyer->id.'/reply', ['text' => 'Написали в Директ', 'commentId' => 'c2'])->assertOk();
        $this->assertTrue(collect($this->graph)->contains(fn ($c) => str_ends_with($c['path'], '/c2/replies')));
        // Наш ответ возвращается вебхуком от нашего же аккаунта — не новая переписка и не лид
        $threads = SocialThread::count();
        $leads = Lead::count();
        $own = ['object' => 'instagram', 'entry' => [['id' => 'PAGE1', 'changes' => [['field' => 'comments',
            'value' => ['id' => 'own1', 'parent_id' => 'c2', 'text' => 'Какая цена? Ответили в директ', 'from' => ['id' => 'PAGE1', 'username' => 'fluggi_group'], 'media' => ['id' => 'POST1']]]]]]];
        $this->signed($own)->assertOk();
        $this->assertSame([$threads, $leads], [SocialThread::count(), Lead::count()]);
        $this->assertSame('OUT', $buyer->messages()->where('external_id', 'own1')->value('direction'));
        $this->postJson('/inbox/'.$buyer->id.'/reply', ['text' => 'x', 'commentId' => 'c1'])->assertStatus(422);
        $this->postJson('/inbox/'.$buyer->id.'/reply', ['text' => 'x'])->assertStatus(422);

        // Таргет: лид-форма → лид «Таргет» с кампанией
        $this->signed(['object' => 'page', 'entry' => [['id' => 'PAGE1', 'changes' => [['field' => 'leadgen', 'value' => ['leadgen_id' => 'LEAD123']]]]]])->assertOk();
        $ad = Lead::whereHas('source', fn ($q) => $q->where('code', 'TARGET'))->firstOrFail();
        $this->assertSame(['Азиз Каримов', '+998 90 777-66-55'], [$ad->contact_name, $ad->phone]);
        $this->assertStringContainsString('Кампания: SMM октябрь', $ad->comment);
        $this->assertStringContainsString('какой_бюджет: 1000$', $ad->comment);

        $this->get('/settings/integrations')->assertOk()->assertSee(t('integrations.metaOn'))->assertSee('/hooks/meta');
        $this->assertSame(1, MetaService::status()['adsLeads']);
        $this->assertSame(3, MetaService::status()['threads']);
        // Без права lead.update ответить нельзя
        $this->actingAsUser($this->executor)->postJson('/inbox/'.$buyer->id.'/reply', ['text' => 'x', 'commentId' => 'c2'])->assertForbidden();
    }

    public function test_settings_and_inbox_pages_render_in_both_languages(): void
    {
        $this->actingAsUser($this->ceo)->put('/settings/integrations/meta', ['ownerId' => $this->manager->id, 'autoLeadFromComments' => 'off',
            'autoLeadFromDirect' => '0', 'commentKeywords' => 'цена, x, прайс'])->assertRedirect();
        $s = IntakeService::settings();
        $this->assertSame([$this->manager->id, 'off', false, ['цена', 'прайс']], [$s['ownerId'], $s['autoLeadFromComments'], $s['autoLeadFromDirect'], $s['commentKeywords']]);
        foreach (['ru', 'uz'] as $loc) {
            $this->ceo->update(['locale' => $loc]);
            $this->actingAsUser($this->ceo)->get('/settings/integrations')->assertOk()->assertSee(Lang::get('integrations.formsTitle', [], $loc));
            $this->actingAsUser($this->ceo)->get('/inbox')->assertOk()->assertSee(Lang::get('inbox.title', [], $loc));
        }
        $this->actingAsUser($this->executor)->get('/inbox')->assertForbidden();
    }
}
