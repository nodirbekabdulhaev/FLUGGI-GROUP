<?php

namespace App\Http\Controllers\Vacancies;

use App\Http\Controllers\Controller;
use App\Services\Vacancies\PostingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Вакансии компании в CRM: создание, правка, публикация на сайте. */
class PostingController extends Controller
{
    public function index(): View
    {
        return view('vacancies.postings', ['rows' => PostingService::list(), 'canManage' => access()->can('vacancy.manage')]);
    }

    private function data(Request $request): array
    {
        return $request->validate([
            'title_ru' => ['required', 'string', 'min:2', 'max:191'], 'title_uz' => ['nullable', 'string', 'max:191'], 'title_en' => ['nullable', 'string', 'max:191'],
            'description_ru' => ['nullable', 'string', 'max:10000'], 'description_uz' => ['nullable', 'string', 'max:10000'], 'description_en' => ['nullable', 'string', 'max:10000'],
            'slug' => ['nullable', 'string', 'max:120', 'regex:/^[a-z0-9-]*$/'],
            'city' => ['nullable', 'string', 'max:100'], 'salary' => ['nullable', 'string', 'max:100'], 'sort' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'employment' => ['required', Rule::in(PostingService::EMPLOYMENT)], 'status' => ['required', Rule::in(PostingService::STATUSES)],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $p = PostingService::create($this->data($request));

        return redirect('/vacancies/postings')->with('ok', t($p->status === 'PUBLISHED' ? 'vacancies.postings.createdPublished' : 'vacancies.postings.created'));
    }

    public function update(Request $request, string $id): RedirectResponse
    {
        PostingService::update($id, $this->data($request));

        return back()->with('ok', t('vacancies.postings.saved'));
    }

    public function status(Request $request, string $id): RedirectResponse
    {
        $d = $request->validate(['status' => ['required', Rule::in(PostingService::STATUSES)]]);
        PostingService::setStatus($id, $d['status']);

        return back()->with('ok', t('vacancies.postings.'.($d['status'] === 'PUBLISHED' ? 'publishedNow' : 'statusChanged')));
    }

    public function destroy(string $id): RedirectResponse
    {
        PostingService::delete($id);

        return back()->with('ok', t('vacancies.postings.deleted'));
    }

    /** Публичная лента опубликованных вакансий для сайта (без токена: на сайте эти данные и так видны всем). */
    public function feed(): JsonResponse
    {
        return response()->json(['vacancies' => PostingService::feed()], 200, [
            'Access-Control-Allow-Origin' => '*', 'Access-Control-Allow-Methods' => 'GET, OPTIONS', 'Cache-Control' => 'public, max-age=60',
        ]);
    }
}
