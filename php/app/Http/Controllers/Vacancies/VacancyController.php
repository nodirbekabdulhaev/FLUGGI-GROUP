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
use Symfony\Component\HttpFoundation\Response;

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

        return view('vacancies.show', ['a' => $a, 'hasResumeHtml' => filled($a->resume_html), 'canManage' => access()->can('vacancy.manage')]);
    }

    /**
     * Резюме кандидата (HTML от сайта) для фрейма карточки и печати в PDF. Содержимое чужое, поэтому строгий CSP: без скриптов сайта,
     * без запросов на чужие адреса, кроме картинок и стилей по https; сценарий печати выполняется только по одноразовому nonce.
     */
    public function resume(Request $request, string $id): Response
    {
        $html = VacancyApplication::whereKey($id)->value('resume_html');
        abort_if($html === null || $html === '', 404);
        $nonce = base64_encode(random_bytes(16));
        if ($request->boolean('print')) {
            $script = '<script nonce="'.$nonce.'">addEventListener("load",function(){setTimeout(function(){print()},300)})</script>';
            $html = stripos($html, '</body>') !== false ? preg_replace('~</body>~i', $script.'</body>', $html, 1) : $html.$script;
        }

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Content-Security-Policy' => "default-src 'none'; img-src https: data:; style-src 'unsafe-inline' https:; font-src https: data:; script-src 'nonce-".$nonce."'; sandbox allow-modals allow-scripts",
            'Referrer-Policy' => 'no-referrer',
        ]);
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
        // С карточки кандидата возвращаемся к списку, с канбана — остаёмся на нём
        $back = str_contains(url()->previous(), '/vacancies/'.$id) ? redirect('/vacancies') : back();

        return $back->with('ok', t('vacancies.deleted'));
    }
}
