<?php

namespace App\Http\Controllers\Smm;

use App\Http\Controllers\Controller;
use App\Services\Smm\SmmAccess;
use App\Services\Smm\SmmIdeaService;
use App\Support\Format;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Раздел «SMM»: Kanban и список идей роликов, карточка идеи, утверждение, этапы, комментарии. */
class IdeasController extends Controller
{
    /** Сколько опубликованных и отклонённых показывать на доске без фильтра месяца. */
    private const BOARD_TAIL = 20;

    public function index(Request $request): View
    {
        SmmAccess::authorize();
        $f = $request->validate([
            'view' => ['nullable', Rule::in(['kanban', 'list'])],
            'status' => ['nullable', Rule::in(SmmIdeaService::STATUSES)],
            'assignee_id' => ['nullable', 'uuid'],
            'videographer_id' => ['nullable', 'uuid'],
            'month' => ['nullable', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $view = $f['view'] ?? 'kanban';
        $items = SmmIdeaService::list($view === 'kanban' ? array_diff_key($f, ['status' => 1]) : $f);
        $hidden = 0;
        if ($view === 'kanban' && empty($f['month'])) {
            // Без фильтра месяца на доске — только последние опубликованные и отклонённые
            foreach (['PUBLISHED' => 'published_at', 'REJECTED' => 'rejected_at'] as $status => $field) {
                $col = $items->where('status', $status)->sortByDesc($field);
                $hidden += max(0, $col->count() - self::BOARD_TAIL);
                $drop = $col->slice(self::BOARD_TAIL)->pluck('id')->flip();
                $items = $items->reject(fn ($i) => $drop->has($i->id))->values();
            }
        }

        return view('smm.index', [
            'view' => $view,
            'f' => $f,
            'items' => $items,
            'hidden' => $hidden,
            'assignees' => SmmAccess::assignees(),
            'videographers' => SmmAccess::videographers(),
            'canCreate' => access()->can('smm.create'),
            'defaultAssignee' => SmmAccess::isSmmExecutor(access()->user) ? access()->id() : null,
        ]);
    }

    public function show(string $id): View
    {
        $idea = SmmIdeaService::get($id);

        return view('smm.show', [
            'idea' => $idea,
            'assignees' => SmmAccess::assignees($idea->assignee_id),
            'videographers' => SmmAccess::videographers($idea->videographer_id),
            'pmRequired' => SmmAccess::projectManagerIds() !== [],
        ]);
    }

    private function fields(Request $request, bool $create): array
    {
        $data = $request->validate([
            'title' => [$create ? 'required' : 'sometimes', 'string', 'max:300'],
            'scenario' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'assignee_id' => ['sometimes', 'nullable', 'uuid'],
            'videographer_id' => ['sometimes', 'nullable', 'uuid'],
            'planned_publish_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'published_at' => ['sometimes', 'nullable', 'date_format:Y-m-d\TH:i'],
            'publish_url' => ['sometimes', 'nullable', 'url', 'max:500'],
        ], [], [
            'title' => t('smm.fields.title'), 'scenario' => t('smm.fields.scenario'), 'assignee_id' => t('smm.fields.assignee'), 'videographer_id' => t('smm.fields.videographer'),
            'planned_publish_date' => t('smm.fields.plannedDate'), 'published_at' => t('smm.fields.publishedAt'), 'publish_url' => t('smm.fields.publishUrl'),
        ]);
        if (array_key_exists('published_at', $data)) {
            $data['published_at'] = Format::fromLocalInput($data['published_at']);
        }

        return $data;
    }

    public function store(Request $request): RedirectResponse
    {
        $idea = SmmIdeaService::create($this->fields($request, true));

        return redirect('/smm/'.$idea->id)->with('ok', t('smm.created'));
    }

    public function update(Request $request, string $id): RedirectResponse
    {
        SmmIdeaService::update($id, $this->fields($request, false));

        return redirect('/smm/'.$id)->with('ok', t('smm.saved'));
    }

    /** Перемещение (Kanban — JSON, карточка и телефон — форма). */
    public function move(Request $request, string $id): RedirectResponse|JsonResponse
    {
        SmmAccess::authorize();
        $data = $request->validate([
            'status' => ['required', Rule::in(SmmIdeaService::STATUSES)],
            'before_id' => ['nullable', 'uuid'],
            'planned_publish_date' => ['nullable', 'date_format:Y-m-d'],
            'published_at' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'publish_url' => ['nullable', 'url', 'max:500'],
            'videographer_id' => ['nullable', 'uuid'],
        ], [], ['videographer_id' => t('smm.fields.videographer'), 'planned_publish_date' => t('smm.fields.plannedDate'), 'published_at' => t('smm.fields.publishedAt'), 'publish_url' => t('smm.fields.publishUrl')]);
        $idea = SmmIdeaService::move($id, $data['status'], $data['before_id'] ?? null, [
            'planned_publish_date' => $data['planned_publish_date'] ?? null,
            'published_at' => Format::fromLocalInput($data['published_at'] ?? null),
            'publish_url' => $data['publish_url'] ?? null,
            'videographer_id' => $data['videographer_id'] ?? null,
        ]);
        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'status' => $idea->status, 'message' => t('smm.moved')]);
        }

        return back()->with('ok', t('smm.moved'));
    }

    public function approve(Request $request, string $id): RedirectResponse|JsonResponse
    {
        $idea = SmmIdeaService::approve($id);
        $message = t($idea->status === 'APPROVED' ? 'smm.approvedFull' : 'smm.approvedPartial');
        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'status' => $idea->status, 'message' => $message]);
        }

        return back()->with('ok', $message);
    }

    public function reject(Request $request, string $id): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:2000']], [], ['reason' => t('smm.fields.rejectReason')]);
        SmmIdeaService::reject($id, trim($data['reason']));

        return back()->with('ok', t('smm.rejected'));
    }

    public function comment(Request $request, string $id): RedirectResponse
    {
        SmmAccess::authorize();
        $data = $request->validate(['body' => ['required', 'string', 'max:5000']], [], ['body' => t('smm.comment')]);
        SmmIdeaService::comment($id, trim($data['body']));

        return back()->with('ok', t('smm.commentAdded'));
    }

    public function destroy(string $id): RedirectResponse
    {
        SmmIdeaService::remove($id);

        return redirect('/smm')->with('ok', t('smm.deleted'));
    }
}
