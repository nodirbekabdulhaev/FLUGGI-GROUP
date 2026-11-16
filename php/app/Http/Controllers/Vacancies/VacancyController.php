<?php

namespace App\Http\Controllers\Vacancies;

use App\Http\Controllers\Controller;
use App\Services\Vacancies\VacancyService;
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

        return view('vacancies.index', [
            'items' => VacancyService::query($f['status'] ?? null, $f['q'] ?? null)->paginate(50)->withQueryString(),
            'filters' => $f, 'counts' => VacancyService::counts(), 'canManage' => access()->can('vacancy.manage'),
        ]);
    }

    public function update(Request $request, string $id): RedirectResponse
    {
        $d = $request->validate(['status' => ['nullable', Rule::in(VacancyService::STATUSES)], 'comment' => ['nullable', 'string', 'max:2000']]);
        VacancyService::update($id, $d['status'] ?? null, array_key_exists('comment', $d) ? ($d['comment'] ?? '') : null);

        return back()->with('ok', t('vacancies.saved'));
    }

    public function destroy(string $id): RedirectResponse
    {
        VacancyService::delete($id);

        return back()->with('ok', t('vacancies.deleted'));
    }
}
