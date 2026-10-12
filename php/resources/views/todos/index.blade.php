@extends('layouts.app')
@section('title', t('todos.pageTitle'))
@php
    use App\Services\Todos\TodoService;
    use App\Services\Todos\RecurringTodoService;
    $dots = ['URGENT' => 'bg-danger', 'HIGH' => 'bg-warning', 'MEDIUM' => 'bg-success', 'LOW' => 'bg-muted-foreground/40'];
    $tabs = [];
    foreach ($views as $v) {
        $tabs[route('todos', ['view' => $v])] = $v === 'all' && ! TodoService::seesAll($access) ? t('todos.views.team') : t('todos.views.'.$v);
    }
    $months = collect(range(1, 12))->mapWithKeys(fn ($m) => [$m => t('todos.months.'.$m)])->all();
    $quarterMonths = collect(range(1, 3))->mapWithKeys(fn ($m) => [$m => t('todos.quarterMonth', ['n' => $m])])->all();
    $schedule = function (array $r) use ($months) {
        return match ($r['frequency']) {
            'MONTHLY' => t('todos.scheduleMonthly', ['day' => $r['dayOfMonth']]),
            'QUARTERLY' => t('todos.scheduleQuarterly', ['day' => $r['dayOfMonth'], 'month' => $r['month'] ?? 1]),
            default => t('todos.scheduleYearly', ['day' => $r['dayOfMonth'], 'month' => $months[$r['month'] ?? 1]]),
        };
    };
    $formJs = fn ($t) => ['action' => route('todos.update', $t->id), 'method' => 'PATCH', 'title' => $t->title, 'description' => (string) $t->description,
        'kind' => $t->kind, 'priority' => $t->priority, 'due_at' => \App\Support\Format::toLocalInput($t->due_at), 'owner_id' => $t->owner_id, 'client_id' => (string) $t->client_id];
@endphp
@section('content')
    <x-page-header :title="t('todos.pageTitle')" :subtitle="t('todos.pageSubtitle')" />
    <x-tabs :items="$tabs" :active="route('todos', ['view' => $view])" />

    @if ($view !== 'recurring')
        <div x-data="{ f: {} }" @todo-edit.window="f = $event.detail; $dispatch('open-modal', 'todo-form')">
            <div class="mb-4 flex flex-wrap gap-2">
                @foreach (['OPEN' => 'statusOpen', 'DONE' => 'statusDone'] as $s => $label)
                    <a href="{{ route('todos', ['view' => $view, 'status' => $s]) }}" @class(['btn btn-sm', 'btn-primary' => $status === $s, 'btn-outline' => $status !== $s])>{{ t('todos.'.$label) }}</a>
                @endforeach
                <button type="button" class="btn btn-primary btn-sm ml-auto" @click="$dispatch('todo-edit', @js(['action' => route('todos.store'), 'method' => 'POST', 'title' => '', 'description' => '', 'kind' => 'TASK', 'priority' => 'MEDIUM', 'due_at' => '', 'owner_id' => $access->id(), 'client_id' => '']))">
                    <x-icon name="plus" /> {{ t('todos.add') }}
                </button>
            </div>

            @if ($items->isEmpty())
                <x-card><x-empty icon="list" :title="t($status === 'OPEN' ? 'todos.emptyOpen' : 'todos.emptyDone')">{{ t('todos.emptyText') }}</x-empty></x-card>
            @else
                <x-table>
                    <thead><tr>
                        <th>{{ t('todos.title') }}</th><th>{{ t('todos.kind') }}</th><th>{{ t('todos.due') }}</th><th>{{ t('todos.client') }}</th>
                        <th>{{ $view === 'mine' ? t('todos.from') : t('todos.owner') }}</th><th class="w-28"></th>
                    </tr></thead>
                    <tbody>
                        @foreach ($items as $x)
                            <tr>
                                <td>
                                    <span class="flex items-start gap-2">
                                        <span class="mt-1.5 size-2 shrink-0 rounded-full {{ $dots[$x->priority] }}"></span>
                                        <span @class(['font-medium', 'text-muted-foreground line-through' => $x->status === 'DONE'])>{{ $x->title }}</span>
                                        @if ($x->recurring_id)<x-badge>{{ t('todos.recurring') }}</x-badge>@endif
                                    </span>
                                </td>
                                <td class="whitespace-nowrap text-muted-foreground">{{ t('todos.kinds.'.$x->kind) }}</td>
                                <td @class(['whitespace-nowrap', 'font-medium text-danger' => TodoService::overdue($x)])>{{ TodoService::dueLabel($x->due_at) }}</td>
                                <td>@if ($x->client)<a href="/clients/{{ $x->client->id }}" class="link">{{ $x->client->name }}</a>@else — @endif</td>
                                <td class="whitespace-nowrap text-muted-foreground">
                                    {{ $view === 'mine' ? ($x->creator_id === $x->owner_id ? '—' : $x->creator?->full_name) : $x->owner?->full_name }}
                                </td>
                                <td class="whitespace-nowrap text-right">
                                    @if (TodoService::canEdit($access, $x))
                                        <div class="flex justify-end gap-1">
                                            @if ($x->status === 'OPEN')
                                                <form method="POST" action="{{ route('todos.complete', $x->id) }}">@csrf
                                                    <button class="btn btn-ghost btn-sm" aria-label="{{ t('todos.complete', ['title' => $x->title]) }}" title="{{ t('todos.markDone') }}"><x-icon name="check" /></button></form>
                                                <button type="button" class="btn btn-ghost btn-sm" aria-label="{{ t('todos.edit') }}" @click="$dispatch('todo-edit', @js($formJs($x)))"><x-icon name="edit" /></button>
                                            @else
                                                <form method="POST" action="{{ route('todos.reopen', $x->id) }}">@csrf
                                                    <button class="btn btn-ghost btn-sm" aria-label="{{ t('todos.reopen') }}" title="{{ t('todos.reopen') }}"><x-icon name="refresh" /></button></form>
                                            @endif
                                            @if ($x->creator_id === $access->id() || TodoService::seesAll($access))
                                                <form method="POST" action="{{ route('todos.destroy', $x->id) }}" data-confirm="{{ t('todos.deleteConfirm', ['title' => $x->title]) }}">@csrf @method('DELETE')
                                                    <button class="btn btn-ghost btn-sm" aria-label="{{ t('todos.delete') }}"><x-icon name="trash" /></button></form>
                                            @endif
                                        </div>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <x-slot:footer>{{ $items->links('vendor.pagination.fluggi') }}</x-slot:footer>
                </x-table>
            @endif

            <x-modal name="todo-form" :title="t('todos.new')">
                <form method="POST" :action="f.action" class="space-y-4">
                    @csrf
                    <input type="hidden" name="_method" :value="f.method">
                    <div><label class="label" for="td-title">{{ t('todos.title') }} <span class="text-danger">*</span></label>
                        <input id="td-title" name="title" class="input" x-model="f.title" required maxlength="300"></div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div><label class="label" for="td-kind">{{ t('todos.kind') }}</label>
                            <select id="td-kind" name="kind" class="input" x-model="f.kind">@foreach (TodoService::KINDS as $k)<option value="{{ $k }}">{{ t('todos.kinds.'.$k) }}</option>@endforeach</select></div>
                        <div><label class="label" for="td-priority">{{ t('todos.priority') }}</label>
                            <select id="td-priority" name="priority" class="input" x-model="f.priority">@foreach (TodoService::PRIORITIES as $p)<option value="{{ $p }}">{{ t('crm.priority.'.$p) }}</option>@endforeach</select></div>
                        <div><label class="label" for="td-due">{{ t('todos.due') }}</label>
                            <input id="td-due" type="datetime-local" name="due_at" class="input" x-model="f.due_at"></div>
                        @if (count($owners) > 1)
                            <div><label class="label" for="td-owner">{{ t('todos.owner') }}</label>
                                <select id="td-owner" name="owner_id" class="input" x-model="f.owner_id">@foreach ($owners as $id => $name)<option value="{{ $id }}">{{ $id === $access->id() ? $name.' ('.t('todos.me').')' : $name }}</option>@endforeach</select></div>
                        @endif
                        @if ($clients)
                            <div><label class="label" for="td-client">{{ t('todos.client') }}</label>
                                <select id="td-client" name="client_id" class="input" x-model="f.client_id"><option value="">{{ t('todos.noClient') }}</option>@foreach ($clients as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select></div>
                        @endif
                    </div>
                    <div><label class="label" for="td-desc">{{ t('todos.description') }}</label>
                        <textarea id="td-desc" name="description" rows="3" class="input" x-model="f.description" maxlength="4000"></textarea></div>
                    <div class="flex justify-end gap-2">
                        <button type="button" class="btn btn-outline" @click="$dispatch('close-modal', 'todo-form')">{{ t('todos.cancel') }}</button>
                        <button class="btn btn-primary">{{ t('todos.save') }}</button>
                    </div>
                </form>
            </x-modal>
        </div>
    @else
        @php
            $ruleJs = fn (?array $r) => $r
                ? ['action' => route('recurring.update', $r['id']), 'method' => 'PUT', 'title' => $r['title'], 'description' => (string) $r['description'], 'kind' => $r['kind'],
                    'priority' => $r['priority'], 'frequency' => $r['frequency'], 'day_of_month' => $r['dayOfMonth'], 'month' => $r['month'] ?? 1,
                    'remind_days_before' => $r['remindDaysBefore'], 'is_active' => $r['isActive']]
                : ['action' => route('recurring.store'), 'method' => 'POST', 'title' => '', 'description' => '', 'kind' => 'REPORT', 'priority' => 'HIGH',
                    'frequency' => 'MONTHLY', 'day_of_month' => 4, 'month' => 1, 'remind_days_before' => 3, 'is_active' => true];
        @endphp
        <div x-data="{ r: {} }" @rule-edit.window="r = $event.detail; $dispatch('open-modal', 'rule-form')">
            <x-card :title="t('todos.recurringTitle')" :subtitle="t('todos.recurringText')" :padding="false">
                <x-slot:actions>
                    @if ($access->roleCode === 'CEO')
                        <form method="POST" action="{{ route('recurring.taxCalendar') }}">@csrf<button class="btn btn-outline btn-sm"><x-icon name="calendar" /> {{ t('todos.taxCalendar') }}</button></form>
                    @endif
                    <button type="button" class="btn btn-primary btn-sm" @click="$dispatch('rule-edit', @js($ruleJs(null)))"><x-icon name="plus" /> {{ t('todos.ruleNew') }}</button>
                </x-slot:actions>
                @if (! $rules)
                    <x-empty icon="calendar" :title="t('todos.noRules')">{{ t('todos.noRulesText') }}</x-empty>
                @else
                    <div class="overflow-x-auto">
                        <table class="table">
                            <thead><tr><th>{{ t('todos.title') }}</th><th>{{ t('todos.schedule') }}</th><th>{{ t('todos.nextDue') }}</th><th>{{ t('todos.owner') }}</th><th class="w-24"></th></tr></thead>
                            <tbody>
                                @foreach ($rules as $r)
                                    <tr @class(['opacity-60' => ! $r['isActive']])>
                                        <td><p class="font-medium">{{ $r['nextTitle'] }}</p>@unless ($r['isActive'])<x-badge>{{ t('todos.paused') }}</x-badge>@endunless</td>
                                        <td class="text-muted-foreground">{{ $schedule($r) }} · {{ t('todos.remindBefore', ['days' => $r['remindDaysBefore']]) }}</td>
                                        <td class="whitespace-nowrap">{{ d($r['nextDue']) }}</td>
                                        <td class="whitespace-nowrap text-muted-foreground">{{ $r['owner']['name'] }}</td>
                                        <td class="whitespace-nowrap text-right">
                                            <div class="flex justify-end gap-1">
                                                <button type="button" class="btn btn-ghost btn-sm" aria-label="{{ t('todos.ruleEdit') }}" @click="$dispatch('rule-edit', @js($ruleJs($r)))"><x-icon name="edit" /></button>
                                                <form method="POST" action="{{ route('recurring.destroy', $r['id']) }}" data-confirm="{{ t('todos.ruleDeleteConfirm', ['title' => $r['nextTitle']]) }}">@csrf @method('DELETE')
                                                    <button class="btn btn-ghost btn-sm" aria-label="{{ t('todos.ruleDelete') }}"><x-icon name="trash" /></button></form>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-card>

            <x-modal name="rule-form" :title="t('todos.ruleNew')" :open="$errors->any()">
                <form method="POST" :action="r.action" class="space-y-4">
                    @csrf
                    <input type="hidden" name="_method" :value="r.method">
                    <input type="hidden" name="_form" value="1">
                    <p class="hint">{{ t('todos.placeholdersHint') }} {прошлый_месяц}, {прошлый_квартал}, {прошлый_год}, {месяц}, {год}</p>
                    <div><label class="label" for="rt-title">{{ t('todos.title') }} <span class="text-danger">*</span></label>
                        <input id="rt-title" name="title" class="input" x-model="r.title" required maxlength="300">
                        @error('title')<p class="error">{{ $message }}</p>@enderror</div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div><label class="label" for="rt-freq">{{ t('todos.frequency') }}</label>
                            <select id="rt-freq" name="frequency" class="input" x-model="r.frequency">@foreach (RecurringTodoService::FREQUENCIES as $f)<option value="{{ $f }}">{{ t('todos.frequencies.'.$f) }}</option>@endforeach</select></div>
                        <div><label class="label" for="rt-day">{{ t('todos.dayOfMonth') }}</label>
                            <input id="rt-day" type="number" min="1" max="28" name="day_of_month" class="input" x-model="r.day_of_month" required>
                            @error('day_of_month')<p class="error">{{ $message }}</p>@enderror</div>
                        <div x-show="r.frequency === 'QUARTERLY'"><label class="label" for="rt-qmonth">{{ t('todos.month') }}</label>
                            <select id="rt-qmonth" name="month" class="input" x-model="r.month" :disabled="r.frequency !== 'QUARTERLY'">@foreach ($quarterMonths as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select>
                            @error('month')<p class="error">{{ $message }}</p>@enderror</div>
                        <div x-show="r.frequency === 'YEARLY'"><label class="label" for="rt-month">{{ t('todos.month') }}</label>
                            <select id="rt-month" name="month" class="input" x-model="r.month" :disabled="r.frequency !== 'YEARLY'">@foreach ($months as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select></div>
                        <div><label class="label" for="rt-remind">{{ t('todos.remindDaysBefore') }}</label>
                            <input id="rt-remind" type="number" min="0" max="30" name="remind_days_before" class="input" x-model="r.remind_days_before"></div>
                        <div><label class="label" for="rt-kind">{{ t('todos.kind') }}</label>
                            <select id="rt-kind" name="kind" class="input" x-model="r.kind">@foreach (TodoService::KINDS as $k)<option value="{{ $k }}">{{ t('todos.kinds.'.$k) }}</option>@endforeach</select></div>
                        <div><label class="label" for="rt-priority">{{ t('todos.priority') }}</label>
                            <select id="rt-priority" name="priority" class="input" x-model="r.priority">@foreach (TodoService::PRIORITIES as $p)<option value="{{ $p }}">{{ t('crm.priority.'.$p) }}</option>@endforeach</select></div>
                    </div>
                    <div><label class="label" for="rt-desc">{{ t('todos.description') }}</label>
                        <textarea id="rt-desc" name="description" rows="2" class="input" x-model="r.description"></textarea></div>
                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_active" value="1" x-model="r.is_active"> {{ t('todos.active') }}</label>
                    <div class="flex justify-end gap-2">
                        <button type="button" class="btn btn-outline" @click="$dispatch('close-modal', 'rule-form')">{{ t('todos.cancel') }}</button>
                        <button class="btn btn-primary">{{ t('todos.save') }}</button>
                    </div>
                </form>
            </x-modal>
        </div>
    @endif
@endsection
