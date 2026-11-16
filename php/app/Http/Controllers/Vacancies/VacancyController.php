<?php

namespace App\Http\Controllers\Vacancies;

use App\Http\Controllers\Controller;
use App\Models\VacancyApplication;
use App\Services\Vacancies\VacancyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Раздел «Вакансии»: отклики с сайта, статусы и заметки HR. */
class VacancyController extends Controller
{
    public function index(Request $request): View
    {
        $f = $request->validate(['status' => ['nullable', Rule::in(VacancyService::STATUSES)], 'q' => ['nullable', 'string', 'max:100']]);

        $view = $request->query('view') === 'list' ? 'list' : 'kanban';
        $query = VacancyService::query($f['status'] ?? null, $f['q'] ?? null);

        return view('vacancies.index', [
            'view' => $view,
            'board' => $view === 'kanban' ? (clone $query)->limit(500)->get()->groupBy('status') : null,
            'items' => $view === 'list' ? $query->paginate(50)->withQueryString() : null,
            'filters' => $f, 'counts' => VacancyService::counts(), 'canManage' => access()->can('vacancy.manage'),
        ]);
    }

    public function show(string $id): View
    {
        $a = VacancyApplication::findOrFail($id);
        if ($a->status === 'NEW' && access()->can('vacancy.manage')) {
            VacancyService::update($id, 'VIEWED', null);
            $a->refresh();
        }

        return view('vacancies.show', ['a' => $a, 'canManage' => access()->can('vacancy.manage')]);
    }

    public function rescore(): RedirectResponse
    {
        return back()->with('ok', t('vacancies.rescored', ['n' => VacancyService::rescoreAll()]));
    }

    public function update(Request $request, string $id): RedirectResponse|JsonResponse
    {
        $d = $request->validate(['status' => ['nullable', Rule::in(VacancyService::STATUSES)], 'comment' => ['nullable', 'string', 'max:2000']]);
        VacancyService::update($id, $d['status'] ?? null, array_key_exists('comment', $d) ? ($d['comment'] ?? '') : null);
        if ($request->expectsJson()) {
            return response()->json(['ok' => true]);
        }

        return back()->with('ok', t('vacancies.saved'));
    }

    public function destroy(string $id): RedirectResponse
    {
        VacancyService::delete($id);

        return back()->with('ok', t('vacancies.deleted'));
    }
}
