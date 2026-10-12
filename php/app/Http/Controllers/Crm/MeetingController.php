<?php

namespace App\Http\Controllers\Crm;

use App\Services\Crm\Crm;
use App\Services\Crm\CrmAccess;
use App\Services\Crm\MeetingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class MeetingController extends CrmController
{
    public function __construct(private readonly MeetingService $meetings) {}

    /** Предстоящие (с сегодняшнего дня по Ташкенту, кроме проведённых и отменённых), проведённые, все. */
    public function index(Request $request): View
    {
        $f = $this->check($request, [
            'view' => ['nullable', Rule::in(['upcoming', 'done', 'all'])],
            'status' => ['nullable', Rule::in(Crm::MEETING_STATUSES)],
            'manager_id' => ['nullable', 'uuid'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $view = $f['view'] ?? 'upcoming';
        $q = CrmAccess::meetings()->with(['lead', 'deal', 'client', 'manager', 'rop'])
            ->when($view === 'upcoming', fn ($q) => $q->where('starts_at', '>=', MeetingService::todayStartUtc())->whereNotIn('status', ['DONE', 'CANCELLED']))
            ->when($view === 'done', fn ($q) => $q->where('status', 'DONE'))
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($f['manager_id'] ?? null, fn ($q, $v) => $q->where('manager_id', $v))
            ->when($f['date_from'] ?? null, fn ($q, $v) => $q->where('starts_at', '>=', Crm::dayStart($v)))
            ->when($f['date_to'] ?? null, fn ($q, $v) => $q->where('starts_at', '<', Crm::dayStart($v)->addDay()))
            ->orderBy('starts_at', $view === 'done' ? 'desc' : 'asc');

        return view('crm.meetings.index', ['meetings' => $q->paginate(25)->withQueryString(), 'view' => $view]);
    }

    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $data = $this->check($request, [
            'lead_id' => ['nullable', 'uuid'],
            'deal_id' => ['nullable', 'uuid'],
            'starts_at' => ['required', 'date'],
            'duration_min' => ['nullable', 'integer', 'between:5,600'],
            'type' => ['required', Rule::in(Crm::MEETING_TYPES)],
            'link' => ['nullable', 'string', 'max:500'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);
        $data['starts_at'] = $this->localDateTime($data['starts_at']);
        $this->meetings->create($data);

        return $this->done($request, t('crm.common.meetingCreated'));
    }

    public function update(Request $request, string $id): RedirectResponse|JsonResponse
    {
        $meeting = MeetingService::find($id, 'meeting.update');
        $data = $this->check($request, [
            'starts_at' => ['nullable', 'date'],
            'duration_min' => ['nullable', 'integer', 'between:5,600'],
            'type' => ['nullable', Rule::in(Crm::MEETING_TYPES)],
            'link' => ['nullable', 'string', 'max:500'],
            'comment' => ['nullable', 'string', 'max:2000'],
            'status' => ['nullable', Rule::in(Crm::MEETING_EDIT_STATUSES)],
        ]);
        // Быстрые действия (подтвердить, отменить, не состоялась) присылают только статус
        $data = array_intersect_key($data, $request->all());
        foreach (['starts_at', 'duration_min', 'type'] as $k) {
            if (array_key_exists($k, $data) && $data[$k] === null) {
                unset($data[$k]);
            }
        }
        if (isset($data['starts_at'])) {
            $data['starts_at'] = $this->localDateTime($data['starts_at']);
        }
        $this->meetings->update($meeting, $data);

        return $this->done($request, t('crm.common.meetingUpdated'));
    }

    public function complete(Request $request, string $id): RedirectResponse|JsonResponse
    {
        $meeting = MeetingService::find($id, 'meeting.update');
        $result = $this->check($request, ['result' => ['required', 'string', 'max:4000']], ['result.required' => t('meetings.errors.resultRequired')])['result'];
        $this->meetings->complete($meeting, $result);

        return $this->done($request, t('crm.common.meetingDone'));
    }
}
