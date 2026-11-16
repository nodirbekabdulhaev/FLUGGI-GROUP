<?php

namespace Tests\Feature\Import;

use App\Models\AuditLog;
use App\Models\Lead;
use App\Services\Import\ImportService;
use App\Services\Import\ImportStore;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;

class ImportFlowTest extends ImportTestCase
{
    /** Шаблон: заголовки на языке пользователя со «*» у обязательных, пример и лист «Справочник». */
    public function test_templates_for_each_entity_open_with_phpspreadsheet(): void
    {
        $expect = [
            'leads' => ['Источник *', 'Услуга *', 'Телефон', 'Ответственный (email)'],
            'deals' => ['Название *', 'Клиент *', 'Сумма *', 'Этап'],
            'clients' => ['Название *', 'Имя контакта', 'Должность контакта'],
            'projects' => ['Название *', 'Клиент *', 'Сделка', 'РОП (email) *', 'Направление'],
            'attendance' => ['Сотрудник *', 'Дата *', 'Статус', 'Приход', 'Уход'],
        ];
        foreach ($expect as $entity => $headers) {
            $res = $this->actingAsUser($this->ceo)->get("/import/$entity/template")->assertOk()
                ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            $path = tempnam(sys_get_temp_dir(), 'tpl');
            file_put_contents($path, $res->getContent());
            $book = (new XlsxReader)->load($path);
            @unlink($path);
            $this->assertSame(2, $book->getSheetCount(), $entity);
            $row1 = $book->getSheet(0)->rangeToArray('A1:Z1')[0];
            foreach ($headers as $h) {
                $this->assertContains($h, $row1, "$entity: $h");
            }
            $this->assertNotEmpty(array_filter($book->getSheet(0)->rangeToArray('A2:Z2')[0]), "$entity: строка-пример");
            $this->assertSame('Справочник', $book->getSheet(1)->getTitle());
            $values = array_merge(...$book->getSheet(1)->toArray());
            $this->assertContains('Правила заполнения', $values);
            if ($entity === 'leads') {
                $this->assertContains('SMM_VIDEO', $values);
                $this->assertContains($this->manager->email, $values);
                $this->assertContains('TOO_EXPENSIVE', $values);
            }
            if ($entity === 'deals') {
                $this->assertContains('NEGOTIATION', $values);
                $this->assertNotContains('PAID', $values);
            }
            if ($entity === 'projects') {
                $this->assertContains('IT', $values);
                $this->assertContains($this->rop->email, $values);
            }
        }

        $this->ceo->update(['locale' => 'uz']);
        $res = $this->actingAsUser($this->ceo)->get('/import/leads/template')->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'tpl');
        file_put_contents($path, $res->getContent());
        $book = (new XlsxReader)->load($path);
        @unlink($path);
        $this->assertContains('Manba *', $book->getSheet(0)->rangeToArray('A1:Z1')[0]);
        $this->assertSame('Maʼlumotnoma', $book->getSheet(1)->getTitle());
    }

    /** CSV: UTF-8 с BOM, «;», русские заголовки у пользователя с узбекским языком; CSV с «,». */
    public function test_csv_upload_with_bom_and_any_language_headers(): void
    {
        $this->manager->update(['locale' => 'uz']);
        $csv = "\u{FEFF}Имя контакта;Телефон;Источник *;Услуга *;Бюджет\r\n\"Ali; Vali\";+998 93 300-00-01;Instagram;SMM;\"1 000 000,50\"\r\n";
        $token = $this->previewToken($this->manager, 'leads', $this->csv($csv));
        $p = $this->preview($this->manager, 'leads', $token);
        $this->assertSame('create', $p[2]['status']);
        $this->confirm($this->manager, 'leads', $token);
        $lead = Lead::where('contact_name', 'Ali; Vali')->firstOrFail();
        $this->assertSame('1000000.50', (string) $lead->budget);

        $csv = "contact name,phone,source,service\nBobur,+998 93 300-00-02,Telegram,Veb-sayt\n";
        $token = $this->previewToken($this->manager, 'leads', $this->csv($csv));
        $p = $this->preview($this->manager, 'leads', $token);
        $this->assertSame('create', $p[2]['status']);
        // Причины — на языке пользователя (узбекский)
        $bad = "Contact name,Phone,Source,Service\nX,+998 93 300-00-03,Nope,SMM\n";
        $p = $this->preview($this->manager, 'leads', $this->previewToken($this->manager, 'leads', $this->csv($bad)));
        $this->assertSame('«Manba» ustunidagi «Nope» qiymati maʼlumotnomada topilmadi', $p[2]['reason']);
    }

    public function test_file_validation(): void
    {
        $this->upload($this->manager, 'leads', $this->csv('a;b', 'leads.txt'))->assertSessionHasErrors('file');
        $this->upload($this->manager, 'leads', $this->csv("Источник;Услуга\n"))->assertSessionHasErrors(['file' => t('import.errors.noRows')]);
        $this->upload($this->manager, 'leads', $this->csv("Имя контакта;Телефон\nA;1234567\n"))
            ->assertSessionHasErrors(['file' => t('import.errors.missingColumns', ['columns' => 'Источник, Услуга'])]);
        $rows = "Источник;Услуга\n".str_repeat("Instagram;SMM\n", 2001);
        $this->upload($this->manager, 'leads', $this->csv($rows))->assertSessionHasErrors(['file' => t('import.errors.tooManyRows', ['max' => 2000])]);
        $big = $this->csv(str_repeat('x', 5 * 1024 * 1024 + 10));
        $this->upload($this->manager, 'leads', $big)->assertSessionHasErrors('file');
        $fake = $this->csv('not a zip', 'fake.xlsx');
        $this->upload($this->manager, 'leads', $fake)->assertSessionHasErrors('file');
    }

    /** Повторное подтверждение (второй клик, двойная отправка) ничего не импортирует. */
    public function test_confirm_is_idempotent(): void
    {
        $token = $this->previewToken($this->manager, 'leads', $this->csv("Имя контакта;Телефон;Источник;Услуга\nOnce;+998 93 400-00-01;Instagram;SMM\n"));
        $before = Lead::count();
        $this->confirm($this->manager, 'leads', $token);
        $this->actingAsUser($this->manager)->post("/import/leads/preview/$token")->assertRedirect("/import/leads/result/$token")
            ->assertSessionHas('ok', t('import.alreadyImported'));
        $this->as($this->manager);
        $this->assertNull(ImportService::confirm('leads', $token));
        $this->assertSame($before + 1, Lead::count());
        $this->assertSame(1, AuditLog::where('action', 'import.run')->count());
        // Предпросмотр после импорта открывает итог
        $this->get("/import/leads/preview/$token")->assertRedirect("/import/leads/result/$token");
        $this->assertFalse(is_file(ImportStore::dir()."/$token.json"), 'исходные строки удалены');

        // Чужой токен не виден другому пользователю
        $other = $this->previewToken($this->manager, 'leads', $this->csv("Имя контакта;Телефон;Источник;Услуга\nTwice;+998 93 400-00-02;Instagram;SMM\n"));
        $this->actingAsUser($this->rop)->post("/import/leads/preview/$other")->assertRedirect('/import/leads');
        $this->assertFalse(Lead::where('contact_name', 'Twice')->exists());
        // Отмена удаляет данные файла
        $this->actingAsUser($this->manager)->delete("/import/leads/preview/$other")->assertRedirect('/import/leads');
        $this->assertNull(ImportStore::get($other, 'leads', $this->manager->id));
    }

    public function test_permissions_per_entity(): void
    {
        $cases = [
            'leads' => [$this->manager, $this->executor],
            'deals' => [$this->manager, $this->hr],
            'clients' => [$this->rop, $this->executor],
            'projects' => [$this->rop, $this->manager],
            'attendance' => [$this->hr, $this->rop],
        ];
        foreach ($cases as $entity => [$allowed, $denied]) {
            $this->actingAsUser($allowed)->get("/import/$entity")->assertOk();
            $this->actingAsUser($denied)->get("/import/$entity")->assertForbidden();
            $this->actingAsUser($denied)->get("/import/$entity/template")->assertForbidden();
            $this->upload($denied, $entity, $this->csv("a;b\n1;2\n"))->assertForbidden();
            $this->actingAsUser($denied)->post("/import/$entity/preview/".str_repeat('a', 40))->assertForbidden();
        }
    }

    public function test_pages_render_in_all_languages_and_list_buttons(): void
    {
        $texts = ['ru' => ['Импорт: Лиды', 'Проверить файл', 'Будет создано', 'Итог импорта'], 'uz' => ['Import: Lidlar', 'Faylni tekshirish', 'Yaratiladi', 'Import natijasi'], 'en' => ['Import: Leads', 'Check file', 'Will be created', 'Import result']];
        $i = 0;
        foreach ($texts as $locale => [$title, $check, $create, $result]) {
            $i++;
            $this->ceo->update(['locale' => $locale]);
            $this->actingAsUser($this->ceo)->get('/import/leads')->assertOk()->assertSee($title)->assertSee($check);
            foreach (['deals', 'clients', 'projects', 'attendance'] as $entity) {
                $this->get("/import/$entity")->assertOk();
            }
            $token = $this->previewToken($this->ceo, 'leads', $this->csv("Contact name;Phone;Source;Service\nL$locale;+998 93 500-00-0$i;Instagram;SMM\nBad;;Instagram;SMM\n"));
            $this->get("/import/leads/preview/$token")->assertOk()->assertSee($create);
            $this->get("/import/leads/preview/$token?status=error")->assertOk();
            $this->post("/import/leads/preview/$token")->assertRedirect();
            $this->get("/import/leads/result/$token")->assertOk()->assertSee($result);
        }

        $this->actingAsUser($this->manager)->get('/sales/leads')->assertOk()->assertSee('/import/leads');
        $this->get('/sales/deals')->assertOk()->assertSee('/import/deals');
        $this->get('/clients')->assertOk()->assertSee('/import/clients');
        $this->get('/projects/all')->assertOk()->assertDontSee('/import/projects');
        $this->actingAsUser($this->rop)->get('/projects/all')->assertOk()->assertSee('/import/projects');
        $this->actingAsUser($this->hr)->get('/attendance')->assertOk()->assertSee('/import/attendance');
        $this->actingAsUser($this->manager)->get('/attendance')->assertOk()->assertDontSee('/import/attendance');
    }
}
