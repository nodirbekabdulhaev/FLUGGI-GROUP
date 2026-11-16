<?php

namespace App\Services\Vacancies;

use App\Models\VacancyApplication;
use App\Models\VacancyPosting;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Вакансии компании: создание в CRM, публикация на сайте (лента для сайта — только опубликованные). */
final class PostingService
{
    public const STATUSES = ['DRAFT', 'PUBLISHED', 'CLOSED'];

    public const EMPLOYMENT = ['FULL', 'PART', 'REMOTE', 'INTERN'];

    private const FIELDS = ['title_ru', 'title_uz', 'title_en', 'description_ru', 'description_uz', 'description_en', 'city', 'employment', 'salary', 'status', 'sort'];

    public static function list(): array
    {
        $counts = VacancyApplication::query()->whereNotNull('vacancy_slug')->selectRaw('vacancy_slug, COUNT(*) AS c')->groupBy('vacancy_slug')->pluck('c', 'vacancy_slug');

        return VacancyPosting::orderByRaw("FIELD(status, 'PUBLISHED', 'DRAFT', 'CLOSED')")->orderBy('sort')->orderByDesc('created_at')->get()
            ->map(fn ($p) => ['posting' => $p, 'applications' => (int) ($counts[$p->slug] ?? 0)])->all();
    }

    private static function clean(array $d): array
    {
        $out = [];
        foreach (self::FIELDS as $k) {
            $v = $d[$k] ?? null;
            $out[$k] = is_string($v) ? (trim($v) === '' ? null : trim($v)) : $v;
        }
        $out['status'] = in_array($out['status'], self::STATUSES, true) ? $out['status'] : 'DRAFT';
        $out['employment'] = in_array($out['employment'], self::EMPLOYMENT, true) ? $out['employment'] : 'FULL';
        $out['sort'] = (int) ($out['sort'] ?? 0);

        return $out;
    }

    private static function slug(string $title, ?string $wanted, ?string $exceptId = null): string
    {
        $base = Str::slug($wanted ?: $title) ?: 'vacancy';
        $slug = $base;
        for ($i = 2; VacancyPosting::withTrashed()->where('slug', $slug)->when($exceptId, fn ($q) => $q->where('id', '<>', $exceptId))->exists(); $i++) {
            $slug = $base.'-'.$i;
        }

        return $slug;
    }

    public static function create(array $d): VacancyPosting
    {
        $data = self::clean($d);

        return DB::transaction(function () use ($data, $d) {
            $p = VacancyPosting::create($data + ['slug' => self::slug($data['title_ru'], $d['slug'] ?? null), 'created_by_id' => access()->id(),
                'published_at' => $data['status'] === 'PUBLISHED' ? now() : null]);
            Audit::log('vacancy.posting.create', 'vacancy_posting', $p->id, ['title' => ['old' => null, 'new' => $p->title_ru], 'status' => ['old' => null, 'new' => $p->status]]);

            return $p;
        });
    }

    public static function update(string $id, array $d): VacancyPosting
    {
        $data = self::clean($d);

        return DB::transaction(function () use ($id, $data) {
            $p = VacancyPosting::lockForUpdate()->findOrFail($id);
            $before = ['title_ru' => $p->title_ru, 'status' => $p->status];
            $publishedAt = $data['status'] === 'PUBLISHED' ? ($p->published_at ?? now()) : $p->published_at;
            $p->update($data + ['published_at' => $publishedAt]);
            if ($diff = Audit::diff($before, ['title_ru' => $p->title_ru, 'status' => $p->status], ['title_ru', 'status'])) {
                Audit::log('vacancy.posting.update', 'vacancy_posting', $p->id, $diff);
            }

            return $p;
        });
    }

    public static function setStatus(string $id, string $status): VacancyPosting
    {
        $p = VacancyPosting::findOrFail($id);

        return self::update($id, ['status' => $status] + $p->only(self::FIELDS));
    }

    public static function delete(string $id): void
    {
        DB::transaction(function () use ($id) {
            $p = VacancyPosting::lockForUpdate()->findOrFail($id);
            $p->delete();
            Audit::log('vacancy.posting.delete', 'vacancy_posting', $p->id, ['title' => ['old' => $p->title_ru, 'new' => null]]);
        });
    }

    /** Лента для сайта: только опубликованные; названия и описания на трёх языках (нет перевода — русский). */
    public static function feed(): array
    {
        return VacancyPosting::where('status', 'PUBLISHED')->orderBy('sort')->orderByDesc('published_at')->get()->map(fn ($p) => [
            'slug' => $p->slug,
            'title' => ['ru' => $p->title_ru, 'uz' => $p->title_uz ?: $p->title_ru, 'en' => $p->title_en ?: $p->title_ru],
            'description' => ['ru' => $p->description_ru, 'uz' => $p->description_uz ?: $p->description_ru, 'en' => $p->description_en ?: $p->description_ru],
            'city' => $p->city, 'employment' => $p->employment, 'salary' => $p->salary, 'published_at' => $p->published_at?->toIso8601String(),
        ])->all();
    }
}
