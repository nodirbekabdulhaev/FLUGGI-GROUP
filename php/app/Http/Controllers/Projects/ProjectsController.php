<?php

namespace App\Http\Controllers\Projects;

use App\Http\Controllers\Controller;
use App\Models\Direction;
use App\Models\StoredFile;
use App\Services\Crm\Deletion;
use App\Services\Finance\CostLineService;
use App\Services\Finance\ExpenseService;
use App\Services\Finance\FinanceService;
use App\Services\Finance\PartnerService;
use App\Services\Projects\ProjectAccess;
use App\Services\Projects\ProjectService;
use App\Services\Projects\TaskService;
use App\Services\Projects\TemplateService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Проекты (ТЗ §19–21): списки, карточка с Kanban, командой, финансами, файлами и историей. */
class ProjectsController extends Controller
{
    public function index(Request $request, string $view): View
    {
        abort_unless(in_array($view, ProjectService::VIEWS, true), 404);
        $f = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(ProjectAccess::STATUSES)],
            'direction' => ['nullable', 'uuid'],
        ]);

        return view('projects.index', [
            'view' => $view,
            'filters' => $f,
            'projects' => ProjectService::list(['view' => $view, 'q' => $f['q'] ?? null, 'status' => $view === 'all' ? ($f['status'] ?? null) : null, 'directionId' => $f['direction'] ?? null]),
            'directions' => Direction::where('is_active', true)->orderBy('sort')->get(),
        ]);
    }

    public function show(Request $request, string $id): View
    {
        $project = ProjectService::detail($id);
        $access = access();
        $tab = $request->query('tab', 'tasks');
        $seesFinance = $access->can('finance.read') && ProjectAccess::can($id, 'finance.read');
        $tabs = ['tasks', 'team', ...($seesFinance ? ['finance'] : []), 'files', 'info', 'history'];
        if (! in_array($tab, $tabs, true)) {
            $tab = 'tasks';
        }
        $data = ['project' => $project, 'tab' => $tab, 'tabs' => $tabs];
        // Партнёр видит свою долю по проекту, даже если финансы проекта его роли закрыты
        if (! $seesFinance && PartnerService::isPartner()) {
            $data['partnerShare'] = PartnerService::projectShare($id);
        }
        $tasks = TaskService::list(['projectId' => $id], 100);
        $data['tasks'] = $tasks;
        $data['assignees'] = ProjectService::assignees($project);
        if ($taskId = $request->query('task')) {
            $data['openTask'] = TaskService::get($taskId);
            abort_if($data['openTask']->project_id !== $id, 404);
            $data['assignees'] = ProjectService::assignees($project, $data['openTask']->assignee);
        }
        if ($project->can['assign']) {
            $active = $project->team->where('status', 'ACTIVE')->pluck('user_id')->all();
            $data['candidates'] = ProjectService::candidates()->reject(fn ($u) => in_array($u->id, $active, true));
        }
        if ($project->can['update']) {
            $data['templates'] = TemplateService::list(true);
            $data['directions'] = Direction::where('is_active', true)->orderBy('sort')->get();
        }
        if ($tab === 'history') {
            $data['timeline'] = ProjectService::timeline($id);
        }
        if ($tab === 'files') {
            $data['files'] = StoredFile::with('uploadedBy:id,full_name')->where('project_id', $id)->orderByDesc('created_at')->get();
        }
        if ($tab === 'finance') {
            $data['finance'] = FinanceService::project($id);
            $data['costLines'] = CostLineService::list($id);
            $data['expenses'] = ExpenseService::list(['projectId' => $id], 100);
        }

        return view('projects.show', $data);
    }

    /** Удаление проекта с задачами — только CEO. Сделка, оплаты и финансовая история остаются. */
    public function destroy(string $id): RedirectResponse
    {
        Deletion::project(ProjectAccess::project($id, 'project.delete'));

        return redirect()->route('projects.index', 'all')->with('ok', t('projects.deleted'));
    }

    public function update(Request $request, string $id): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:200'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'priority' => ['sometimes', Rule::in(ProjectAccess::PRIORITIES)],
            'start_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'deadline' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'rop_id' => ['sometimes', 'uuid'],
            'direction_id' => ['sometimes', 'nullable', 'uuid'],
        ]);
        ProjectService::update($id, $data);

        return back()->with('ok', t('projects.saved'));
    }

    public function status(Request $request, string $id): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(ProjectAccess::STATUSES)],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);
        ProjectService::setStatus($id, $data['status'], $data['comment'] ?? null);

        return back()->with('ok', t('projects.statusChanged'));
    }

    public function applyTemplate(Request $request, string $id): RedirectResponse
    {
        $data = $request->validate(['template_id' => ['required', 'uuid']]);
        ProjectService::applyTemplate($id, $data['template_id']);

        return back()->with('ok', t('projects.templateApplied'));
    }

    public function addMember(Request $request, string $id): RedirectResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'uuid'],
            'role' => ['nullable', Rule::in(ProjectAccess::SPECIALTIES)],
            'workload_pct' => ['nullable', 'integer', 'min:0', 'max:100'],
            'deadline' => ['nullable', 'date_format:Y-m-d'],
        ]);
        ProjectService::addMember($id, $data);

        return redirect('/projects/'.$id.'?tab=team')->with('ok', t('projects.team.added'));
    }

    public function updateMember(Request $request, string $id, string $member): RedirectResponse
    {
        $data = $request->validate([
            'role' => ['sometimes', 'nullable', Rule::in(ProjectAccess::SPECIALTIES)],
            'workload_pct' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100'],
            'deadline' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'status' => ['sometimes', Rule::in(['ACTIVE', 'DONE', 'REMOVED'])],
        ]);
        ProjectService::updateMember($id, $member, $data);

        return redirect('/projects/'.$id.'?tab=team')->with('ok', t(($data['status'] ?? null) === 'REMOVED' ? 'projects.team.removed' : 'common.saved'));
    }
}
