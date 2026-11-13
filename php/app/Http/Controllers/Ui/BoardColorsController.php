<?php

namespace App\Http\Controllers\Ui;

use App\Http\Controllers\Controller;
use App\Services\Crm\Crm;
use App\Support\BoardColors;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Настройки → Цвета досок: цвета статусов SMM и задач проектов (этапы воронки — в справочниках). */
class BoardColorsController extends Controller
{
    public function index(): View
    {
        return view('settings.board-colors', [
            'boards' => BoardColors::all(),
            'stages' => Crm::stages(),
            'canEdit' => access()->can('settings.manage', 'ALL') || access()->can('reference.manage', 'ALL'),
            'canStages' => access()->can('reference.manage'),
        ]);
    }

    public function update(Request $request, string $board): RedirectResponse|JsonResponse
    {
        $statuses = BoardColors::statuses()[$board] ?? abort(404);
        $data = $request->validate([
            'colors' => ['required', 'array:'.implode(',', $statuses)],
            'colors.*' => ['required', 'string', 'regex:'.BoardColors::HEX],
        ], [
            'colors.*.regex' => t('boardColors.errors.hex'),
            'colors.*.required' => t('boardColors.errors.hex'),
        ]);
        BoardColors::save($board, $data['colors']);
        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'colors' => BoardColors::for($board)]);
        }

        return back()->with('ok', t('boardColors.saved'));
    }
}
