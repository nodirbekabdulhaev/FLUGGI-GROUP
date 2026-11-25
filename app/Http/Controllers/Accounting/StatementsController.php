<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Services\Accounting\Statements;
use App\Services\Finance\Input;
use App\Services\Import\RowError;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Баланс (форма №1) и финрезультаты (форма №2): строки считаются из системы, меняются вручную, переносятся на следующий год; загрузка сданных форм. */
class StatementsController extends Controller
{
    private function year(Request $request): int
    {
        $y = (int) $request->input('year', date('Y'));

        return $y >= 2000 && $y <= 2100 ? $y : (int) date('Y');
    }

    public function index(Request $request): View
    {
        $year = $this->year($request);
        $form = $request->query('form') === '2' ? '2' : '1';

        return view('accounting.statements', Statements::page($year) + ['form' => $form, 'unit' => $request->query('unit') === 'k' ? 'k' : 'u', 'canManage' => access()->can('accounting.manage'),
            'bOrder' => Statements::B_ORDER, 'fOrder' => Statements::F_ORDER, 'bInput' => Statements::B_INPUT, 'fInput' => Statements::F_INPUT]);
    }

    public function line(Request $request): RedirectResponse
    {
        Input::normalize($request, ['amount']);
        $d = $request->validate([
            'year' => ['required', 'integer', 'min:2000', 'max:2100'], 'form' => ['required', 'in:B,F'], 'code' => ['required', 'regex:/^\d{3}$/'],
            'amount' => ['nullable', 'regex:/^-?\d{1,15}(\.\d{1,2})?$/'], 'note' => ['nullable', 'string', 'max:500'], 'reset' => ['nullable', 'boolean'],
        ]);
        try {
            Statements::set((int) $d['year'], $d['form'], $d['code'], ! empty($d['reset']) ? null : ($d['amount'] ?? null), $d['note'] ?? null);
        } catch (RowError $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect('/accounting/statements?'.http_build_query(['year' => $d['year'], 'form' => $d['form'] === 'B' ? '1' : '2']))->with('ok', t('accounting.statements.saved'));
    }

    public function import(Request $request): RedirectResponse
    {
        $request->validate(['files' => ['required', 'array', 'min:1', 'max:10'], 'files.*' => ['file', 'max:5120', 'extensions:xltx,xlsx']], [
            'files.required' => t('import.errors.fileRequired'), 'files.*.extensions' => t('accounting.statements.errors.type'),
        ]);
        $r = Statements::import($request->file('files'));
        $back = redirect('/accounting/statements?year='.$this->year($request));
        if ($r['errors']) {
            $back->with('error', implode(' ', $r['errors']));
        }

        return $r['files'] ? $back->with('ok', t('accounting.statements.imported', ['files' => $r['files'], 'lines' => $r['lines']])) : $back;
    }
}
