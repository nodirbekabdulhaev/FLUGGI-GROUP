<?php

namespace App\Services\Accounting;

use App\Exceptions\BusinessRule;
use App\Models\AccAccount;
use App\Models\AccCategory;
use App\Models\AccTransaction;
use App\Services\Import\ImportStore;
use App\Support\Audit;
use App\Support\Settings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Загрузка банковской выписки: файл → предпросмотр (счёт, категории, дубли) → подтверждение → итог.
 * Разобранные строки хранятся во временном файле предпросмотра (ImportStore), сам файл не сохраняется.
 *
 * Дубли: у каждой строки отпечаток external_ref (номер документа, дата, сумма, направление и номер повтора
 * одинаковых строк в файле), уникальный в пределах счёта — повторная загрузка пересекающихся периодов
 * ничего не задваивает. Операции, внесённые вручную (тот же счёт, дата, направление и сумма), отмечаются
 * как «возможный дубль» и по умолчанию не загружаются.
 */
final class BankImportService
{
    public const ENTITY = 'acc_bank';

    public const SOURCE = 'BANK_IMPORT';

    /** Строки, которые можно загрузить (возможный дубль — только если отмечен вручную). */
    public const IMPORTABLE = ['new', 'possible'];

    public static function upload(UploadedFile $file, ?string $accountId): string
    {
        @set_time_limit(300);
        $parsed = BankStatement::read((string) $file->getRealPath(), $file->getClientOriginalExtension());
        $account = $accountId ? AccAccount::where('is_active', true)->find($accountId) : null;
        $account ??= self::matchAccount($parsed['accountNumber']);

        return ImportStore::put([
            'entity' => self::ENTITY, 'user_id' => access()->id(),
            'file' => mb_substr($file->getClientOriginalName(), 0, 191), 'account_id' => $account?->id,
        ] + $parsed);
    }

    /** Активный счёт с тем же номером банковского счёта, что в шапке выписки. */
    public static function matchAccount(?string $number): ?AccAccount
    {
        $number = preg_replace('/\D+/', '', (string) $number);

        return $number === '' ? null : AccAccount::where('is_active', true)->where('account_number', $number)->orderBy('sort')->first();
    }

    public static function preview(string $token): ?array
    {
        return ImportStore::valid($token) ? ImportStore::get($token, self::ENTITY, (string) access()->id()) : null;
    }

    /** Сменить счёт предпросмотра: новый токен (статусы дублей пересчитываются по новому счёту). null — нет предпросмотра или счёта. */
    public static function setAccount(string $token, string $accountId): ?string
    {
        $data = self::preview($token);
        $acc = AccAccount::where('is_active', true)->find($accountId);
        if (! $data || ! $acc) {
            return null;
        }
        $data['account_id'] = $acc->id;
        $new = ImportStore::put($data);
        ImportStore::forget($token);

        return $new;
    }

    /** Отпечаток строки выписки. */
    public static function ref(array $row, int $occurrence): string
    {
        $doc = ltrim((string) ($row['doc'] ?? ''), '0');

        return sha1(implode('|', [$doc, $row['date'], $row['amount'], $row['direction'], $occurrence]));
    }

    /**
     * Строки с отпечатком, статусом (new | duplicate | possible | error) и предложенной категорией.
     *
     * @return list<array<string,mixed>>
     */
    public static function analyze(array $data, ?AccAccount $account): array
    {
        $seen = [];
        $rows = [];
        foreach ($data['rows'] as $i => $r) {
            $key = ltrim((string) ($r['doc'] ?? ''), '0').'|'.$r['date'].'|'.$r['amount'].'|'.$r['direction'];
            $seen[$key] = ($seen[$key] ?? 0) + 1;
            $rows[] = $r + ['idx' => $i, 'ref' => self::ref($r, $seen[$key]), 'status' => 'new', 'message' => null];
        }
        $articles = AccCategory::where('is_active', true)->whereNotNull('keywords')->where('keywords', '<>', '')
            ->orderBy('sort')->orderBy('name')->get()->filter(fn ($a) => in_array($a->base, Accounting::manual($a->direction), true))->values();
        $inn = (string) (Settings::company()['inn'] ?? '');
        foreach ($rows as &$r) {
            $s = BankRules::suggest($r, $articles, $inn);
            $r['category'] = $s['category_id'] ?? $s['category'];
            $r['base'] = $s['category'];
            $r['rule'] = $s['rule'];
            $r['period'] = substr($r['date'], 0, 7);
        }
        unset($r);
        if (! $account) {
            return $rows;
        }

        $existing = [];
        foreach (array_chunk(array_column($rows, 'ref'), 500) as $chunk) {
            foreach (AccTransaction::withTrashed()->where('account_id', $account->id)->whereIn('external_ref', $chunk)->pluck('external_ref') as $ref) {
                $existing[$ref] = true;
            }
        }
        $dates = array_column($rows, 'date');
        $manual = [];
        if ($dates) {
            AccTransaction::where('account_id', $account->id)->whereNull('external_ref')
                ->where('date', '>=', min($dates))->where('date', '<=', max($dates))
                ->get(['date', 'direction', 'amount'])
                ->each(function ($t) use (&$manual) {
                    $k = $t->date->toDateString().'|'.$t->direction.'|'.$t->amount;
                    $manual[$k] = ($manual[$k] ?? 0) + 1;
                });
        }
        $opening = $account->opening_date?->toDateString();
        foreach ($rows as &$r) {
            $k = $r['date'].'|'.$r['direction'].'|'.$r['amount'];
            if (isset($existing[$r['ref']])) {
                $r['status'] = 'duplicate';
            } elseif ($opening && $r['date'] < $opening) {
                $r['status'] = 'error';
                $r['message'] = t('accounting.errors.beforeOpening', ['date' => d($account->opening_date)]);
            } elseif (($manual[$k] ?? 0) > 0) {
                $r['status'] = 'possible';
                $manual[$k]--;
            }
        }
        unset($r);

        return $rows;
    }

    /** @return array<string,int> */
    public static function totals(array $rows): array
    {
        $t = ['new' => 0, 'duplicate' => 0, 'possible' => 0, 'error' => 0];
        foreach ($rows as $r) {
            $t[$r['status']] = ($t[$r['status']] ?? 0) + 1;
        }

        return $t;
    }

    /** Категории, которые можно выбрать в загрузке: ручные для направления и перевод. */
    public static function codes(string $direction): array
    {
        return [...Accounting::manual($direction), $direction === 'IN' ? 'TRANSFER_IN' : 'TRANSFER_OUT'];
    }

    /**
     * Загрузка отмеченных строк одной транзакцией. $choices: idx → {on, category, period}.
     * null — предпросмотр уже забран (повторная отправка) или истёк.
     *
     * @param  array<int|string, array{on?:mixed,category?:mixed,period?:mixed}>  $choices
     */
    public static function confirm(string $token, array $choices): ?array
    {
        $uid = (string) access()->id();
        $data = self::preview($token);
        if (! $data) {
            return null;
        }
        $acc = $data['account_id'] ? AccAccount::where('is_active', true)->find($data['account_id']) : null;
        if (! $acc) {
            throw new BusinessRule(t('accounting.bank.errors.account'), ['account_id' => t('accounting.bank.errors.account')]);
        }
        $data = ImportStore::claim($token, self::ENTITY, $uid);
        if (! $data) {
            return null;
        }

        $result = DB::transaction(function () use ($data, $choices, $acc) {
            $acc = AccAccount::lockForUpdate()->findOrFail($acc->id);
            $rows = self::analyze($data, $acc);
            $t = ['created' => 0, 'duplicate' => 0, 'unchecked' => 0, 'error' => 0, 'notCompleted' => (int) $data['notCompleted'], 'unreadable' => count($data['errors'])];
            $errors = [];
            foreach ($rows as $r) {
                if ($r['status'] === 'duplicate') {
                    $t['duplicate']++;

                    continue;
                }
                if ($r['status'] === 'error') {
                    $t['error']++;
                    $errors[] = ['n' => $r['n'], 'message' => $r['message']];

                    continue;
                }
                $c = $choices[$r['idx']] ?? [];
                $on = array_key_exists('on', $c) ? (bool) $c['on'] : $r['status'] === 'new';
                if (! $on) {
                    $t['unchecked']++;

                    continue;
                }
                try {
                    [$category, $categoryId] = self::category($r['direction'], is_string($c['category'] ?? null) ? $c['category'] : $r['category']);
                } catch (BusinessRule $e) {
                    $t['error']++;
                    $errors[] = ['n' => $r['n'], 'message' => $e->getMessage()];

                    continue;
                }
                $period = null;
                if ($category === 'SALARY' || in_array($category, Accounting::TAX_CATEGORIES, true)) {
                    $p = is_string($c['period'] ?? null) ? $c['period'] : $r['period'];
                    $period = preg_match(Accounting::PERIOD_RE, $p) ? $p : $r['period'];
                }
                $details = array_filter([
                    $r['inn'] ? t('accounting.bank.inn').': '.$r['inn'] : null,
                    $r['cpAccount'] ? t('accounting.bank.cpAccount').': '.$r['cpAccount'] : null,
                    $r['doc'] ? t('accounting.bank.doc').': '.$r['doc'] : null,
                ]);
                $desc = trim(($r['purpose'] ?? '').($details ? ' ('.implode(', ', $details).')' : ''));
                AccTransaction::create([
                    'account_id' => $acc->id, 'direction' => $r['direction'], 'category' => $category, 'category_id' => $categoryId,
                    'amount' => $r['amount'], 'date' => $r['date'], 'period' => $period,
                    'counterparty' => $r['counterparty'] ? mb_substr($r['counterparty'], 0, 191) : null,
                    'description' => $desc === '' ? null : mb_substr($desc, 0, 2000),
                    'source' => self::SOURCE, 'external_ref' => $r['ref'], 'created_by_id' => access()->id(),
                ]);
                $t['created']++;
            }
            $check = self::balanceCheck($acc, $data);
            Audit::log('accounting.bank_import', 'acc_account', $acc->id, [
                'import' => ['old' => null, 'new' => [
                    'file' => $data['file'], 'format' => $data['format'], 'account' => $acc->name,
                    'from' => $data['from'], 'to' => $data['to'], 'source' => self::SOURCE,
                ] + $t],
            ]);

            return ['entity' => self::ENTITY, 'user_id' => access()->id(), 'file' => $data['file'], 'format' => $data['format'],
                'account_id' => $acc->id, 'account' => $acc->name, 'currency' => $acc->currency, 'from' => $data['from'], 'to' => $data['to'],
                'totals' => $t, 'errors' => array_merge($data['errors'], $errors), 'check' => $check];
        });
        ImportStore::finish($token, $result);

        return $result;
    }

    public static function result(string $token): ?array
    {
        return ImportStore::valid($token) ? ImportStore::result($token, self::ENTITY, (string) access()->id()) : null;
    }

    /**
     * Категория строки: код базовой категории (ручной или перевод) либо id активной статьи того же направления.
     *
     * @return array{0:string,1:?string}
     */
    private static function category(string $direction, string $value): array
    {
        if (Str::isUuid($value)) {
            $a = CategoriesService::forOperation($value, $direction);

            return [$a->base, $a->id];
        }
        if (! in_array($value, self::codes($direction), true)) {
            throw new BusinessRule(t('accounting.errors.category'));
        }

        return [$value, null];
    }

    /**
     * Сверка остатка: «Конец дня» из шапки выписки против остатка счёта на дату конца выписки.
     *
     * @return array{statement:string,account:string,difference:string,date:string}|null
     */
    public static function balanceCheck(AccAccount $acc, array $data): ?array
    {
        if ($data['closing'] === null || ! $data['to']) {
            return null;
        }
        $date = $data['to'];
        $s = AccTransaction::where('account_id', $acc->id)->where('date', '<=', $date)
            ->selectRaw("SUM(CASE WHEN direction = 'IN' THEN amount ELSE -amount END) AS s")->value('s');
        $opening = $acc->opening_date === null || $acc->opening_date->toDateString() <= $date ? Accounting::dec($acc->opening_balance) : Accounting::dec('0');
        $balance = $opening->plus(Accounting::dec($s));
        $statement = Accounting::dec($data['closing']);

        return ['statement' => (string) $statement->toScale(2), 'account' => (string) $balance->toScale(2),
            'difference' => (string) $balance->minus($statement)->toScale(2), 'date' => $date];
    }

    /** Строки для формы выбора категории: [направление => [значение => подпись]] с группами статей. */
    public static function choiceGroups(): array
    {
        $out = [];
        foreach (['IN', 'OUT'] as $d) {
            $groups = [t('accounting.operations.baseCategories') => collect(self::codes($d))->mapWithKeys(fn ($c) => [$c => t('accounting.category.'.$c)])->all()];
            foreach (CategoriesService::options($d) as $base => $list) {
                $groups[t('accounting.operations.articlesOf', ['base' => t('accounting.category.'.$base)])] = collect($list)->mapWithKeys(fn ($a) => [$a->id => $a->name])->all();
            }
            $out[$d] = $groups;
        }

        return $out;
    }

    /** Базовая категория для каждого выбираемого значения (статья → её база). */
    public static function choiceBases(): array
    {
        $out = [];
        foreach (['IN', 'OUT'] as $d) {
            foreach (CategoriesService::options($d) as $list) {
                foreach ($list as $a) {
                    $out[$a->id] = $a->base;
                }
            }
        }

        return $out;
    }

    /** @return Collection<int,AccAccount> */
    public static function accounts(): Collection
    {
        return AccAccount::where('is_active', true)->orderBy('sort')->orderBy('name')->get();
    }
}
