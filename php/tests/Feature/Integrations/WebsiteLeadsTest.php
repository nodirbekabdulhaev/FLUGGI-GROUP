<?php

namespace Tests\Feature\Integrations;

use App\Models\Lead;
use App\Models\WebsiteLead;
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
}
