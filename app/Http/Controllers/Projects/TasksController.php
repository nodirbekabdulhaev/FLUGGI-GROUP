<?php

namespace App\Http\Controllers\Projects;

use App\Http\Controllers\Controller;
use App\Services\Projects\ProjectAccess;
use App\Services\Projects\TaskService;
use App\Support\Format;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Задачи (ТЗ §22–24, §60): мои задачи, сегодня, просрочено; Kanban и карточка задачи — в проекте. */
class TasksController extends Controller
{
    public function index(Request $request): View
    {
        $f = $request->validate([
            'view' => ['nullable', Rule::in(TaskService::VIEWS)],
            'q' => ['nullable', 'string', 'max:100'],
            'mine' => ['nullable', 'in:0,1'],
        ]);
        // Руководитель видит задачи команды; исполнитель — только свои (это ограничивает и выборка)
        $lead = access()->can('task.create', 'TEAM');
        $mine = $request->has('mine') ? $request->query('mine') === '1' : ! $lead;
        $view = $f['view'] ?? 'all';

        return view('projects.tasks', [
            'view' => $view, 'q' => $f['q'] ?? '', 'mine' => $mine, 'lead' => $lead,
            'tasks' => TaskService::list(['view' => $view, 'mine' => $mine, 'q' => $f['q'] ?? null]),
        ]);
    }

    private function fields(Request $request, bool $create): array
    {
        $data = $request->validate([
            'project_id' => [$create ? 'required' : 'prohibited', 'uuid'],
            'title' => [$create ? 'required' : 'sometimes', 'string', 'max:300'],
            'description' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'assignee_id' => [$create ? 'required' : 'sometimes', 'uuid'],
            'priority' => ['sometimes', Rule::in(ProjectAccess::PRIORITIES)],
            'start_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'deadline' => ['sometimes', 'nullable', 'date_format:Y-m-d\TH:i'],
            'progress_pct' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'add_member' => [$create ? 'sometimes' : 'prohibited', 'boolean'],
        ], [], ['assignee_id' => t('tasks.fields.assignee')]);
        if (array_key_exists('deadline', $data)) {
            // Поле формы — время по Ташкенту
            $data['deadline'] = Format::fromLocalInput($data['deadline']);
        }

        return $data;
    }

    public function store(Request $request): RedirectResponse
    {
        $task = TaskService::create($this->fields($request, true));

        return redirect('/projects/'.$task->project_id)->with('ok', t('tasks.created'));
    }

    public function update(Request $request, string $id): RedirectResponse|JsonResponse
    {
        $task = TaskService::update($id, $this->fields($request, false));
        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'progressPct' => $task->progress_pct]);
        }

        return redirect('/projects/'.$task->project_id.'?task='.$task->id)->with('ok', t(array_keys($request->except(['_token', '_method'])) === ['progress_pct'] ? 'tasks.progressSaved' : 'tasks.saved'));
    }

    /** Перемещение карточки (Kanban — JSON, карточка задачи — форма). */
    public function move(Request $request, string $id): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(ProjectAccess::TASK_STATUSES)],
            'before_id' => ['nullable', 'uuid'],
        ]);
        $task = TaskService::move($id, $data['status'], $data['before_id'] ?? null);
        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'status' => $task->status, 'message' => t('tasks.moved')]);
        }

        return back()->with('ok', t('tasks.moved'));
    }

    public function destroy(string $id): RedirectResponse
    {
        $task = TaskService::remove($id);

        return redirect('/projects/'.$task->project_id)->with('ok', t('tasks.deleted'));
    }

    public function comment(Request $request, string $id): RedirectResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:5000']]);
        TaskService::comment($id, trim($data['body']));

        return back()->with('ok', t('tasks.commentAdded'));
    }
}
