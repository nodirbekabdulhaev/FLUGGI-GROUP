{{-- Встречи: $meetings; $target (lead_id|deal_id) и $canCreate — для кнопки «Назначить встречу» на карточке --}}
@php
    $target ??= null; $ownerId ??= null; $canCreate = ($canCreate ?? false) && can('meeting.create');
    $canUpdate = can('meeting.update');
    $types = \App\Services\Crm\Crm::enum(\App\Services\Crm\Crm::MEETING_TYPES, 'crm.meetingType');
    $plannerOptions = ($canCreate && $target) || ($canUpdate && $meetings->isNotEmpty())
        ? \App\Services\Crm\MeetingSlots::participants()->map(fn ($u) => ['id' => $u->id, 'name' => $u->full_name])->values()->all() : [];
@endphp
<div class="grid gap-4">
    @if ($canCreate && $target)
        <div><button type="button" class="btn btn-primary btn-sm" x-data x-on:click="$dispatch('open-modal', 'meeting-create')"><x-icon name="calendar" /> {{ t('crm.common.newMeeting') }}</button></div>
    @endif
    @if ($meetings->isEmpty())
        <x-empty :title="t('crm.common.noMeetings')" />
    @else
        <ul class="divide-y rounded-lg border">
            @foreach ($meetings as $m)
                @php
                    $done = $m->status === 'DONE';
                    $tone = $done ? 'green' : (in_array($m->status, ['CANCELLED', 'NO_SHOW'], true) ? 'red' : ($m->starts_at->isPast() ? 'yellow' : 'blue'));
                    $editable = $canUpdate && ! $done && \App\Services\Crm\MeetingService::canEdit($m);
                    $resultable = $editable && \App\Services\Crm\MeetingService::canWriteResult($m);
                    $people = $m->participants->isNotEmpty() ? $m->participants : collect([$m->manager])->filter();
                    $local = \App\Support\Format::local($m->starts_at);
                @endphp
                <li class="flex flex-wrap items-start gap-3 px-4 py-3 text-sm">
                    <x-icon :name="$done ? 'check' : 'clock'" @class(['mt-0.5 size-4', 'text-success' => $done, 'text-accent' => ! $done]) />
                    <div class="min-w-0 flex-1">
                        <p class="font-medium">{{ dt($m->starts_at) }} · {{ t('crm.meetingType.'.$m->type) }} · {{ t('crm.common.minutes', ['n' => $m->duration_min]) }}
                            @if ($m->link)<span class="font-normal text-muted-foreground break-all">· {{ $m->link }}</span>@endif
                        </p>
                        <p class="text-xs text-muted-foreground">
                            @if ($m->lead)<a class="link" href="{{ route('crm.leads.show', $m->lead_id) }}">{{ \App\Support\Format::code('L', $m->lead->number) }} {{ $m->lead->title }}</a>
                            @elseif ($m->deal)<a class="link" href="{{ route('crm.deals.show', $m->deal_id) }}">{{ \App\Support\Format::code('D', $m->deal->number) }} {{ $m->deal->title }}</a>@endif
                            @if ($m->rop) · {{ t('crm.fields.rop') }} {{ $m->rop->full_name }}@endif
                        </p>
                        <p class="text-xs text-muted-foreground">{{ t('meetings.participants') }}:
                            @foreach ($people as $pp)<span @class(['font-medium text-foreground' => $pp->id === $m->manager_id])>{{ $pp->full_name }}</span>@if ($pp->id === $m->manager_id) ({{ t('meetings.responsible') }})@endif{{ $loop->last ? '' : ', ' }}@endforeach
                        </p>
                        @if ($m->comment)<p class="mt-1 text-muted-foreground">{{ $m->comment }}</p>@endif
                        @if ($m->result)<p class="mt-1 whitespace-pre-wrap">{{ $m->result }}</p>@endif
                    </div>
                    <x-badge :color="$tone">{{ t('crm.meetingStatus.'.$m->status) }}</x-badge>
                    @if ($editable)
                        <div class="flex w-full flex-wrap gap-2 sm:w-auto" x-data>
                            @if ($m->status !== 'CANCELLED' && $resultable)
                                <button type="button" class="btn btn-outline btn-sm" x-on:click="$dispatch('meeting-complete', @js(route('crm.meetings.complete', $m->id))); $dispatch('open-modal', 'meeting-complete')"><x-icon name="check" /> {{ t('crm.common.completeMeeting') }}</button>
                            @endif
                            <button type="button" class="btn btn-ghost btn-sm" x-on:click="$dispatch('meeting-edit', @js([
                                'url' => route('crm.meetings.update', $m->id), 'orig' => $local->format('Y-m-d\TH:i'),
                                'date' => $local->format('Y-m-d'), 'time' => $local->format('H:i'), 'duration' => $m->duration_min,
                                'type' => $m->type, 'link' => $m->link ?? '', 'comment' => $m->comment ?? '', 'status' => $m->status,
                                'exclude' => $m->id, 'selected' => $people->pluck('id')->values()->all(), 'responsible' => $m->manager_id,
                                'extra' => $people->map(fn ($pp) => ['id' => $pp->id, 'name' => $pp->full_name])->values()->all(),
                            ])); $dispatch('open-modal', 'meeting-edit')"><x-icon name="edit" /> {{ t('meetings.edit') }}</button>
                            @foreach (array_filter(['CONFIRMED' => 'meetings.confirm', 'NO_SHOW' => 'meetings.noShow', 'CANCELLED' => 'meetings.cancel'], fn ($k) => $k !== $m->status && ($k !== 'NO_SHOW' || $resultable), ARRAY_FILTER_USE_KEY) as $status => $label)
                                <form method="POST" action="{{ route('crm.meetings.update', $m->id) }}" @if ($status === 'CANCELLED') data-confirm="{{ t('meetings.cancelConfirm') }}" @endif>
                                    @csrf @method('PUT') <input type="hidden" name="status" value="{{ $status }}">
                                    <button type="submit" class="btn btn-ghost btn-sm">{{ t($label) }}</button>
                                </form>
                            @endforeach
                        </div>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</div>

@if ($canCreate && $target)
    <x-modal name="meeting-create" size="max-w-2xl" :title="t('crm.common.newMeeting')" :open="$errors->any() && old('_form') === 'meeting'">
        <form method="POST" action="{{ route('crm.meetings.store') }}" class="grid gap-4">
            @csrf <input type="hidden" name="_form" value="meeting">
            @foreach ($target as $k => $v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
            @php
                $start = old('starts_at') ?: \App\Support\Format::toLocalInput(now()->addDay()->startOfHour());
                $flagged = array_column($plannerOptions, 'id');
                $defaultSel = in_array(access()->id(), $flagged, true) ? [access()->id()] : (in_array($ownerId, $flagged, true) ? [$ownerId] : []);
            @endphp
            @include('crm.partials.meeting-planner', ['pid' => 'mc', 'options' => $plannerOptions, 'planner' => [
                'date' => substr($start, 0, 10), 'time' => substr($start, 11, 5), 'duration' => (int) old('duration_min', 60),
                'selected' => array_values((array) old('participant_ids', $defaultSel)), 'responsible' => old('manager_id'),
            ]])
            <div class="grid gap-4 sm:grid-cols-2">
                <x-select name="type" :label="t('crm.fields.type')" :options="$types" value="OFFLINE" />
                <x-field name="link" :label="t('crm.fields.link')" />
            </div>
            <x-field name="comment" type="textarea" :label="t('crm.fields.comment')" maxlength="2000" />
            <div class="flex justify-end gap-2">
                <button type="button" class="btn btn-outline" x-on:click="$dispatch('close-modal', 'meeting-create')">{{ t('common.cancel') }}</button>
                <button type="submit" class="btn btn-primary">{{ t('crm.common.newMeeting') }}</button>
            </div>
        </form>
    </x-modal>
@endif

@if ($canUpdate && $meetings->isNotEmpty())
    @once
        <x-modal name="meeting-complete" :title="t('meetings.completeTitle')">
            <form method="POST" x-data="{ url: '' }" x-on:meeting-complete.window="url = $event.detail" :action="url" class="grid gap-4">
                @csrf
                <div>
                    <label class="label" for="m-result">{{ t('crm.fields.result') }} <span class="text-danger">*</span></label>
                    <textarea id="m-result" name="result" rows="4" class="input h-auto py-2" required maxlength="4000"></textarea>
                    <p class="hint">{{ t('meetings.resultHint') }}</p>
                </div>
                <div class="flex justify-end gap-2">
                    <button type="button" class="btn btn-outline" x-on:click="$dispatch('close-modal', 'meeting-complete')">{{ t('common.cancel') }}</button>
                    <button type="submit" class="btn btn-primary">{{ t('crm.common.completeMeeting') }}</button>
                </div>
            </form>
        </x-modal>
        <x-modal name="meeting-edit" size="max-w-2xl" :title="t('meetings.editTitle')">
            <form method="POST" class="grid gap-4" x-data="{ m: { url: '', orig: '', type: 'OFFLINE', link: '', comment: '', status: 'SCHEDULED' } }"
                  x-on:meeting-edit.window="m = $event.detail" :action="m.url">
                @csrf @method('PUT')
                <p class="text-sm text-muted-foreground">{{ t('meetings.rescheduleHint') }}</p>
                {{-- Новое время при «Запланирована» / «Подтверждена» отмечает встречу перенесённой --}}
                @include('crm.partials.meeting-planner', ['pid' => 'me', 'options' => $plannerOptions, 'listen' => 'meeting-edit',
                    'init' => "\$watch('startsAt', (v) => { if (v && m.orig && v !== m.orig && ['SCHEDULED', 'CONFIRMED'].includes(m.status)) m.status = 'RESCHEDULED' })",
                    'planner' => ['date' => '', 'time' => '', 'duration' => 60, 'selected' => [], 'responsible' => null]])
                <div class="grid gap-4 sm:grid-cols-2">
                    <div><label class="label" for="me-type">{{ t('crm.fields.type') }}</label>
                        <select id="me-type" name="type" class="input" x-model="m.type">@foreach ($types as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select></div>
                    <div><label class="label" for="me-status">{{ t('crm.fields.status') }}</label>
                        <select id="me-status" name="status" class="input" x-model="m.status">@foreach (\App\Services\Crm\Crm::MEETING_EDIT_STATUSES as $s)<option value="{{ $s }}">{{ t('crm.meetingStatus.'.$s) }}</option>@endforeach</select></div>
                    <div class="sm:col-span-2"><label class="label" for="me-link">{{ t('crm.fields.link') }}</label><input id="me-link" name="link" class="input" maxlength="500" x-model="m.link"></div>
                </div>
                <div><label class="label" for="me-comment">{{ t('crm.fields.comment') }}</label><textarea id="me-comment" name="comment" rows="3" class="input h-auto py-2" maxlength="2000" x-model="m.comment"></textarea></div>
                <div class="flex justify-end gap-2">
                    <button type="button" class="btn btn-outline" x-on:click="$dispatch('close-modal', 'meeting-edit')">{{ t('common.cancel') }}</button>
                    <button type="submit" class="btn btn-primary">{{ t('common.save') }}</button>
                </div>
            </form>
        </x-modal>
    @endonce
@endif
