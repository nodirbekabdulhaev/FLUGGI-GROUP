<?php

namespace App\Services\Vacancies;

use App\Models\User;
use App\Models\VacancyApplication;
use App\Services\Notifications\Notifier;
use App\Support\Audit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** Отклики на вакансии, пришедшие с сайта: список, статус, заметка, уведомление HR и CEO о новом отклике. */
final class VacancyService
{
    public const STATUSES = ['NEW', 'VIEWED', 'INTERVIEW', 'HIRED', 'REJECTED'];

    /** Число новых откликов (бейдж в меню). */
    public static function newCount(): int
    {
        return VacancyApplication::where('status', 'NEW')->count();
    }

    public static function query(?string $status, ?string $q): Builder
    {
        return VacancyApplication::query()
            ->when($status && in_array($status, self::STATUSES, true), fn ($b) => $b->where('status', $status))
            ->when($q, fn ($b) => $b->where(fn ($w) => $w->where('name', 'like', '%'.$q.'%')->orWhere('phone', 'like', '%'.$q.'%')->orWhere('email', 'like', '%'.$q.'%')->orWhere('vacancy_name', 'like', '%'.$q.'%')))
            ->orderByDesc('created_at');
    }

    /** Счётчики по статусам для вкладок. */
    public static function counts(): array
    {
        return VacancyApplication::query()->selectRaw('status, COUNT(*) AS c')->groupBy('status')->pluck('c', 'status')->map(fn ($v) => (int) $v)->all();
    }

    /** Новый отклик с сайта (вызывается приёмом заявок; повтор по external_id обрабатывает вызывающий код). */
    public static function createFromSite(array $in): VacancyApplication
    {
        $ctx = is_array($in['context'] ?? null) ? $in['context'] : [];
        $profile = is_array($in['candidate'] ?? null) ? $in['candidate'] : null;
        $assessment = is_array($in['assessment'] ?? null) ? $in['assessment'] : null;
        $eval = $profile || $assessment ? CandidateScore::evaluate($profile ?? [], $assessment ?? []) : null;
        // Сайт сам считает оценку (score, grade A–D): берём её; иначе оцениваем по нашей логике
        if ($eval && is_numeric($assessment['score'] ?? null)) {
            $site = max(0, min(100, (int) round((float) $assessment['score'])));
            $eval = ['score' => $site, 'level' => CandidateScore::level($site), 'parts' => [], 'flags' => $eval['flags']];
        }
        $row = DB::transaction(fn () => VacancyApplication::create([
            'external_id' => $in['external_id'], 'name' => $in['name'], 'phone' => $in['phone'], 'email' => $in['email'] ?? null, 'message' => $in['message'] ?? null,
            'vacancy_slug' => $in['product']['slug'] ?? null, 'vacancy_name' => $in['product']['name'] ?? null,
            'page_url' => isset($ctx['page_url']) ? mb_substr((string) $ctx['page_url'], 0, 1000) : null, 'locale' => $ctx['locale'] ?? null,
            'utm' => ! empty($in['utm']) ? $in['utm'] : null, 'site_created_at' => $in['created_at'] ?? null,
            'profile' => $profile, 'assessment' => $assessment,
            'score' => $eval['score'] ?? null, 'score_level' => $eval['level'] ?? null, 'score_parts' => $eval ? ['parts' => $eval['parts'], 'flags' => $eval['flags']] : null,
        ]));
        $ids = User::where('status', 'ACTIVE')->whereHas('role', fn ($q) => $q->whereIn('code', ['CEO', 'HR_ADMIN']))->pluck('id')->all();
        Notifier::notify($ids, 'vacancy.new', fn () => [t('vacancies.notifyTitle'), $row->name.' — '.($row->vacancy_name ?: t('vacancies.noVacancy')).($row->score !== null ? ' · '.$row->score.'/100' : '')], '/vacancies');

        return $row;
    }

    /** Пересчитать оценку (после смены логики) для всех откликов с анкетой; возвращает число пересчитанных. */
    public static function rescoreAll(): int
    {
        $n = 0;
        foreach (VacancyApplication::where(fn ($q) => $q->whereNotNull('profile')->orWhereNotNull('assessment'))->get() as $row) {
            if (is_numeric($row->assessment['score'] ?? null)) {
                continue;    // оценку посчитал сайт
            }
            $e = CandidateScore::evaluate($row->profile ?? [], $row->assessment ?? []);
            $row->forceFill(['score' => $e['score'], 'score_level' => $e['level'], 'score_parts' => ['parts' => $e['parts'], 'flags' => $e['flags']]])->save();
            $n++;
        }

        return $n;
    }

    public static function update(string $id, ?string $status, ?string $comment): VacancyApplication
    {
        return DB::transaction(function () use ($id, $status, $comment) {
            $row = VacancyApplication::lockForUpdate()->findOrFail($id);
            $before = ['status' => $row->status, 'comment' => $row->comment];
            $after = ['status' => $status && in_array($status, self::STATUSES, true) ? $status : $row->status, 'comment' => $comment !== null ? (trim($comment) ?: null) : $row->comment];
            $row->forceFill($after + ['updated_by_id' => access()->id()])->save();
            if ($diff = Audit::diff($before, $after, ['status', 'comment'])) {
                Audit::log('vacancy.update', 'vacancy_application', $row->id, $diff);
            }

            return $row;
        });
    }

    public static function delete(string $id): void
    {
        DB::transaction(function () use ($id) {
            $row = VacancyApplication::lockForUpdate()->findOrFail($id);
            $row->delete();
            Audit::log('vacancy.delete', 'vacancy_application', $row->id, ['name' => ['old' => $row->name, 'new' => null]]);
        });
    }
}
