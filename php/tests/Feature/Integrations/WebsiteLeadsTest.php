<?php

namespace Tests\Feature\Integrations;

use App\Models\Lead;
use App\Models\VacancyApplication;
use App\Models\VacancyPosting;
use App\Models\WebsiteLead;
use App\Services\Vacancies\CandidateScore;
use Tests\Feature\Notifications\CommsFixtures;
use Tests\TestCase;

/** Приём заявок с сайта: токен, проверка полей, защита от дублей по external_id. */
class WebsiteLeadsTest extends TestCase
{
    use CommsFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtures();
        config(['fluggi.website.leads_token' => 'test-token-123']);
    }

    private function body(array $extra = []): array
    {
        return $extra + [
            'external_id' => 'fluggi-web-1', 'source' => 'fluggi.uz', 'type' => 'demo', 'name' => 'Test Mijoz', 'phone' => '+998901112233',
            'email' => null, 'company' => null, 'message' => 'Нужна система для учебного центра',
            'product' => ['kind' => 'solution', 'slug' => 'edu-erp', 'name' => 'Edu ERP'],
            'context' => ['page_url' => 'https://fluggi.uz/edu-erp', 'locale' => 'ru', 'demo_seconds' => 412],
            'utm' => ['source' => 'google', 'medium' => 'cpc', 'campaign' => 'edu', 'term' => null, 'content' => null], 'utm_last' => null,
            'created_at' => '2026-11-16T10:15:00+05:00',
        ];
    }

    private function send(array $body, ?string $token = 'test-token-123')
    {
        return $this->postJson('/hooks/website-leads', $body, $token ? ['Authorization' => 'Bearer '.$token] : []);
    }

    public function test_lead_is_created_once_and_repeat_returns_409_with_same_id(): void
    {
        $r = $this->send($this->body());
        $r->assertCreated()->assertJsonStructure(['id']);
        $lead = Lead::findOrFail($r->json('id'));
        $this->assertSame(['Test Mijoz', '+998901112233', 'WEBSITE', 'NEW'], [$lead->contact_name, $lead->phone, $lead->source->code, $lead->stage->code]);
        $this->assertStringContainsString('Edu ERP', $lead->title);
        $this->assertStringContainsString('utm', mb_strtolower((string) $lead->comment));
        $this->assertStringContainsString('412', (string) $lead->comment);

        $again = $this->send($this->body());
        $again->assertStatus(409)->assertJson(['id' => $lead->id]);
        $this->assertSame(1, Lead::count());
        $this->assertSame(1, WebsiteLead::count());
        // Ключ можно передать и заголовком Idempotency-Key
        $this->postJson('/hooks/website-leads', array_diff_key($this->body(), ['external_id' => 1]), ['Authorization' => 'Bearer test-token-123', 'Idempotency-Key' => 'fluggi-web-1'])->assertStatus(409);
    }

    public function test_same_phone_in_new_request_marks_repeat_instead_of_second_lead(): void
    {
        $first = $this->send($this->body())->json('id');
        $r = $this->send($this->body(['external_id' => 'fluggi-web-2', 'type' => 'callback']));
        $r->assertCreated()->assertJson(['id' => $first, 'duplicate' => true]);
        $this->assertSame(1, Lead::count());
    }

    public function test_token_and_fields_are_checked(): void
    {
        $this->send($this->body(), null)->assertUnauthorized();
        $this->send($this->body(), 'wrong')->assertUnauthorized();
        config(['fluggi.website.leads_token' => '']);
        $this->send($this->body(), '')->assertUnauthorized();
        config(['fluggi.website.leads_token' => 'test-token-123']);
        $this->send($this->body(['phone' => '90 123']))->assertStatus(422)->assertJsonValidationErrors('phone');
        $this->send($this->body(['type' => 'unknown']))->assertStatus(422)->assertJsonValidationErrors('type');
        $this->send($this->body(['name' => 'A']))->assertStatus(422)->assertJsonValidationErrors('name');
        $this->assertSame(0, Lead::count());
    }

    public function test_vacancy_application_goes_to_vacancies_not_to_leads(): void
    {
        $r = $this->send($this->body(['external_id' => 'fluggi-web-9', 'type' => 'vacancy', 'name' => 'Test Kandidat', 'message' => null,
            'product' => ['kind' => 'vacancy', 'slug' => 'php-dev', 'name' => 'PHP-разработчик']]));
        $r->assertCreated();
        $this->assertSame(0, Lead::count());
        $app = VacancyApplication::findOrFail($r->json('id'));
        $this->assertSame(['Test Kandidat', 'PHP-разработчик', 'NEW'], [$app->name, $app->vacancy_name, $app->status]);
        $this->send($this->body(['external_id' => 'fluggi-web-9', 'type' => 'vacancy']))->assertStatus(409)->assertJson(['id' => $app->id]);
        $this->assertSame(1, VacancyApplication::count());

        $ceo = $this->user('CEO');
        $this->actingAsUser($ceo)->get('/vacancies')->assertOk()->assertSee('Test Kandidat')->assertSee('PHP-разработчик');
        $this->patch('/vacancies/'.$app->id, ['status' => 'INTERVIEW', 'comment' => 'Позвонить во вторник'])->assertSessionHasNoErrors();
        $this->assertSame(['INTERVIEW', 'Позвонить во вторник'], [$app->fresh()->status, $app->fresh()->comment]);
        $this->actingAsUser($this->user('MANAGER'))->get('/vacancies')->assertForbidden();
    }

    public function test_candidate_card_from_game_form_is_scored_and_shown(): void
    {
        $candidate = ['photo_url' => 'https://example.com/photo.jpg', 'about' => 'Люблю чистый код', 'city' => 'Namangan', 'birth_year' => 2001, 'experience_years' => 2,
            'education' => [['level' => 'college', 'name' => 'Test kollej', 'year_from' => 2016, 'year_to' => 2019]],
            'skills' => [['name' => 'PHP', 'level' => 4], ['name' => 'SQL', 'level' => 5]],
            'languages' => [['code' => 'ru', 'level' => 'native'], ['code' => 'en', 'level' => 'b2']]];
        $assessment = ['test_name' => 'Fluggi Quest', 'duration_seconds' => 600, 'traits' => ['discipline' => 80, 'responsibility' => 80, 'stress_resistance' => 80, 'teamwork' => 80, 'learning' => 80, 'communication' => 80]];
        $r = $this->send($this->body(['external_id' => 'fluggi-web-7', 'type' => 'vacancy', 'candidate' => $candidate, 'assessment' => $assessment,
            'product' => ['kind' => 'vacancy', 'slug' => 'php-dev', 'name' => 'PHP-разработчик']]));
        $r->assertCreated();
        $app = VacancyApplication::findOrFail($r->json('id'));
        $this->assertSame([74, 'strong'], [$app->score, $app->score_level]);
        $this->assertSame([80, 68, 60, 100, 40], array_column($app->score_parts['parts'], 'score'));

        $this->actingAsUser($this->user('CEO'))->get('/vacancies/'.$app->id)->assertOk()->assertSee('Test kollej')->assertSee('🇷🇺')->assertSee('Fluggi Quest')->assertSee('74');
        $this->assertSame('VIEWED', $app->fresh()->status);
        // Kanban: карточка в колонке «Просмотрен», перенос в «Собеседование» — JSON-запросом
        $this->get('/vacancies')->assertOk()->assertSee('data-card="'.$app->id.'"', false);
        $this->patchJson('/vacancies/'.$app->id, ['status' => 'INTERVIEW'])->assertOk()->assertJson(['ok' => true]);
        $this->assertSame('INTERVIEW', $app->fresh()->status);
        $this->get('/vacancies?view=list')->assertOk()->assertSee('Test Mijoz');

        // Слабая дисциплина — красный флаг; без остальных разделов вес пересчитывается
        $this->assertSame(['discipline'], CandidateScore::evaluate([], ['traits' => ['discipline' => 20, 'teamwork' => 90]])['flags']);
        $this->assertSame(50, CandidateScore::evaluate([], ['traits' => ['discipline' => 20, 'teamwork' => 90]])['score']);
        $this->assertNull(CandidateScore::evaluate([], [])['score']);
        $this->send($this->body(['external_id' => 'fluggi-web-8', 'type' => 'vacancy', 'candidate' => ['photo_url' => 'http://insecure.example/p.jpg']]))->assertStatus(422);
    }

    public function test_site_assessment_format_is_stored_and_shown(): void
    {
        $assessment = ['score' => 63, 'grade' => 'C', 'recommendation' => 'reserve', 'archetype' => 'stability', 'level' => 8,
            'traits' => ['responsibility' => 79, 'teamwork' => 55, 'initiative' => 57, 'learning' => 83, 'stability' => 94, 'communication' => 92],
            'badges' => ['learning', 'stability'], 'flags' => ['too_fast'], 'experience' => 'middle', 'skills' => ['clients', 'sales'], 'motivators' => ['growth', 'team'],
            'city' => 'Namangan', 'link' => 'https://t.me/test_user', 'duration_seconds' => 112, 'parts' => ['profile' => 78, 'fit' => 61, 'completeness' => 60, 'penalty' => 10]];
        $r = $this->send($this->body(['external_id' => 'fluggi-web-11', 'type' => 'vacancy', 'message' => 'Балл 63, оценка C', 'assessment' => $assessment,
            'product' => ['kind' => 'vacancy', 'slug' => 'sales-manager', 'name' => 'Менеджер по продажам']]));
        $r->assertCreated();
        $app = VacancyApplication::findOrFail($r->json('id'));
        $this->assertSame([63, 'medium', 'Балл 63, оценка C'], [$app->score, $app->score_level, $app->message]);
        $this->assertSame(['too_fast'], $app->assessment['flags']);
        $this->actingAsUser($this->user('CEO'))->get('/vacancies/'.$app->id)->assertOk()->assertSee('Опора команды')->assertSee('Слишком быстро прошёл')->assertSee('В резерв')->assertSee('Namangan')->assertSee('t.me/test_user');
        $this->get('/vacancies')->assertOk()->assertSee('data-card="'.$app->id.'"', false);
    }

    public function test_company_vacancy_is_published_to_site_feed_at_once(): void
    {
        $this->actingAsUser($this->user('CEO'));
        $this->post('/vacancies/postings', ['title_ru' => 'PHP-разработчик', 'title_en' => 'PHP Developer', 'description_ru' => 'Пишем CRM', 'city' => 'Наманган', 'employment' => 'FULL', 'status' => 'PUBLISHED'])
            ->assertSessionHasNoErrors();
        $this->post('/vacancies/postings', ['title_ru' => 'Дизайнер', 'employment' => 'REMOTE', 'status' => 'DRAFT'])->assertSessionHasNoErrors();
        $p = VacancyPosting::where('title_ru', 'PHP-разработчик')->firstOrFail();
        $this->assertSame('PUBLISHED', $p->status);
        $this->assertMatchesRegularExpression('/^[a-z0-9-]+$/', $p->slug);
        $this->assertNotNull($p->published_at);

        $feed = $this->getJson('/hooks/website-vacancies')->assertOk()->assertHeader('Access-Control-Allow-Origin', '*')->json('vacancies');
        $this->assertCount(1, $feed);
        $this->assertSame(['PHP-разработчик', 'PHP Developer', 'PHP-разработчик'], [$feed[0]['title']['ru'], $feed[0]['title']['en'], $feed[0]['title']['uz']]);
        $this->assertSame($p->slug, $feed[0]['slug']);
        $this->get('/vacancies/postings')->assertOk()->assertSee('PHP-разработчик');

        // Снятие с сайта и публикация черновика — сразу в ленте
        $this->patch('/vacancies/postings/'.$p->id.'/status', ['status' => 'CLOSED'])->assertSessionHasNoErrors();
        $this->assertCount(0, $this->getJson('/hooks/website-vacancies')->json('vacancies'));
        $draft = VacancyPosting::where('title_ru', 'Дизайнер')->firstOrFail();
        $this->patch('/vacancies/postings/'.$draft->id.'/status', ['status' => 'PUBLISHED']);
        $this->assertSame(['Дизайнер'], array_column(array_column($this->getJson('/hooks/website-vacancies')->json('vacancies'), 'title'), 'ru'));
        $this->actingAsUser($this->user('MANAGER'))->post('/vacancies/postings', ['title_ru' => 'X', 'employment' => 'FULL', 'status' => 'DRAFT'])->assertForbidden();
    }
}
