@extends('layouts.app')
@section('title', $deal->title)
@section('content')
    @include('crm.partials.errors')
@php
    $open = $deal->status === 'OPEN';
    $canStage = $open && can('deal.change_stage');
    $idx = array_search($deal->stage->code, \App\Services\Crm\DealStages::CODES, true);
    $probability = $deal->probability_override ?? $deal->stage->probability;
    $weighted = \Brick\Math\BigDecimal::of((string) $deal->amount_uzs)->multipliedBy($probability)->dividedBy(100, 2, \Brick\Math\RoundingMode::HalfUp);
    $h = fn ($html) => new \Illuminate\Support\HtmlString($html);
    // Разделы других модулей подключаются в карточку без правки этого файла (если экран модуля готов и есть право)
    $slots = array_filter([
        'proposals' => ['sales.partials.deal-proposals', 'proposal.read', t('leads.tabs.proposals')],
        'contracts' => ['sales.partials.deal-contracts', 'contract.read', t('leads.tabs.contracts')],
        'payments' => ['sales.partials.deal-payments', 'payment.read', t('leads.tabs.payments')],
        'files' => ['sales.partials.deal-files', null, t('leads.tabs.files')],
        'projects' => ['projects.partials.deal-project', 'project.read', t('leads.tabs.projects')],
    ], fn ($s) => view()->exists($s[0]) && ($s[1] === null || can($s[1])));
    $tabs = ['overview' => t('leads.tabs.overview'), 'tasks' => t('leads.tabs.tasks'), 'activity' => t('leads.tabs.activity'), 'meetings' => t('leads.tabs.meetings'),
        'comments' => t('leads.tabs.comments'), 'history' => t('leads.tabs.history')] + array_map(fn ($s) => $s[2], $slots);
@endphp
    <a href="{{ route('crm.deals.index') }}" class="mb-4 inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"><x-icon name="arrow-left" /> {{ t('deals.title') }}</a>

    <div class="mb-6 flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
        <div class="min-w-0">
            <p class="text-xs text-muted-foreground">{{ \App\Support\Format::code('D', $deal->number) }}@if ($deal->is_repeat) · {{ t('deals.repeat') }}@endif</p>
            <h1 class="break-words text-xl font-semibold tracking-tight sm:text-2xl">{{ $deal->title }}</h1>
            <div class="mt-2 flex flex-wrap items-center gap-2 text-sm">
                @if ($open) @include('crm.partials.stage', ['stage' => $deal->stage]) @else @include('crm.partials.status', ['status' => $deal->status, 'kind' => 'deal']) @endif
                <a href="{{ route('crm.clients.show', $deal->client_id) }}" class="link">{{ $deal->client?->name }}</a>
                <span class="text-muted-foreground">·</span>
                <span class="flex items-center gap-1 text-muted-foreground"><x-icon name="user" class="size-3.5" /> {{ $deal->owner?->full_name }}</span>
            </div>
            @if ($deal->lossReason)
                <p class="mt-2 text-sm text-danger">{{ $deal->lossReason->label() }}@if ($deal->loss_comment) — {{ $deal->loss_comment }}@endif</p>
            @endif
        </div>
        <div class="flex flex-wrap items-start gap-3">
            <button type="button" class="btn btn-outline btn-sm" x-data x-on:click="$dispatch('open-modal', 'record-task')"><x-icon name="list" /> {{ t('todos.assignTask') }}</button>
            <div class="text-right">
                <p class="text-2xl font-semibold tabular-nums">{{ money($deal->amount, $deal->currency) }}</p>
                <p class="text-xs text-muted-foreground">
                    @if ($deal->currency === 'USD')≈ {{ money($deal->amount_uzs) }} · @endif
                    {{ t('crm.fields.probability') }} {{ $probability }}% · {{ t('deals.weighted') }} {{ money((string) $weighted) }}
                </p>
            </div>
            @if (can('deal.update'))
                <div class="relative" x-data="{ open: false }" x-on:click.outside="open = false">
                    <button type="button" class="btn btn-outline btn-icon h-8 w-8" x-on:click="open = !open" aria-label="{{ t('common.actions') }}"><x-icon name="more" /></button>
                    <div x-show="open" x-cloak class="absolute right-0 z-20 mt-1 w-56 rounded-md border bg-surface py-1 shadow-lg">
                        <button type="button" class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-muted" x-on:click="open = false; $dispatch('open-modal', 'deal-edit')"><x-icon name="edit" /> {{ t('crm.common.edit') }}</button>
                        @if (can('lead.assign') && $open && $owners)
                            <button type="button" class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-muted" x-on:click="open = false; $dispatch('open-modal', 'assign-record')"><x-icon name="user" /> {{ t('crm.common.assign') }}</button>
                        @endif
                        @if ($open)
                            <button type="button" class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-danger hover:bg-muted" x-on:click="open = false; $dispatch('open-modal', 'close-record')"><x-icon name="x" /> {{ t('crm.common.close') }}</button>
                        @elseif ($deal->status !== 'WON')
                            <form method="POST" action="{{ route('crm.deals.reopen', $deal->id) }}">@csrf
                                <button class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-muted"><x-icon name="refresh" /> {{ t('crm.common.reopen') }}</button>
                            </form>
                        @endif
                        @if (can('deal.delete') && $deal->status !== 'WON')
                            <form method="POST" action="{{ route('crm.deals.destroy', $deal->id) }}" data-confirm="{{ t('crm.common.deleteConfirm', ['name' => $deal->title]) }}" class="border-t">@csrf @method('DELETE')
                                <button class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-danger hover:bg-muted"><x-icon name="trash" /> {{ t('crm.common.delete') }}</button>
                            </form>
                        @endif
                    </div>
                </div>
            @endif
        </div>
    </div>

    {{-- Деньги по сделке: договоры, оплаты, ожидается, проект (только чтение) --}}
    @if ($money)
        <div class="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-4">
            @foreach ([[t('sales.money.contract'), $money['contract']], [t('sales.money.paid'), $money['paid']], [t('sales.money.receivable'), $money['receivable']]] as [$label, $value])
                <div class="card p-3"><p class="text-xs text-muted-foreground">{{ $label }}</p><p class="font-semibold tabular-nums">{{ money($value) }}</p></div>
            @endforeach
            <div class="card p-3">
                <p class="text-xs text-muted-foreground">{{ t('sales.money.project') }}</p>
                @if ($money['project'])
                    <p class="flex flex-wrap items-center gap-2 font-semibold">
                        @if (can('project.read'))<a href="/projects/{{ $money['project']->id }}" class="hover:underline">{{ \App\Support\Format::code('P', $money['project']->number) }}</a>@else{{ \App\Support\Format::code('P', $money['project']->number) }}@endif
                        <x-badge color="blue">{{ t('sales.projectStatus.'.$money['project']->status) }}</x-badge>
                    </p>
                @else
                    <p class="text-sm text-muted-foreground">—</p>
                @endif
            </div>
        </div>
    @endif

    @if ($canStage)
        <div class="mb-6">
            <div class="hidden gap-1 sm:flex">
                @foreach ($stages as $i => $s)
                    <form method="POST" action="{{ route('crm.deals.stage', $deal->id) }}" class="flex-1">@csrf
                        <input type="hidden" name="stage" value="{{ $s->code }}">
                        <button type="submit" @disabled($s->id === $deal->stage_id || $s->code === 'PAID') @if ($s->code === 'PAID') title="{{ t('deals.paidAutoHint') }}" @endif
                                class="w-full rounded-md px-2 py-2 text-xs font-medium transition-colors hover:opacity-90 disabled:cursor-default"
                                style="{{ $i <= $idx ? 'background-color: '.$s->color.'; color: #fff' : 'background-color: var(--color-muted); color: var(--color-muted-foreground)' }}">{{ $s->label() }}</button>
                    </form>
                @endforeach
            </div>
            <form method="POST" action="{{ route('crm.deals.stage', $deal->id) }}" class="sm:hidden">@csrf
                <select name="stage" class="input" aria-label="{{ t('crm.common.changeStage') }}" onchange="this.form.submit()">
                    @foreach ($stages->where('code', '<>', 'PAID') as $s)<option value="{{ $s->code }}" @selected($s->id === $deal->stage_id)>{{ $s->label() }}</option>@endforeach
                </select>
            </form>
            <p class="mt-2 text-xs text-muted-foreground">{{ t('deals.gatesHint') }}</p>
        </div>
    @endif

    <div x-data="{ tab: (location.hash || '#overview').slice(1) }" x-on:hashchange.window="tab = (location.hash || '#overview').slice(1)">
        <nav class="mb-4 flex gap-1 overflow-x-auto border-b">
            @foreach ($tabs as $key => $label)
                <a href="#{{ $key }}" class="whitespace-nowrap border-b-2 px-3 py-2 text-sm font-medium" :class="tab === '{{ $key }}' ? 'border-foreground text-foreground' : 'border-transparent text-muted-foreground hover:text-foreground'">{{ $label }}</a>
            @endforeach
        </nav>
        <div class="card card-body">
            <div x-show="tab === 'overview'">
                @include('crm.partials.details', ['rows' => [
                    [t('crm.fields.client'), $h('<a class="link" href="'.route('crm.clients.show', $deal->client_id).'">'.e($deal->client?->name).'</a>')],
                    [t('crm.fields.contact'), $deal->contact?->full_name],
                    [t('crm.fields.service'), $deal->service?->label()],
                    [t('crm.fields.smmIdea'), $deal->smmIdea ? (\App\Services\Smm\SmmAccess::allowed() ? $h('<a class="link" href="/smm/'.e($deal->smm_idea_id).'">'.e($deal->smmIdea->code().' · '.$deal->smmIdea->title).'</a>') : $deal->smmIdea->code().' · '.$deal->smmIdea->title) : null],
                    [t('crm.fields.amount'), money($deal->amount, $deal->currency).($deal->currency === 'USD' ? ' ('.t('deals.rate', ['rate' => \App\Support\Format::number((string) $deal->exchange_rate, 6)]).')' : '')],
                    [t('crm.fields.probability'), $probability.'%'.($deal->probability_override !== null ? ' ('.t('deals.manual').')' : '')],
                    [t('crm.fields.expectedCloseDate'), d($deal->expected_close_date)],
                    [t('crm.fields.owner'), $deal->owner?->full_name],
                    [t('crm.fields.team'), $deal->team?->name],
                    [t('deals.fromLead'), $leadId ? $h('<a class="link" href="'.route('crm.leads.show', $leadId).'">'.e(t('deals.openLead')).'</a>') : null],
                    [t('crm.fields.createdAt'), dt($deal->created_at)],
                ]])
            </div>
            <div x-show="tab === 'tasks'" x-cloak>@include('todos.partials.record-tasks', ['column' => 'deal_id', 'recordId' => $deal->id])</div>
            <div x-show="tab === 'activity'" x-cloak>@include('crm.partials.timeline')</div>
            <div x-show="tab === 'meetings'" x-cloak>@include('crm.partials.meetings', ['target' => ['deal_id' => $deal->id], 'ownerId' => $deal->owner_id, 'canCreate' => $open])</div>
            <div x-show="tab === 'comments'" x-cloak>@include('crm.partials.comments', ['target' => ['deal_id' => $deal->id]])</div>
            <div x-show="tab === 'history'" x-cloak>@include('crm.partials.history')</div>
            @if (isset($slots['proposals']))<div x-show="tab === 'proposals'" x-cloak>@includeIf('sales.partials.deal-proposals', ['deal' => $deal])</div>@endif
            @if (isset($slots['contracts']))<div x-show="tab === 'contracts'" x-cloak>@includeIf('sales.partials.deal-contracts', ['deal' => $deal])</div>@endif
            @if (isset($slots['payments']))<div x-show="tab === 'payments'" x-cloak>@includeIf('sales.partials.deal-payments', ['deal' => $deal])</div>@endif
            @if (isset($slots['files']))<div x-show="tab === 'files'" x-cloak>@includeIf('sales.partials.deal-files', ['deal' => $deal])</div>@endif
            @if (isset($slots['projects']))<div x-show="tab === 'projects'" x-cloak>@includeIf('projects.partials.deal-project', ['deal' => $deal])</div>@endif
        </div>
    </div>

    @if (can('deal.update'))
        <x-modal name="deal-edit" :title="t('deals.editTitle')" :open="$errors->any() && old('_form') === 'deal-edit'">
            @include('crm.deals.form', ['deal' => $deal])
        </x-modal>
        @if ($open)
            @include('crm.partials.close-modal', ['action' => route('crm.deals.close', $deal->id)])
        @endif
        @if (can('lead.assign') && $open && $owners)
            @include('crm.partials.assign-modal', ['action' => route('crm.deals.assign', $deal->id), 'current' => $deal->owner_id])
        @endif
    @endif
    @include('todos.partials.record-task-modal', ['column' => 'deal_id', 'recordId' => $deal->id, 'back' => route('crm.deals.show', $deal->id, false).'#tasks'])
@endsection
