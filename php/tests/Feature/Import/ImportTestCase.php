<?php

namespace Tests\Feature\Import;

use App\Models\User;
use App\Services\Import\ImportStore;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\Shared\Date as XlsDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Feature\Crm\CrmTestCase;

/** Фикстуры CRM + файлы XLSX/CSV для импорта, загрузка → предпросмотр → подтверждение. */
abstract class ImportTestCase extends CrmTestCase
{
    /** @var list<string> */
    private array $tmp = [];

    /** @var list<string> */
    private array $tokens = [];

    protected function tearDown(): void
    {
        foreach ($this->tmp as $f) {
            @unlink($f);
        }
        foreach ($this->tokens as $token) {
            ImportStore::forget($token);
        }
        parent::tearDown();
    }

    /**
     * XLSX из строк (первая — заголовки). Значение ['date' => 'YYYY-MM-DD'] пишется настоящей ячейкой даты Excel.
     */
    protected function xlsx(array $rows, string $name = 'import.xlsx'): UploadedFile
    {
        $book = new Spreadsheet;
        $ws = $book->getActiveSheet();
        foreach (array_values($rows) as $r => $row) {
            foreach (array_values($row) as $c => $v) {
                if (is_array($v) && isset($v['date'])) {
                    $ws->setCellValue([$c + 1, $r + 1], XlsDate::PHPToExcel($v['date'].' 00:00:00'));
                    $ws->getStyle([$c + 1, $r + 1])->getNumberFormat()->setFormatCode('dd.mm.yyyy');
                } elseif ($v !== null) {
                    $ws->setCellValue([$c + 1, $r + 1], $v);
                }
            }
        }
        $path = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
        (new Xlsx($book))->save($path);
        $this->tmp[] = $path;

        return new UploadedFile($path, $name, null, null, true);
    }

    protected function csv(string $content, string $name = 'import.csv'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'imp').'.csv';
        file_put_contents($path, $content);
        $this->tmp[] = $path;

        return new UploadedFile($path, $name, null, null, true);
    }

    protected function upload(User $as, string $entity, UploadedFile $file, array $options = []): TestResponse
    {
        return $this->actingAsUser($as)->post('/import/'.$entity, ['file' => $file, 'options' => array_fill_keys($options, '1')]);
    }

    /** Загрузка без ошибок → токен предпросмотра. */
    protected function previewToken(User $as, string $entity, UploadedFile $file, array $options = []): string
    {
        $res = $this->upload($as, $entity, $file, $options)->assertSessionHasNoErrors()->assertRedirect();
        $this->assertMatchesRegularExpression('#/import/'.$entity.'/preview/([A-Za-z0-9]{40})$#', $res->headers->get('Location'));

        return $this->tokens[] = basename($res->headers->get('Location'));
    }

    /** Строки предпросмотра по номеру строки файла. */
    protected function preview(User $as, string $entity, string $token): array
    {
        $data = ImportStore::get($token, $entity, $as->id);
        $this->assertNotNull($data);

        return collect($data['preview'])->keyBy('n')->all();
    }

    protected function confirm(User $as, string $entity, string $token): array
    {
        $this->actingAsUser($as)->post("/import/$entity/preview/$token")->assertRedirect("/import/$entity/result/$token");
        $result = ImportStore::result($token, $entity, $as->id);
        $this->assertNotNull($result);

        return collect($result['rows'])->keyBy('n')->all();
    }
}
