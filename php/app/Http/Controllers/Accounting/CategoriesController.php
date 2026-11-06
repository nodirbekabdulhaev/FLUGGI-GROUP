<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\AccCategory;
use App\Services\Accounting\CategoriesService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Свои статьи прихода и расхода: каждая учитывается как одна из базовых категорий. */
class CategoriesController extends Controller
{
    public function index(Request $request): View
    {
        $canManage = access()->can('accounting.manage');
        $editing = $canManage && ($id = $request->query('edit')) ? AccCategory::findOrFail($id) : null;

        return view('accounting.categories', ['categories' => CategoriesService::list(), 'canManage' => $canManage, 'editing' => $editing]);
    }

    private function input(Request $request): array
    {
        return $request->validate([
            'direction' => ['required', Rule::in(['IN', 'OUT'])],
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'base' => ['required', 'string', 'max:32'],
            'keywords' => ['nullable', 'string', 'max:1000'],
            'sort' => ['nullable', 'integer', 'min:0', 'max:1000'],
        ]) + ['keywords' => null];
    }

    public function store(Request $request): RedirectResponse
    {
        CategoriesService::save(null, $this->input($request));

        return back()->with('ok', t('accounting.categories.created'));
    }

    public function update(Request $request, string $id): RedirectResponse
    {
        CategoriesService::save($id, $this->input($request));

        return redirect('/accounting/categories')->with('ok', t('accounting.categories.saved'));
    }

    public function active(Request $request, string $id): RedirectResponse
    {
        CategoriesService::setActive($id, $request->boolean('active'));

        return back()->with('ok', t('accounting.categories.saved'));
    }

    public function destroy(string $id): RedirectResponse
    {
        CategoriesService::delete($id);

        return redirect('/accounting/categories')->with('ok', t('accounting.categories.deleted'));
    }
}
