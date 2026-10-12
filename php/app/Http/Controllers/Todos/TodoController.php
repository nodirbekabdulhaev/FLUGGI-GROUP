<?php

namespace App\Http\Controllers\Todos;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Crm\CrmAccess;
use App\Services\Todos\RecurringTodoService;
use App\Services\Todos\TodoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * «Мои дела»: личные дела, поручения и регулярные дела (налоговый календарь).
 * Доступны каждому сотруднику для себя; поручить другому — по праву task.create.
 */
class TodoController extends Controller
{
    private const VIEWS = ['mine', 'assigned', 'all', 'recurring'];

    public function index(Request $request): View
    {
        $a = access();
        $views = ['mine', 'assigned'];
        // «Все»: CEO — вся компания, РОП — свой отдел (проверка — в сервисе)
        if (TodoService::seesAll($a) || TodoService::teamScope($a)) {
            $views[] = 'all';
        }
        $views[] = 'recurring';
        // «Все» без права — 403 (как в прежней версии)
        $view = in_array($request->query('view'), self::VIEWS, true) ? $request->query('view') : 'mine';
        $status = $request->query('status') === 'DONE' ? 'DONE' : 'OPEN';

        return view('todos.index', [
            'views' => $views,
            'view' => $view,
            'status' => $status,
            'items' => $view === 'recurring' ? null : TodoService::list($a, $view, $status),
            'rules' => $view === 'recurring' ? RecurringTodoService::list($a) : [],
            'access' => $a,
            'owners' => self::owners(),
            'clients' => self::clients(),
        ]);
    }

    /** Кому можно поручить: себе; РОП — своему отделу; CEO — всем. */
    public static function owners(): array
    {
        $a = access();
        $scope = $a->permissions['task.create'] ?? null;
        $q = User::where('status', 'ACTIVE')->orderBy('full_name');
        if ($scope === 'ALL') {
            // все
        } elseif ($scope === 'TEAM') {
            $q->where(fn ($w) => $w->whereIn('team_id', $a->teamIds() ?: ['-'])->orWhere('id', $a->id()));
        } else {
            $q->whereKey($a->id());
        }

        return $q->pluck('full_name', 'id')->all();
    }

    public static function clients(): array
    {
        return can('client.read') ? CrmAccess::clients()->orderBy('name')->limit(300)->pluck('name', 'id')->all() : [];
    }

    public function dock(): JsonResponse
    {
        return response()->json(['todos' => TodoService::dock(access())]);
    }

    private function input(Request $request, bool $partial): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'title' => [$req, 'string', 'min:1', 'max:300'],
            'description' => ['nullable', 'string', 'max:4000'],
            'kind' => ['sometimes', Rule::in(TodoService::KINDS)],
            'priority' => ['sometimes', Rule::in(TodoService::PRIORITIES)],
            'due_at' => ['nullable', 'date'],
            'owner_id' => ['nullable', 'uuid'],
            'client_id' => ['nullable', 'uuid'],
            'deal_id' => ['nullable', 'uuid'],
            'lead_id' => ['nullable', 'uuid'],
        ]);
    }

    private function respond(Request $request, $todo, string $message, int $status = 200): JsonResponse|RedirectResponse
    {
        return $request->expectsJson()
            ? response()->json(TodoService::dto(access(), $todo), $status)
            : back()->with('ok', $message);
    }

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $t = TodoService::create(access(), $this->input($request, false));

        return $this->respond($request, $t, t('todos.created'), 201);
    }

    public function update(Request $request, string $id): JsonResponse|RedirectResponse
    {
        $t = TodoService::update(access(), $id, $this->input($request, true));

        return $this->respond($request, $t, t('todos.saved'));
    }

    public function complete(Request $request, string $id): JsonResponse|RedirectResponse
    {
        return $this->respond($request, TodoService::setStatus(access(), $id, 'DONE'), t('todos.doneToast'));
    }

    public function reopen(Request $request, string $id): JsonResponse|RedirectResponse
    {
        return $this->respond($request, TodoService::setStatus(access(), $id, 'OPEN'), t('todos.saved'));
    }

    public function destroy(Request $request, string $id): JsonResponse|RedirectResponse
    {
        TodoService::remove(access(), $id);

        return $request->expectsJson() ? response()->json(null, 204) : back()->with('ok', t('todos.deleted'));
    }

    private function recurringInput(Request $request): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:300'],
            'description' => ['nullable', 'string', 'max:4000'],
            'kind' => ['sometimes', Rule::in(TodoService::KINDS)],
            'priority' => ['sometimes', Rule::in(TodoService::PRIORITIES)],
            'owner_id' => ['nullable', 'uuid'],
            'frequency' => ['required', Rule::in(RecurringTodoService::FREQUENCIES)],
            'day_of_month' => ['required', 'integer', 'min:1', 'max:28'],
            'month' => ['nullable', 'integer', 'min:1', 'max:12'],
            'remind_days_before' => ['sometimes', 'integer', 'min:0', 'max:30'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        if ($data['frequency'] === 'QUARTERLY' && ! empty($data['month']) && $data['month'] > 3) {
            throw ValidationException::withMessages(['month' => t('todos.errors.quarterMonth')]);
        }
        $data['is_active'] = $request->boolean('is_active', ! $request->has('_form'));

        return $data;
    }

    public function recurringStore(Request $request): JsonResponse|RedirectResponse
    {
        $r = RecurringTodoService::create(access(), $this->recurringInput($request));

        return $request->expectsJson() ? response()->json(RecurringTodoService::dto($r->load('owner')), 201) : back()->with('ok', t('todos.saved'));
    }

    public function recurringUpdate(Request $request, string $id): JsonResponse|RedirectResponse
    {
        $r = RecurringTodoService::update(access(), $id, $this->recurringInput($request));

        return $request->expectsJson() ? response()->json(RecurringTodoService::dto($r)) : back()->with('ok', t('todos.saved'));
    }

    public function recurringDestroy(Request $request, string $id): JsonResponse|RedirectResponse
    {
        RecurringTodoService::remove(access(), $id);

        return $request->expectsJson() ? response()->json(null, 204) : back()->with('ok', t('todos.ruleDeleted'));
    }

    public function taxCalendar(Request $request): JsonResponse|RedirectResponse
    {
        RecurringTodoService::addTaxCalendar(access());

        return $request->expectsJson() ? response()->json(RecurringTodoService::list(access())) : back()->with('ok', t('todos.calendarAdded'));
    }
}
