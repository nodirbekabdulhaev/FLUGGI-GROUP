@extends('layouts.app')
@section('title', $idea->code().' · '.$idea->title)
@section('content')
    @php
        use App\Services\Smm\SmmIdeaService;
        use App\Support\Format;
        use Illuminate\Support\Str;
        $value = function (string $field, $v) {
            if ($v === null || $v === '') {
                return '—';
            }
            return match ($field) {
                'status' => t('smm.status.'.$v),
                'igStatus' => t('smm.ig.status.'.$v),
                'planned_publish_date' => d((string) $v),
                default => Str::limit((string) $v, 300),
            };
        };
        $work = ['APPROVED', 'SHOOTING', 'EDITING', 'SCHEDULED', 'PUBLISHED'];
        $moveTargets = $idea->status === 'PUBLISHED' ? ['APPROVED', 'SHOOTING', 'EDITING', 'SCHEDULED']
            : (in_array($idea->status, $work, true) ? array_values(array_diff($work, [$idea->status])) : []);
        // Видеограф (не ответственный и без права утверждать) переводит только «Съёмка → Монтаж»
        if ($idea->can_move && ! $idea->can_edit && $idea->videographer_id === access()->id()) {
            $moveTargets = $idea->status === 'SHOOTING' ? ['EDITING'] : [];
        }
    @endphp
    <x-page-header :title="$idea->title" :subtitle="$idea->code().' · '.t('smm.authorOn', ['name' => $idea->author?->full_name, 'date' => dt($idea->created_at)])" back="/smm">
        <button type="button" class="btn btn-outline btn-sm" x-data x-on:click="$dispatch('open-modal', 'record-task')"><x-icon name="list" /> {{ t('todos.assignTask') }}</button>
        <x-badge :color="SmmIdeaService::COLORS[$idea->status]">{{ t('smm.status.'.$idea->status) }}</x-badge>
    </x-page-header>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="grid content-start gap-6 lg:col-span-2">
            {{-- Сценарий --}}
            <x-card :title="t('smm.fields.scenario')">
                @if ($idea->can_edit)
                    <form method="POST" action="/smm/{{ $idea->id }}" class="grid gap-4">
                        @csrf @method('PATCH')
                        <x-field name="title" :label="t('smm.fields.title')" :value="$idea->title" maxlength="300" required />
                        <x-field name="scenario" type="textarea" rows="12" :label="t('smm.fields.scenario')" :value="$idea->scenario" maxlength="20000" :hint="t('smm.scenarioHint')" />
                        <x-field name="notes" type="textarea" rows="3" :label="t('smm.fields.notes')" :value="$idea->notes" maxlength="5000" />
                        <div class="grid gap-4 sm:grid-cols-2">
                            <x-select name="assignee_id" :label="t('smm.fields.assignee')" :options="$assignees->pluck('full_name', 'id')->all()" :value="$idea->assignee_id" />
                            <x-select name="videographer_id" :label="t('smm.fields.videographer')" :options="$videographers->pluck('full_name', 'id')->all()" :value="$idea->videographer_id"
                                      :placeholder="t('smm.noVideographer')" :hint="t('smm.videographerHint')" />
                            <x-field name="planned_publish_date" type="date" :label="t('smm.fields.plannedDate')" :value="$idea->planned_publish_date?->format('Y-m-d')" :required="$idea->status === 'SCHEDULED'" />
                            @if ($idea->status === 'PUBLISHED')
                                <x-field name="published_at" type="datetime-local" :label="t('smm.fields.publishedAt')" :value="Format::toLocalInput($idea->published_at)" />
                            @endif
                            <x-field name="publish_url" type="url" :label="t('smm.fields.publishUrl')" :value="$idea->publish_url" maxlength="500" placeholder="https://" />
                        </div>
                        <div class="flex justify-end"><button class="btn btn-primary">{{ t('common.save') }}</button></div>
                    </form>
                @else
                    @if ($idea->scenario)
                        <p class="whitespace-pre-wrap text-sm">{{ $idea->scenario }}</p>
                    @else
                        <p class="text-sm text-muted-foreground">{{ t('smm.noScenario') }}</p>
                    @endif
                    @if ($idea->notes)<p class="mt-4 whitespace-pre-wrap text-sm text-muted-foreground">{{ $idea->notes }}</p>@endif
                @endif
            </x-card>

            {{-- Автопубликация в Instagram --}}
            @include('smm.partials.instagram', ['idea' => $idea, 'p' => $publication, 'canPublish' => $canPublish])

            {{-- Задачи по идее --}}
            <x-card :title="t('todos.tasksBlock')" id="tasks">
                @include('todos.partials.record-tasks', ['column' => 'smm_idea_id', 'recordId' => $idea->id])
            </x-card>

            {{-- Комментарии --}}
            <x-card :title="t('smm.comments')">
                @if ($idea->comments->isEmpty())
                    <p class="text-sm text-muted-foreground">{{ t('smm.noComments') }}</p>
                @else
                    <ul class="grid gap-3">
                        @foreach ($idea->comments as $c)
                            <li class="rounded-md bg-muted/50 p-3 text-sm">
                                <p class="text-xs text-muted-foreground">{{ $c->author?->full_name }} · {{ dt($c->created_at) }}</p>
                                <p class="mt-1 whitespace-pre-wrap">{{ $c->body }}</p>
                            </li>
                        @endforeach
                    </ul>
                @endif
                <form method="POST" action="/smm/{{ $idea->id }}/comments" class="mt-4 grid gap-2">
                    @csrf
                    <x-field name="body" type="textarea" rows="2" :label="t('smm.comment')" maxlength="5000" required />
                    <div class="flex justify-end"><button class="btn btn-outline btn-sm">{{ t('smm.addComment') }}</button></div>
                </form>
            </x-card>

            {{-- История --}}
            <x-card :title="t('smm.historyTitle')">
                @if ($idea->history->isEmpty())
                    <p class="text-sm text-muted-foreground">{{ t('smm.noHistory') }}</p>
                @else
                    <ul class="divide-y">
                        @foreach ($idea->history as $h)
                            <li class="grid gap-1 py-2 text-sm">
                                <p><span class="font-medium">{{ t('smm.history.'.$h->action) }}</span>
                                    <span class="text-xs text-muted-foreground">· {{ $h->user?->full_name ?? t('smm.system') }} · {{ dt($h->created_at) }}</span></p>
                                @foreach ($h->changes ?? [] as $field => $c)
                                    <p class="text-xs text-muted-foreground"><span class="text-foreground">{{ t('smm.historyFields.'.$field) }}:</span>
                                        {{ $value($field, $c['old'] ?? null) }} → {{ $value($field, $c['new'] ?? null) }}</p>
                                @endforeach
                                @if ($h->comment)<p class="whitespace-pre-wrap text-xs text-muted-foreground">«{{ Str::limit($h->comment, 500) }}»</p>@endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-card>
        </div>

        <div class="grid content-start gap-6">
            <x-card :title="t('smm.details')">
                <dl class="grid gap-2 text-sm">
                    <div class="flex justify-between gap-3"><dt class="text-muted-foreground">{{ t('smm.fields.assignee') }}</dt><dd class="text-right">{{ $idea->assignee?->full_name }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-muted-foreground">{{ t('smm.fields.videographer') }}</dt><dd class="text-right">{{ $idea->videographer?->full_name ?? '—' }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-muted-foreground">{{ t('smm.fields.author') }}</dt><dd class="text-right">{{ $idea->author?->full_name }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-muted-foreground">{{ t('smm.fields.plannedDate') }}</dt><dd>{{ d($idea->planned_publish_date) }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-muted-foreground">{{ t('smm.fields.publishedAt') }}</dt><dd>{{ dt($idea->published_at) }}</dd></div>
                    @if ($idea->publish_url)
                        <div class="flex justify-between gap-3"><dt class="text-muted-foreground">{{ t('smm.fields.publishUrl') }}</dt>
                            <dd class="min-w-0 truncate"><a href="{{ $idea->publish_url }}" class="link" target="_blank" rel="noopener noreferrer">{{ $idea->publish_url }}</a></dd></div>
                    @endif
                </dl>
            </x-card>

            {{-- Утверждение: проект-менеджер и CEO --}}
            <x-card :title="t('smm.approval')">
                <ul class="grid gap-2 text-sm">
                    <li class="flex items-center justify-between gap-3">
                        <span>{{ t('smm.pmApproval') }}</span>
                        @if ($idea->pm_approved_by_id)
                            <span class="text-right text-xs"><x-badge color="green">✓</x-badge> {{ $idea->pmApprovedBy?->full_name }} · {{ dt($idea->pm_approved_at) }}</span>
                        @else
                            <span class="text-xs text-muted-foreground">{{ $pmRequired ? t('smm.waiting') : t('smm.pmNotRequired') }}</span>
                        @endif
                    </li>
                    <li class="flex items-center justify-between gap-3">
                        <span>{{ t('smm.ceoApproval') }}</span>
                        @if ($idea->ceo_approved_by_id)
                            <span class="text-right text-xs"><x-badge color="green">✓</x-badge> {{ $idea->ceoApprovedBy?->full_name }} · {{ dt($idea->ceo_approved_at) }}</span>
                        @else
                            <span class="text-xs text-muted-foreground">{{ t('smm.waiting') }}</span>
                        @endif
                    </li>
                </ul>
                @if ($idea->status === 'REJECTED')
                    <div class="mt-3 rounded-md bg-danger-soft p-3 text-sm">
                        <p class="font-medium text-danger">{{ t('smm.rejectedBy', ['name' => $idea->rejectedBy?->full_name, 'date' => dt($idea->rejected_at)]) }}</p>
                        <p class="mt-1 whitespace-pre-wrap">{{ $idea->reject_reason }}</p>
                    </div>
                @endif
                <div class="mt-4 flex flex-wrap gap-2">
                    @if ($idea->can_approve)
                        <form method="POST" action="/smm/{{ $idea->id }}/approve">@csrf<button class="btn btn-primary btn-sm"><x-icon name="check" /> {{ t('smm.approve') }}</button></form>
                    @endif
                    @if ($idea->can_reject)
                        <button type="button" class="btn btn-danger btn-sm" x-data x-on:click="$dispatch('open-modal', 'smm-reject')">{{ t('smm.reject') }}</button>
                    @endif
                    @if ($idea->can_resubmit)
                        <form method="POST" action="/smm/{{ $idea->id }}/move">@csrf<input type="hidden" name="status" value="IDEA">
                            <button class="btn btn-outline btn-sm"><x-icon name="refresh" /> {{ t('smm.resubmit') }}</button></form>
                    @endif
                </div>
            </x-card>

            {{-- Этап --}}
            @if ($idea->can_move && $moveTargets)
                <x-card :title="t('smm.moveTo')">
                    <form method="POST" action="/smm/{{ $idea->id }}/move" class="grid gap-3"
                          x-data="{ to: @js(old('status', $moveTargets[0])) }">
                        @csrf
                        <select name="status" class="input" x-model="to" aria-label="{{ t('smm.moveTo') }}">
                            @foreach ($moveTargets as $to)<option value="{{ $to }}">{{ t('smm.status.'.$to) }}</option>@endforeach
                        </select>
                        @if (! $idea->videographer_id)
                            <div x-show="to === 'SHOOTING'" x-cloak>
                                <label class="label" for="move-videographer">{{ t('smm.fields.videographer') }}<span class="text-danger"> *</span></label>
                                <select id="move-videographer" name="videographer_id" class="input" x-bind:disabled="to !== 'SHOOTING'">
                                    <option value="">{{ t('crm.common.select') }}</option>
                                    @foreach ($videographers as $v)<option value="{{ $v->id }}" @selected(old('videographer_id') === $v->id)>{{ $v->full_name }}</option>@endforeach
                                </select>
                                @error('videographer_id')<p class="error">{{ $message }}</p>@enderror
                            </div>
                        @endif
                        <div x-show="to === 'SCHEDULED'" x-cloak>
                            <x-field name="planned_publish_date" type="date" id="move-planned" :label="t('smm.fields.plannedDate')" :value="$idea->planned_publish_date?->format('Y-m-d')" x-bind:disabled="to !== 'SCHEDULED'" />
                        </div>
                        <div x-show="to === 'PUBLISHED'" x-cloak class="grid gap-3">
                            <x-field name="published_at" type="datetime-local" id="move-published" :label="t('smm.fields.publishedAt')" :value="Format::toLocalInput(now())" x-bind:disabled="to !== 'PUBLISHED'" />
                            <x-field name="publish_url" type="url" id="move-url" :label="t('smm.fields.publishUrl')" :value="$idea->publish_url" placeholder="https://" x-bind:disabled="to !== 'PUBLISHED'" />
                        </div>
                        <button class="btn btn-primary">{{ t('smm.moveSubmit') }}</button>
                    </form>
                    @if ($idea->status === 'PUBLISHED')<p class="mt-2 text-xs text-muted-foreground">{{ t('smm.revertHint') }}</p>@endif
                </x-card>
            @elseif (in_array($idea->status, ['IDEA', 'REJECTED'], true))
                <p class="text-xs text-muted-foreground">{{ t('smm.approvalHint') }}</p>
            @endif

            @if ($idea->can_delete)
                <form method="POST" action="/smm/{{ $idea->id }}" onsubmit="return confirm(@js(t('smm.deleteConfirm')))">
                    @csrf @method('DELETE')
                    <button class="btn btn-ghost btn-sm text-danger"><x-icon name="trash" /> {{ t('smm.delete') }}</button>
                </form>
            @endif
        </div>
    </div>

    @if ($idea->can_reject)
        <x-modal name="smm-reject" :title="t('smm.reject')" :open="$errors->has('reason')">
            <form method="POST" action="/smm/{{ $idea->id }}/reject" class="grid gap-4">
                @csrf
                <x-field name="reason" type="textarea" rows="3" :label="t('smm.fields.rejectReason')" maxlength="2000" required />
                <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                    <button type="button" class="btn btn-outline" x-on:click="$dispatch('close-modal', 'smm-reject')">{{ t('common.cancel') }}</button>
                    <button class="btn btn-danger">{{ t('smm.reject') }}</button>
                </div>
            </form>
        </x-modal>
    @endif
    @include('todos.partials.record-task-modal', ['column' => 'smm_idea_id', 'recordId' => $idea->id, 'back' => '/smm/'.$idea->id.'#tasks'])
@endsection
