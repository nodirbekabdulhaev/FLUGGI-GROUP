<?php

namespace App\Services\Import;

use App\Exceptions\BusinessRule;
use App\Models\Client;
use App\Models\LeadSource;
use App\Models\Service;
use App\Models\User;
use App\Services\Crm\CrmAccess;
use App\Support\Format;
use App\Support\Lang;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as XlsDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Импорт одного раздела (лиды, сделки, клиенты, проекты, посещаемость) из XLSX/CSV.
 * Колонки имеют стабильные внутренние ключи; заголовок файла узнаётся на любом из трёх языков.
 * Каждая строка проверяется (check) теми же правилами, что и ручное создание, и создаётся (create)
 * через сервисы модулей в своей транзакции — ошибка одной строки не отменяет остальные.
 */
abstract class Importer
{
    /** Ключ раздела: leads|deals|clients|projects|attendance. */
    abstract public static function entity(): string;

    /** Право на импорт (то же, что на ручное создание). */
    abstract public static function permission(): string;

    /** Страница списка раздела. */
    abstract public static function backUrl(): string;

    /**
     * Колонки: ключ → [type: text|date|time|money|email|phone, required: bool].
     *
     * @return array<string, array{type?:string, required?:bool}>
     */
    abstract protected function columns(): array;

    /** Строка-пример шаблона: ключ → значение. */
    abstract protected function example(): array;

    /**
     * Разделы листа «Справочник»: [название раздела => [[значение, пояснение], …]].
     *
     * @return array<string, list<array{0:string,1:string}>>
     */
    abstract protected function reference(): array;

    /**
     * Проверка строки без изменений в базе.
     *
     * @param  array<string,?string>  $v  значения по ключам колонок
     * @return array{status:string, reason?:?string, data?:array, keys?:list<?string>} status: create|update|duplicate
     */
    abstract protected function check(array $v, ImportContext $ctx): array;

    /** Создание (уже внутри транзакции строки). Возвращает номер/подпись созданной записи. */
    abstract protected function create(array $data, ImportContext $ctx): string;

    /** Короткая подпись строки для таблицы предпросмотра. */
    abstract protected function label(array $v): string;

    /** Опции загрузки (флажки), доступные разделу. */
    public function options(): array
    {
        return [];
    }

    /** Правила заполнения (для страницы загрузки и листа «Справочник»). */
    public function rules(): array
    {
        return [];
    }

    /** Разрешены ли дубли внутри файла (только лиды с флажком «Импортировать дубли тоже»). */
    protected function allowsDuplicates(ImportContext $ctx): bool
    {
        return false;
    }

    // ─────────────────────────── Колонки и заголовки ───────────────────────────

    public function col(string $key, ?string $locale = null): string
    {
        return Lang::get('import.col.'.$key, [], $locale);
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->columns());
    }

    public function required(string $key): bool
    {
        return ! empty($this->columns()[$key]['required']);
    }

    /** Заголовок колонки на языке пользователя, обязательные — со «*». */
    public function header(string $key): string
    {
        return $this->col($key).($this->required($key) ? ' *' : '');
    }

    /**
     * Сопоставление заголовков файла с ключами (на любом языке, без учёта регистра и «*»).
     *
     * @return array{map: array<int,string>, unknown: list<string>, missing: list<string>}
     */
    public function mapHeader(array $header): array
    {
        $aliases = [];
        foreach ($this->keys() as $key) {
            $aliases[Values::norm($key)] = $key;
            foreach (Lang::LOCALES as $locale) {
                $aliases[Values::norm($this->col($key, $locale))] = $key;
            }
        }
        $map = [];
        $unknown = [];
        foreach ($header as $i => $h) {
            $n = Values::norm(is_scalar($h) ? (string) $h : '');
            if ($n === '') {
                continue;
            }
            if (isset($aliases[$n]) && ! in_array($aliases[$n], $map, true)) {
                $map[$i] = $aliases[$n];
            } else {
                $unknown[] = (string) $h;
            }
        }
        $missing = array_values(array_filter($this->keys(), fn ($k) => $this->required($k) && ! in_array($k, $map, true)));

        return ['map' => $map, 'unknown' => $unknown, 'missing' => array_map(fn ($k) => $this->col($k), $missing)];
    }

    /**
     * Сырые ячейки → значения по ключам (строки, даты YYYY-MM-DD, время HH:MM).
     *
     * @return list<array{n:int, v:array<string,?string>}>
     */
    public function normalize(array $rows, array $map): array
    {
        $types = array_map(fn ($c) => $c['type'] ?? 'text', $this->columns());
        $out = [];
        foreach ($rows as $row) {
            $v = array_fill_keys($this->keys(), null);
            foreach ($map as $i => $key) {
                $v[$key] = Values::cell($row['cells'][$i] ?? null, $types[$key]);
            }
            $out[] = ['n' => $row['n'], 'v' => $v];
        }

        return $out;
    }

    // ─────────────────────────── Проверка и импорт ───────────────────────────

    /**
     * Одна строка: {n, status: create|update|duplicate|error, reason, label, data?}.
     */
    public function analyzeRow(array $row, ImportContext $ctx): array
    {
        $v = $row['v'];
        $out = ['n' => $row['n'], 'label' => mb_substr($this->label($v), 0, 120), 'reason' => null];
        if ($this->isExample($v)) {
            return ['status' => 'error', 'reason' => t('import.reasons.exampleRow')] + $out;
        }
        try {
            foreach ($this->keys() as $key) {
                if ($this->required($key) && ($v[$key] ?? null) === null) {
                    throw new RowError(t('import.errors.required', ['column' => $this->col($key)]));
                }
            }
            $r = $this->check($v, $ctx);
        } catch (Throwable $e) {
            return ['status' => 'error', 'reason' => self::reasonOf($e)] + $out;
        }
        $out['status'] = $r['status'];
        $out['reason'] = $r['reason'] ?? null;
        $out['data'] = $r['data'] ?? [];
        $keys = $r['keys'] ?? [];
        if (in_array($r['status'], ['create', 'update'], true) && $keys) {
            $first = $ctx->seenRow($keys);
            if ($first !== null) {
                if (! $this->allowsDuplicates($ctx)) {
                    return ['status' => 'duplicate', 'reason' => t('import.reasons.duplicateInFile', ['row' => $first])] + $out;
                }
                $out['reason'] = trim(($out['reason'] ? $out['reason'].' ' : '').t('import.reasons.duplicateInFileImported', ['row' => $first]));
            }
            $ctx->remember($keys, $row['n']);
        }

        return $out;
    }

    /**
     * Предпросмотр: статус и причина по каждой строке, без изменений в базе.
     *
     * @return list<array{n:int, status:string, reason:?string, label:string}>
     */
    public function preview(array $rows, array $options): array
    {
        $ctx = new ImportContext($options, dryRun: true);

        return array_map(function ($row) use ($ctx) {
            $r = $this->analyzeRow($row, $ctx);
            unset($r['data']);

            return $r;
        }, $rows);
    }

    /**
     * Импорт: каждая строка проверяется заново (данные могли измениться после предпросмотра)
     * и создаётся в своей транзакции.
     *
     * @return list<array{n:int, status:string, reason:?string, label:string, code:?string}>
     */
    public function run(array $rows, array $options): array
    {
        $ctx = new ImportContext($options, dryRun: false);
        $out = [];
        foreach ($rows as $row) {
            $r = $this->analyzeRow($row, $ctx);
            $data = $r['data'] ?? [];
            unset($r['data']);
            $r['code'] = null;
            if (in_array($r['status'], ['create', 'update'], true)) {
                try {
                    $r['code'] = DB::transaction(fn () => $this->create($data, $ctx));
                    $r['status'] = $r['status'] === 'create' ? 'created' : 'updated';
                } catch (Throwable $e) {
                    $r['status'] = 'error';
                    $r['reason'] = self::reasonOf($e);
                }
            }
            $out[] = $r;
        }

        return $out;
    }

    /** Понятная причина ошибки строки на языке пользователя. */
    public static function reasonOf(Throwable $e): string
    {
        if ($e instanceof RowError || $e instanceof BusinessRule) {
            return $e->getMessage();
        }
        if ($e instanceof HttpExceptionInterface) {
            return $e->getStatusCode() === 403 ? t('import.errors.forbidden') : t('import.errors.notFoundOrScope');
        }
        report($e);

        return t('import.errors.unexpected');
    }

    /** Строка совпадает со строкой-примером шаблона (на любом языке) — её не импортируем. */
    private function isExample(array $v): bool
    {
        $filled = array_filter($v, fn ($x) => $x !== null);
        // Строка из 1–2 значений может случайно совпасть с частью примера — это не пример
        if (count($filled) < 3) {
            return false;
        }
        foreach (Lang::LOCALES as $locale) {
            $example = $this->exampleIn($locale);
            $same = true;
            foreach ($filled as $k => $x) {
                if (Values::norm($x) !== Values::norm($example[$k] ?? '')) {
                    $same = false;
                    break;
                }
            }
            if ($same) {
                return true;
            }
        }

        return false;
    }

    /** @var array<string,array> строка-пример на каждом языке (для сравнения со строками файла) */
    private array $examples = [];

    private function exampleIn(string $locale): array
    {
        if (isset($this->examples[$locale])) {
            return $this->examples[$locale];
        }
        $current = app()->getLocale();
        app()->setLocale($locale);
        try {
            return $this->examples[$locale] = $this->example();
        } finally {
            app()->setLocale($current);
        }
    }

    // ─────────────────────────── Шаблон ───────────────────────────

    /** XLSX-шаблон: лист с заголовками (на языке пользователя) и строкой-примером + лист «Справочник». */
    public function template(): string
    {
        $book = new Spreadsheet;
        $book->getProperties()->setCreator('Fluggi CRM');
        $ws = $book->getActiveSheet();
        $ws->setTitle(self::sheetTitle(t('import.entities.'.static::entity())));
        $columns = $this->columns();
        $example = $this->example();
        $i = 0;
        foreach ($columns as $key => $spec) {
            $i++;
            $header = $this->header($key);
            $ws->setCellValueExplicit([$i, 1], $header, DataType::TYPE_STRING);
            $ws->getColumnDimensionByColumn($i)->setWidth(max(14, mb_strlen($header) + 4));
            $type = $spec['type'] ?? 'text';
            $value = $example[$key] ?? null;
            // Текстовые колонки — формат «Текст», чтобы Excel не превращал телефоны в числа
            if (in_array($type, ['text', 'phone', 'email', 'time'], true)) {
                $ws->getStyle([$i, 2, $i, ImportFile::MAX_ROWS + 1])->getNumberFormat()->setFormatCode('@');
            }
            if ($value === null || $value === '') {
                continue;
            }
            if ($type === 'date') {
                $ws->setCellValue([$i, 2], XlsDate::PHPToExcel($value.' 00:00:00'));
                $ws->getStyle([$i, 2])->getNumberFormat()->setFormatCode('dd.mm.yyyy');
            } elseif ($type === 'money') {
                $ws->setCellValue([$i, 2], (float) $value);
                $ws->getStyle([$i, 2])->getNumberFormat()->setFormatCode('#,##0.##');
            } else {
                $ws->setCellValueExplicit([$i, 2], (string) $value, DataType::TYPE_STRING);
            }
        }
        $ws->getStyle([1, 1, $i, 1])->getFont()->setBold(true);
        $ws->getStyle([1, 2, $i, 2])->getFont()->setItalic(true)->getColor()->setRGB('71717A');
        $ws->freezePane('A2');

        $ref = $book->createSheet();
        $ref->setTitle(self::sheetTitle(t('import.template.reference')));
        $ref->setCellValue([1, 1], t('import.template.section'));
        $ref->setCellValue([2, 1], t('import.template.value'));
        $ref->setCellValue([3, 1], t('import.template.meaning'));
        $ref->getStyle([1, 1, 3, 1])->getFont()->setBold(true);
        $r = 2;
        $sections = [t('import.template.rules') => array_map(fn ($rule) => ['', $rule], [
            t('import.rules.required'),
            t('import.rules.dates'),
            t('import.rules.numbers'),
            t('import.rules.limits', ['rows' => ImportFile::MAX_ROWS, 'mb' => 5]),
            t('import.rules.example'),
            ...$this->rules(),
        ])] + $this->reference();
        foreach ($sections as $title => $items) {
            $ref->setCellValueExplicit([1, $r], (string) $title, DataType::TYPE_STRING);
            $ref->getStyle([1, $r])->getFont()->setBold(true);
            foreach ($items as [$value, $meaning]) {
                $ref->setCellValueExplicit([2, $r], (string) $value, DataType::TYPE_STRING);
                $ref->setCellValueExplicit([3, $r], (string) $meaning, DataType::TYPE_STRING);
                $r++;
            }
            if (! $items) {
                $ref->setCellValueExplicit([3, $r], t('import.template.emptyList'), DataType::TYPE_STRING);
                $r++;
            }
            $r++;
        }
        $ref->getColumnDimension('A')->setWidth(28);
        $ref->getColumnDimension('B')->setWidth(34);
        $ref->getColumnDimension('C')->setWidth(70);
        $book->setActiveSheetIndex(0);

        $tmp = tempnam(sys_get_temp_dir(), 'imp');
        (new Xlsx($book))->save($tmp);
        $data = (string) file_get_contents($tmp);
        @unlink($tmp);
        $book->disconnectWorksheets();

        return $data;
    }

    private static function sheetTitle(string $s): string
    {
        return mb_substr(str_replace(['*', ':', '/', '\\', '?', '[', ']'], ' ', $s), 0, 31);
    }

    // ─────────────────────────── Общие проверки ───────────────────────────

    /**
     * Ответственный по email: активный менеджер или РОП в зоне назначающего (как CrmAccess::assignableOwner).
     * Пусто — null (назначение по умолчанию, как при ручном создании).
     */
    protected function owner(?string $email, string $code, string $column = 'owner_email'): ?User
    {
        if ($email === null) {
            return null;
        }
        $user = $this->userByEmail($email, $column);
        if (! in_array($user->role?->code, CrmAccess::OWNER_ROLES, true)) {
            throw new RowError(t('import.errors.ownerRole', ['email' => $user->email]));
        }
        if (! self::inScope($user, $code)) {
            throw new RowError(t('import.errors.ownerScope', ['email' => $user->email]));
        }

        return $user;
    }

    /** Сотрудник в зоне права $code (ALL — любой; TEAM — свой отдел; OWN — только сам). */
    protected static function inScope(User $user, string $code): bool
    {
        $access = access();
        $scope = $access->permissions[$code] ?? null;

        return $scope === 'ALL' || $user->id === $access->id()
            || ($scope === 'TEAM' && $user->team_id && in_array($user->team_id, $access->teamIds(), true));
    }

    protected function userByEmail(string $email, string $column): User
    {
        $e = Values::email($email, $this->col($column));
        $user = User::with('role')->whereRaw('LOWER(email) = ?', [$e])->where('status', 'ACTIVE')->first();
        if (! $user) {
            throw new RowError(t('import.errors.userNotFound', ['email' => $email]));
        }

        return $user;
    }

    /** @return iterable<LeadSource> */
    protected function sources(ImportContext $ctx): iterable
    {
        return $ctx->cached('sources', fn () => LeadSource::where('is_active', true)->orderBy('sort')->get());
    }

    /** @return iterable<Service> */
    protected function services(ImportContext $ctx): iterable
    {
        return $ctx->cached('services', fn () => Service::where('is_active', true)->orderBy('sort')->get());
    }

    /**
     * Клиент по номеру (C-00001) или точному названию среди видимых пользователю.
     * Не найден: null, если разрешено создать нового, иначе ошибка строки.
     */
    protected function client(string $value, bool $mayCreate): ?Client
    {
        $number = Values::number($value, 'C');
        if ($number !== null && preg_match('/^\s*c/i', $value)) {
            $client = CrmAccess::clients()->where('number', $number)->first();
            if (! $client) {
                throw new RowError(t('import.errors.clientNotFound', ['value' => $value]));
            }

            return $client;
        }
        $matches = CrmAccess::clients()->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower(trim($value))])->limit(2)->get();
        if ($matches->count() > 1) {
            throw new RowError(t('import.errors.clientAmbiguous', ['value' => $value]));
        }
        if ($matches->count() === 1) {
            return $matches->first();
        }
        if (! $mayCreate) {
            throw new RowError(t('import.errors.clientNotFound', ['value' => $value]));
        }

        return null;
    }

    /** Код записи для отчёта: L-00001. */
    protected static function code(string $prefix, ?int $number): string
    {
        return Format::code($prefix, $number);
    }

    /** Список «email — имя» сотрудников для справочника. */
    protected static function people(iterable $users): array
    {
        $out = [];
        foreach ($users as $u) {
            $out[] = [$u->email, $u->full_name.($u->role ? ' · '.t('roles.'.$u->role->code) : '')];
        }

        return $out;
    }

    /** Значения справочника для листа «Справочник»: [код, название на языке пользователя]. */
    protected static function refRows(iterable $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $out[] = [$r->code, method_exists($r, 'label') ? $r->label() : Lang::name($r)];
        }

        return $out;
    }

    /** Значения перечисления: [код, подпись]. */
    protected static function enumRows(array $codes, string $prefix): array
    {
        return array_map(fn ($c) => [$c, t($prefix.'.'.$c)], $codes);
    }

    /** Сотрудники-владельцы (менеджеры/РОП) в зоне права — для справочника. */
    protected static function assignable(string $code): array
    {
        $users = User::with('role')->where('status', 'ACTIVE')
            ->whereHas('role', fn ($q) => $q->whereIn('code', CrmAccess::OWNER_ROLES))->orderBy('full_name')->get()
            ->filter(fn (User $u) => self::inScope($u, $code));

        return self::people($users);
    }
}
