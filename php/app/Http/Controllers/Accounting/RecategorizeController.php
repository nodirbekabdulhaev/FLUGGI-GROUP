<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Services\Accounting\Accounting;
use App\Services\Accounting\Recategorize;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Проверка статей: операции статьи по получателям и распознанному типу платежа — перенос в верные статьи. */
class RecategorizeController extends Controller
{
    private function scope(Request $request): array
    {
        $direction = $request->input('direction') === 'IN' ? 'IN' : 'OUT';
        $category = (string) $request->input('category', $direction === 'IN' ? 'OTHER_INCOME' : 'TAX_OTHER');
        if (! array_key_exists($category, Recategorize::sources($direction))) {
            $category = array_key_first(Recategorize::sources($direction));
        }

        return [$direction, $category];
    }

    public function index(Request $request): View
    {
        [$direction, $category] = $this->scope($request);

        return view('accounting.recategorize', ['direction' => $direction, 'category' => $category, 'sources' => Recategorize::sources($direction),
            'groups' => Recategorize::groups($direction, $category), 'targets' => Recategorize::targets($direction)]);
    }

    public function apply(Request $request): RedirectResponse
    {
        [$direction, $category] = $this->scope($request);
        $data = $request->validate([
            'rows' => ['required', 'array', 'max:1000'],
            'rows.*.key' => ['required', 'regex:/^[0-9a-f]{40}$/'],
            'rows.*.category' => ['nullable', Rule::in(array_merge(Accounting::categories(), ['TRANSFER_IN', 'TRANSFER_OUT']))],
        ]);
        $r = Recategorize::apply($direction, $category, $data['rows']);

        return redirect('/accounting/recategorize?'.http_build_query(['direction' => $direction, 'category' => $category]))
            ->with('ok', t('accounting.recategorize.done', ['groups' => $r['groups'], 'operations' => $r['operations']]));
    }
}
