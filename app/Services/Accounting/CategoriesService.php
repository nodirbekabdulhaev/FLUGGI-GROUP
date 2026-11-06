<?php

namespace App\Services\Accounting;

use App\Exceptions\BusinessRule;
use App\Models\AccCategory;
use App\Models\AccTransaction;
use App\Support\Audit;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Свои статьи прихода и расхода. Статья «учитывается как» базовая категория ручного ввода:
 * в операцию пишется category = base и category_id = статья, поэтому налоги, база IT Park и годовой отчёт
 * считаются по базовым категориям без изменений. Статья с операциями не удаляется — только отключается.
 * Смена base действует на новые операции: уже внесённые остаются с той категорией, с которой внесены.
 */
final class CategoriesService
{
    private const FIELDS = ['direction', 'name', 'base', 'keywords', 'sort'];

    /** Все статьи (по направлению, порядку и названию) с числом операций, включая удалённые. */
    public static function list(): Collection
    {
        $counts = AccTransaction::withTrashed()->whereNotNull('category_id')
            ->selectRaw('category_id, COUNT(*) AS n')->groupBy('category_id')->pluck('n', 'category_id');

        return AccCategory::query()->orderBy('direction')->orderByDesc('is_active')->orderBy('sort')->orderBy('name')->get()
            ->each(fn (AccCategory $c) => $c->setAttribute('operations_count', (int) ($counts[$c->id] ?? 0)));
    }

    /**
     * Активные статьи направления для формы операции: [base => [статьи…]] в порядке базовых категорий.
     *
     * @return array<string, list<AccCategory>>
     */
    public static function options(string $direction): array
    {
        $rows = AccCategory::where('direction', $direction)->where('is_active', true)
            ->whereIn('base', Accounting::manual($direction))->orderBy('sort')->orderBy('name')->get();
        $out = [];
        foreach (Accounting::manual($direction) as $base) {
            $items = $rows->where('base', $base)->values()->all();
            if ($items) {
                $out[$base] = $items;
            }
        }

        return $out;
    }

    /** @param  array{direction:string,name:string,base:string,keywords?:?string,sort:?int}  $data */
    public static function save(?string $id, array $data): AccCategory
    {
        $data['name'] = preg_replace('/\s+/u', ' ', trim($data['name']));
        $data['sort'] = (int) ($data['sort'] ?? 0);
        // Слова для автоподбора при загрузке выписки: через запятую, без повторов
        $data['keywords'] = implode(', ', array_map(fn ($k) => mb_substr($k, 0, 60), BankRules::keywords($data['keywords'] ?? null))) ?: null;
        if ($data['keywords'] !== null && mb_strlen($data['keywords']) > 500) {
            throw new BusinessRule(t('accounting.errors.keywordsLong'), ['keywords' => t('accounting.errors.keywordsLong')]);
        }

        return DB::transaction(function () use ($id, $data) {
            $before = $id ? AccCategory::lockForUpdate()->findOrFail($id) : null;
            if ($before && $before->direction !== $data['direction']
                && AccTransaction::withTrashed()->where('category_id', $before->id)->exists()) {
                throw new BusinessRule(t('accounting.errors.articleDirectionLocked'), ['direction' => t('accounting.errors.articleDirectionLocked')]);
            }
            if (! in_array($data['base'], Accounting::manual($data['direction']), true)) {
                throw new BusinessRule(t('accounting.errors.articleBase'), ['base' => t('accounting.errors.articleBase')]);
            }
            $taken = AccCategory::where('direction', $data['direction'])->where('name', $data['name'])
                ->when($before, fn ($q) => $q->whereKeyNot($before->id))->exists();
            if ($taken) {
                throw new BusinessRule(t('accounting.errors.articleNameTaken'), ['name' => t('accounting.errors.articleNameTaken')]);
            }
            $old = $before ? $before->only(self::FIELDS) : [];
            $row = $before ? tap($before)->update($data) : AccCategory::create($data + ['is_active' => true]);
            Audit::log($before ? 'accounting.category.update' : 'accounting.category.create', 'acc_category', $row->id, Audit::diff($old, $data, self::FIELDS));

            return $row;
        });
    }

    public static function setActive(string $id, bool $active): void
    {
        DB::transaction(function () use ($id, $active) {
            $row = AccCategory::lockForUpdate()->findOrFail($id);
            if ($row->is_active === $active) {
                return;
            }
            $row->update(['is_active' => $active]);
            Audit::log($active ? 'accounting.category.activate' : 'accounting.category.deactivate', 'acc_category', $id, ['is_active' => ['old' => ! $active, 'new' => $active]]);
        });
    }

    /** Удалить можно только статью без операций (включая удалённые операции); иначе — отключить. */
    public static function delete(string $id): void
    {
        DB::transaction(function () use ($id) {
            $row = AccCategory::lockForUpdate()->findOrFail($id);
            if (AccTransaction::withTrashed()->where('category_id', $id)->exists()) {
                throw new BusinessRule(t('accounting.errors.articleUsed'));
            }
            Audit::log('accounting.category.delete', 'acc_category', $id, [
                'name' => ['old' => $row->name, 'new' => null], 'direction' => ['old' => $row->direction, 'new' => null], 'base' => ['old' => $row->base, 'new' => null],
            ]);
            $row->delete();
        });
    }

    /**
     * Статья для новой операции: того же направления, активная, с допустимой базовой категорией.
     * Строка блокируется до конца транзакции, чтобы статью не отключили одновременно.
     */
    public static function forOperation(string $id, string $direction): AccCategory
    {
        $row = AccCategory::sharedLock()->find($id);
        if (! $row || $row->direction !== $direction || ! $row->is_active || ! in_array($row->base, Accounting::manual($direction), true)) {
            throw new BusinessRule(t('accounting.errors.articleInvalid'), ['category' => t('accounting.errors.articleInvalid')]);
        }

        return $row;
    }
}
