<?php

namespace Tests\Feature\Sales;

use App\Models\AuditLog;
use App\Models\Contract;
use App\Models\Proposal;
use App\Models\StoredFile;
use App\Services\Sales\DocumentRenderer;
use App\Services\Sales\Numbers;
use App\Support\Settings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/** Генерация КП и договора, реквизиты, шаблоны; файлы с проверкой сигнатуры и прав (documents/files прежней версии). */
class DocumentsFilesTest extends SalesTestCase
{
    public function test_company_and_client_requisites_go_into_documents_in_all_formats(): void
    {
        Settings::save('company', array_replace(Settings::company(), [
            'name' => 'Fluggi', 'legalName' => 'ООО «FLUGGI GROUP»', 'inn' => '123456789', 'director' => 'Абдулхаев Нодирбек Тестович',
            'signerGenitive' => 'директора Абдулхаева Нодирбека Тестовича', 'bank' => 'АКБ «Капиталбанк»', 'mfo' => '01088',
            'account' => '20208000900123456001', 'phone' => '+998 90 000 00 00',
        ]));
        $deal = $this->deal('7500000');
        // КП и договор оформляет РОП; менеджер сделки открывает документы
        $this->actingAsUser($this->rop);
        $this->post('/sales/proposals', ['deal_id' => $deal->id, 'title' => 'Сайт', 'currency' => 'UZS', 'implementation_term' => '30 рабочих дней',
            'payment_terms' => '50% предоплата, 50% после сдачи', 'items' => [['description' => 'Лендинг', 'quantity' => 1, 'unit_price' => '7500000']]])->assertSessionHasNoErrors();
        $kp = Proposal::firstOrFail();

        $this->actingAsUser($this->manager);
        $html = $this->get("/documents/proposals/{$kp->id}?format=html")->assertOk();
        $this->assertStringContainsString('text/html', $html->headers->get('Content-Type'));
        $html->assertSee('Коммерческое предложение')->assertSee('Лендинг')->assertSee('Семь миллионов пятьсот тысяч сумов 00 тийинов');
        $this->get("/documents/proposals/{$kp->id}?format=pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $docx = $this->get("/documents/proposals/{$kp->id}?format=docx&download=true")->assertOk();
        $this->assertStringContainsString('attachment', $docx->headers->get('Content-Disposition'));
        $this->assertStringContainsString('wordprocessingml', $docx->headers->get('Content-Type'));
        $this->assertStringStartsWith('PK', $docx->getContent());
        $this->get("/documents/proposals/{$kp->id}?format=exe")->assertStatus(422);

        $this->actingAsUser($this->rop);
        $this->post("/sales/proposals/{$kp->id}/send");
        $this->post("/sales/proposals/{$kp->id}/accept");
        $this->post('/sales/contracts', ['deal_id' => $deal->id, 'proposal_id' => $kp->id, 'contract_date' => '2026-10-07', 'amount' => '7500000', 'currency' => 'UZS'])->assertSessionHasNoErrors();
        $c = Contract::firstOrFail();
        $number = Numbers::contract($c->number);
        $this->actingAsUser($this->manager);

        $missing = $this->getJson("/documents/contracts/{$c->id}/check")->assertOk()->json('missing');
        $this->assertTrue(collect($missing)->contains(fn ($m) => str_contains($m, 'Юридическое название клиента')));

        $this->putJson("/clients/{$deal->client_id}/requisites", ['inn' => '12'])->assertStatus(422);
        $this->putJson("/clients/{$deal->client_id}/requisites", [
            'legalName' => 'ООО «Docs Client»', 'inn' => '987654321', 'director' => 'Каримов Алишер', 'directorPosition' => 'Директор',
            'signerGenitive' => 'директора Каримова Алишера', 'basis' => 'Устава', 'bank' => 'АКБ «Ипотека-банк»', 'mfo' => '00419', 'account' => '20208000100000000002',
        ])->assertOk();
        $this->assertSame('987654321', $deal->client->fresh()->requisites['inn']);
        $this->assertTrue(AuditLog::where('action', 'client.requisites')->exists());
        $this->assertSame([], $this->getJson("/documents/contracts/{$c->id}/check")->json('missing'));

        $txt = $this->get("/documents/contracts/{$c->id}?format=txt")->assertOk()->getContent();
        foreach ([
            "ДОГОВОР № $number",
            'ООО «FLUGGI GROUP», именуемое в дальнейшем «Исполнитель», в лице директора Абдулхаева Нодирбека Тестовича',
            'ООО «Docs Client», именуемое в дальнейшем «Заказчик», в лице директора Каримова Алишера',
            '7 500 000 сум (Семь миллионов пятьсот тысяч сумов 00 тийинов)',
            'Срок оказания услуг: 30 рабочих дней',
            '«07» октября 2026 г.',
            'МФО: 00419',
            '/ Абдулхаев Н.Т. /',
        ] as $line) {
            $this->assertStringContainsString($line, $txt);
        }
        foreach (['pdf', 'docx', 'html'] as $format) {
            $this->get("/documents/contracts/{$c->id}?format=$format")->assertOk();
        }
        $this->assertStringContainsString('filename="Dogovor_DG-', $this->get("/documents/contracts/{$c->id}?format=pdf&download=true")->headers->get('Content-Disposition'));

        // Свой шаблон договора (только CEO / settings.manage)
        $this->get('/settings/documents')->assertForbidden();
        $this->actingAsUser($this->ceo)->get('/settings/documents')->assertOk()->assertSee('contract.number');
        $this->put('/settings/documents', ['city' => 'г. Самарканд', 'proposalIntro' => '', 'proposalNote' => '',
            'contractTemplate' => "# ДОГОВОР {{contract.number}}\nЗаказчик: {{client.name}}; {{unknown.key}}\n[[services]]"])->assertSessionHasNoErrors();
        $this->assertTrue(AuditLog::where('action', 'settings.documents')->exists());
        $custom = $this->actingAsUser($this->manager)->get("/documents/contracts/{$c->id}?format=txt")->getContent();
        $this->assertStringContainsString('г. Самарканд', $custom);
        $this->assertStringContainsString('Заказчик: ООО «Docs Client»; ________', $custom);
        $this->assertStringContainsString('ЗАКАЗЧИК: ООО «Docs Client»', $custom);

        // Чужой и без права — нет доступа
        $this->actingAsUser($this->executor)->get("/documents/contracts/{$c->id}?format=pdf")->assertForbidden();
        $this->actingAsUser($this->manager2)->get("/documents/contracts/{$c->id}?format=pdf")->assertNotFound();
    }

    public function test_pdf_renders_cyrillic_and_uzbek(): void
    {
        $pdf = DocumentRenderer::pdf([
            'fileName' => 'x', 'title' => 'Тест', 'brand' => ['name' => 'Fluggi', 'contacts' => ''],
            'blocks' => [['t' => 'p', 'text' => 'Привет, Oʻzbekiston: shartnoma toʻlovi']],
        ]);
        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertStringContainsString('DejaVu', $pdf);
    }

    public function test_upload_checks_signature_and_download_checks_access(): void
    {
        Storage::fake('local');
        $deal = $this->deal();
        $this->actingAsUser($this->manager);
        $pdf = UploadedFile::fake()->createWithContent('договор.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");
        $this->postJson('/files', ['deal_id' => $deal->id, 'category' => 'CONTRACT', 'file' => $pdf])->assertOk();
        $file = StoredFile::firstOrFail();
        $this->assertStringStartsWith('files/deals/', $file->storage_key);
        Storage::disk('local')->assertExists($file->storage_key);
        $this->assertSame(hash('sha256', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF"), $file->checksum);
        $this->assertTrue(AuditLog::where('action', 'file.upload')->where('entity_id', $file->id)->exists());

        $fake = UploadedFile::fake()->createWithContent('virus.pdf', 'MZ this is an exe');
        $this->postJson('/files', ['deal_id' => $deal->id, 'file' => $fake])->assertStatus(422)->assertJsonValidationErrors('file');
        $exe = UploadedFile::fake()->createWithContent('x.exe', 'MZ');
        $this->postJson('/files', ['deal_id' => $deal->id, 'file' => $exe])->assertStatus(422);
        $this->assertSame(1, StoredFile::count());

        $dl = $this->get("/files/{$file->id}/download")->assertOk();
        $this->assertStringContainsString('attachment', $dl->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $dl->headers->get('X-Content-Type-Options'));
        $this->getJson("/files?deal_id={$deal->id}")->assertOk()->assertJsonCount(1);

        $this->actingAsUser($this->manager2);
        $this->get("/files/{$file->id}/download")->assertNotFound();
        $this->postJson('/files', ['deal_id' => $deal->id, 'file' => UploadedFile::fake()->createWithContent('a.pdf', '%PDF-1.4')])->assertNotFound();
        $this->deleteJson("/files/{$file->id}")->assertNotFound();

        $this->actingAsUser($this->manager)->deleteJson("/files/{$file->id}")->assertOk();
        $this->assertSoftDeleted($file);
        $this->get("/files/{$file->id}/download")->assertNotFound();
    }

    public function test_docx_upload_with_zip_signature_is_accepted(): void
    {
        Storage::fake('local');
        $deal = $this->deal();
        $docx = UploadedFile::fake()->createWithContent('a.docx', "PK\x03\x04rest");
        $this->actingAsUser($this->manager)->postJson('/files', ['deal_id' => $deal->id, 'file' => $docx])->assertOk();
    }
}
