<?php

namespace App\Services\Import;

use App\Support\Audit;
use Illuminate\Http\UploadedFile;

/**
 * Импорт из Excel/CSV: шаблон → загрузка и предпросмотр → подтверждение → итог.
 * Права проверяются маршрутом (perm:…), а внутри — по каждой строке теми же сервисами, что и ручное создание.
 */
final class ImportService
{
    /** @var array<string, class-string<Importer>> */
    public const IMPORTERS = [
        'leads' => LeadImporter::class,
        'deals' => DealImporter::class,
        'clients' => ClientImporter::class,
        'projects' => ProjectImporter::class,
        'attendance' => AttendanceImporter::class,
    ];

    public const OPTIONS = ['duplicates', 'create_clients', 'create_tasks'];

    public static function importer(string $entity): Importer
    {
        $class = self::IMPORTERS[$entity] ?? abort(404);
        access()->authorize($class::permission());

        return new $class;
    }

    /** @return array{body:string, filename:string} */
    public static function template(string $entity): array
    {
        $importer = self::importer($entity);

        return ['body' => $importer->template(), 'filename' => 'fluggi-import-'.$entity.'-'.app()->getLocale().'.xlsx'];
    }

    /**
     * Разбор файла и предпросмотр. Возвращает токен предпросмотра.
     *
     * @param  array<string,bool>  $options
     */
    public static function upload(string $entity, UploadedFile $file, array $options): string
    {
        $importer = self::importer($entity);
        $options = array_intersect_key($options, array_flip($importer->options()));
        @set_time_limit(300);
        $parsed = ImportFile::read($file);
        $header = $importer->mapHeader($parsed['header']);
        if ($header['missing']) {
            throw new RowError(t('import.errors.missingColumns', ['columns' => implode(', ', $header['missing'])]));
        }
        if (! $header['map']) {
            throw new RowError(t('import.errors.noKnownColumns'));
        }
        $rows = $importer->normalize($parsed['rows'], $header['map']);
        $preview = $importer->preview($rows, $options);

        return ImportStore::put([
            'entity' => $entity,
            'user_id' => access()->id(),
            'created_at' => now()->toIso8601String(),
            'filename' => mb_substr($file->getClientOriginalName(), 0, 120),
            'options' => $options,
            'unknown' => array_slice($header['unknown'], 0, 30),
            'columns' => array_values($header['map']),
            'rows' => $rows,
            'preview' => $preview,
        ]);
    }

    /** Итоги по статусам строк. */
    public static function totals(array $rows): array
    {
        $t = ['create' => 0, 'update' => 0, 'duplicate' => 0, 'error' => 0, 'created' => 0, 'updated' => 0];
        foreach ($rows as $r) {
            $t[$r['status']] = ($t[$r['status']] ?? 0) + 1;
        }

        return $t;
    }

    /**
     * Импорт по подтверждённому предпросмотру. Повторный вызов с тем же токеном ничего не создаёт:
     * возвращает null, если предпросмотр уже забран (импорт выполнен или идёт) или истёк.
     */
    public static function confirm(string $entity, string $token): ?array
    {
        $importer = self::importer($entity);
        $data = ImportStore::claim($token, $entity, access()->id());
        if (! $data) {
            return null;
        }
        @set_time_limit(600);
        ignore_user_abort(true);
        $rows = $importer->run($data['rows'], $data['options'] ?? []);
        $totals = self::totals($rows);
        $result = [
            'entity' => $entity,
            'user_id' => access()->id(),
            'filename' => $data['filename'] ?? '',
            'finished_at' => now()->toIso8601String(),
            'totals' => $totals,
            'rows' => $rows,
        ];
        Audit::log('import.run', 'import', null, [
            'entity' => ['old' => null, 'new' => $entity],
            'file' => ['old' => null, 'new' => $result['filename']],
            'rows' => ['old' => null, 'new' => count($rows)],
            'created' => ['old' => null, 'new' => $totals['created']],
            'updated' => ['old' => null, 'new' => $totals['updated']],
            'duplicates' => ['old' => null, 'new' => $totals['duplicate']],
            'errors' => ['old' => null, 'new' => $totals['error']],
        ]);
        ImportStore::finish($token, $result);

        return $result;
    }
}
