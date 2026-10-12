@extends('layouts.app')
@section('title', $lead->title)
@section('content')
    @include('crm.partials.errors')
@php
    $open = $lead->status === 'OPEN';
    $canEdit = can('lead.update');
    $currentIdx = array_search($lead->stage->code, \App\Services\Crm\Crm::LEAD_STAGES, true);
    $h = fn ($html) => new \Illuminate\Support\HtmlString($html);
@endphp
    <a href="{{ route('crm.leads.index') }}" class="mb-4 inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"><x-icon name="arrow-left" /> {{ t('leads.title') }}</a>

    {{-- Шапка (ТЗ §11): название, статус, ответственный, приоритет, сумма --}}
    <div class="mb-6 flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
        <div class="min-w-0">
            <p class="text-xs text-muted-foreground">{{ \App\Support\Format::code('L', $lead->number) }}</p>
            <h1 class="break-words text-xl font-semibold tracking-tight sm:text-2xl">{{ $lead->title }}</h1>
            <div class="mt-2 flex flex-wrap items-center gap-2 text-sm">
                @if ($open) @include('crm.partials.stage', ['stage' => $lead->stage]) @else @include('crm.partials.status', ['status' => $lead->status, 'kind' => 'lead']) @endif
                @include('crm.partials.score', ['level' => $lead->score_level, 'score' => $lead->score])
                <span class="text-muted-foreground">·</span>
                <span class="flex items-center gap-1 text-muted-foreground"><x-icon name="user" class="size-3.5" /> {{ $lead->owner?->full_name }}</span>
                <span class="text-muted-foreground">·</span>
                <span @class(['text-muted-foreground' => $lead->priority === 'LOW', 'text-warning' => $lead->priority === 'HIGH', 'font-medium text-danger' => $lead->priority === 'URGENT'])>{{ t('crm.priority.'.$lead->priority) }}</span>
                <span class="text-muted-foreground">·</span>
                <span class="font-medium">{{ money($lead->budget, $lead->currency) }}</span>
            </div>
            @if ($lead->lossReason)
                <p class="mt-2 text-sm text-danger">{{ $lead->lossReason->label() }}@if ($lead->loss_comment) — {{ $lead->loss_comment }}@endif</p>
            @endif
        </div>
        <div class="flex flex-wrap gap-2">
            @if ($lead->phone)<a href="tel:{{ $lead->phone }}" class="btn btn-outline btn-sm"><x-icon name="phone" /> {{ t('crm.common.byPhone') }}</a>@endif
            @if ($lead->telegram)<a href="https://t.me/{{ ltrim($lead->telegram, '@') }}" target="_blank" rel="noopener noreferrer" class="btn btn-outline btn-sm"><x-icon name="send" /> Telegram</a>@endif
            @if ($open && can('deal.create') && $canEdit)
                <button type="button" class="btn btn-accent btn-sm" x-data x-on:click="$dispatch('open-modal', 'lead-convert')"><x-icon name="check" /> {{ t('leads.convert') }}</button>
            @endif
            @if ($lead->deal_id)<a href="{{ route('crm.deals.show', $lead->deal_id) }}" class="btn btn-primary btn-sm">{{ t('leads.openDeal') }}</a>@endif
            @if ($canEdit)
                <div class="relative" x-data="{ open: false }" x-on:click.outside="open = false">
                    <button type="button" class="btn btn-outline btn-icon h-8 w-8" x-on:click="open = !open" aria-label="{{ t('common.actions') }}"><x-icon name="more" /></button>
                    <div x-show="open" x-cloak class="absolute right-0 z-20 mt-1 w-56 rounded-md border bg-surface py-1 shadow-lg">
                        <button type="button" class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-muted" x-on:click="open = false; $dispatch('open-modal', 'lead-edit')"><x-icon name="edit" /> {{ t('crm.common.edit') }}</button>
                        @if (can('lead.assign') && $open && $owners)
                            <button type="button" class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-muted" x-on:click="open = false; $dispatch('open-modal', 'assign-record')"><x-icon name="user" /> {{ t('crm.common.assign') }}</button>
                        @endif
                        @if ($open)
                            <button type="button" class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-danger hover:bg-muted" x-on:click="open = false; $dispatch('open-modal', 'close-record')"><x-icon name="x" /> {{ t('crm.common.close') }}</button>
                        @elseif ($lead->status !== 'CONVERTED')
                            <form method="POST" action="{{ route('crm.leads.reopen', $lead->id) }}">@csrf
                                <button class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-muted"><x-icon name="refresh" /> {{ t('crm.common.reopen') }}</button>
                            </form>
                        @endif
                        @if (can('lead.delete') && $lead->status !== 'CONVERTED')
                            <form method="POST" action="{{ route('crm.leads.destroy', $lead->id) }}" data-confirm="{{ t('crm.common.deleteConfirm', ['name' => $lead->title]) }}" class="border-t">@csrf @method('DELETE')
                                <button class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-danger hover:bg-muted"><x-icon name="trash" /> {{ t('crm.common.delete') }}</button>
                            </form>
                        @endif
                    </div>
                </div>
            @endif
        </div>
    </div>

    @if ($duplicates['leads']->isNotEmpty() || $duplicates['clients']->isNotEmpty())
        <div class="mb-6 rounded-md border border-warning/40 bg-warning-soft p-3 text-sm">
            <p class="font-medium text-warning">{{ t('leads.duplicates.title') }}</p>
            <ul class="mt-1 grid gap-1">
                @foreach ($duplicates['leads'] as $d)
                    <li><a class="link" href="{{ route('crm.leads.show', $d->id) }}">{{ \App\Support\Format::code('L', $d->number) }} {{ $d->title }}</a> <span class="text-xs text-muted-foreground">· {{ t('crm.status.lead.'.$d->status) }} · {{ $d->owner?->full_name }}</span></li>
                @endforeach
                @foreach ($duplicates['clients'] as $c)
                    <li>{{ t('crm.fields.client') }}: <a class="link" href="{{ route('crm.clients.show', $c->id) }}">{{ \App\Support\Format::code('C', $c->number) }} {{ $c->name }}</a> <span class="text-xs text-muted-foreground">· {{ $c->owner?->full_name }}</span></li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- Этапы лида — кликабельный прогресс --}}
    @if ($open && $canEdit)
        <div class="mb-6">
            <div class="hidden gap-1 sm:flex">
                @foreach ($stages as $i => $s)
                    <form method="POST" action="{{ route('crm.leads.stage', $lead->id) }}" class="flex-1">@csrf
                        <input type="hidden" name="stage" value="{{ $s->code }}">
                        <button type="submit" @disabled($s->id === $lead->stage_id) class="w-full rounded-md px-2 py-2 text-xs font-medium transition-colors hover:opacity-90 disabled:cursor-default"
                                style="{{ $i <= $currentIdx ? 'background-color: '.$s->color.'; color: #fff' : 'background-color: var(--color-muted); color: var(--color-muted-foreground)' }}">{{ $s->label() }}</button>
                    </form>
                @endforeach
            </div>
            <form method="POST" action="{{ route('crm.leads.stage', $lead->id) }}" class="sm:hidden">@csrf
                <select name="stage" class="input" aria-label="{{ t('crm.common.changeStage') }}" onchange="this.form.submit()">
                    @foreach ($stages as $s)<option value="{{ $s->code }}" @selected($s->id === $lead->stage_id)>{{ $s->label() }}</option>@endforeach
                </select>
            </form>
        </div>
    @endif

    <div x-data="{ tab: (location.hash || '#overview').slice(1) }" x-on:hashchange.window="tab = (location.hash || '#overview').slice(1)">
        <nav class="mb-4 flex gap-1 overflow-x-auto border-b">
            @foreach (['overview', 'activity', 'meetings', 'comments', 'history', 'docs'] as $key)
                <a href="#{{ $key }}" class="whitespace-nowrap border-b-2 px-3 py-2 text-sm font-medium" :class="tab === '{{ $key }}' ? 'border-foreground text-foreground' : 'border-transparent text-muted-foreground hover:text-foreground'">
                    {{ $key === 'docs' ? t('leads.tabs.proposals').' · '.t('leads.tabs.contracts').' · '.t('leads.tabs.payments') : t('leads.tabs.'.$key) }}
                </a>
            @endforeach
        </nav>
        <div class="card card-body">
            <div x-show="tab === 'overview'">
                @include('crm.partials.details', ['rows' => [
                    [t('crm.fields.contactName'), $lead->contact_name],
                    [t('crm.fields.companyName'), $lead->company_name],
                    [t('crm.fields.phone'), $lead->phone ? $h('<a class="link" href="tel:'.e($lead->phone).'">'.e($lead->phone).'</a>') : null],
                    [t('crm.fields.telegram'), $lead->telegram],
                    [t('crm.fields.whatsapp'), $lead->whatsapp],
                    [t('crm.fields.instagram'), $lead->instagram],
                    [t('crm.fields.email'), $lead->email],
                    [t('crm.fields.website'), $lead->website],
                    [t('crm.fields.city'), collect([$lead->city, $lead->country])->filter()->implode(', ')],
                    [t('crm.fields.source'), $lead->source?->label()],
                    [t('crm.fields.service'), $lead->service?->label()],
                    [t('crm.fields.budget'), $lead->budget !== null ? money($lead->budget, $lead->currency).($lead->currency === 'USD' ? ' ≈ '.money($lead->budget_uzs) : '') : null],
                    [t('crm.fields.desiredDate'), $lead->desired_date ? d($lead->desired_date) : null],
                    [t('crm.fields.companySize'), $lead->company_size ? t('crm.companySize.'.$lead->company_size) : null],
                    [t('crm.fields.interest'), $lead->interest],
                    [t('crm.fields.team'), $lead->team?->name],
                    [t('crm.fields.nextContactAt'), $lead->next_contact_at ? dt($lead->next_contact_at) : null],
                    [t('crm.fields.lastContactAt'), $lead->last_contact_at ? dt($lead->last_contact_at) : null],
                    [t('crm.fields.createdAt'), dt($lead->created_at)],
                    [t('crm.fields.comment'), $lead->comment ? $h('<span class="whitespace-pre-wrap">'.e($lead->comment).'</span>') : null],
                ]])
                @if ($open && can('meeting.create'))
                    <p class="mt-4 text-sm"><a href="#meetings" class="link inline-flex items-center gap-1"><x-icon name="calendar" /> {{ t('crm.common.newMeeting') }}</a></p>
                @endif
            </div>
            <div x-show="tab === 'activity'" x-cloak>@include('crm.partials.timeline')</div>
            <div x-show="tab === 'meetings'" x-cloak>@include('crm.partials.meetings', ['target' => ['lead_id' => $lead->id], 'canCreate' => $open])</div>
            <div x-show="tab === 'comments'" x-cloak>@include('crm.partials.comments', ['target' => ['lead_id' => $lead->id]])</div>
            <div x-show="tab === 'history'" x-cloak>@include('crm.partials.history')</div>
            <div x-show="tab === 'docs'" x-cloak class="flex flex-wrap items-center gap-3 text-sm">
                <span class="text-muted-foreground">{{ $lead->deal_id ? t('sales.leadDocs') : t('sales.leadDocsBefore') }}</span>
                @if ($lead->deal_id)<a href="{{ route('crm.deals.show', $lead->deal_id) }}" class="btn btn-primary btn-sm">{{ t('leads.openDeal') }}</a>@endif
            </div>
        </div>
    </div>

    @if ($canEdit)
        <x-modal name="lead-edit" :title="t('leads.editTitle')" size="max-w-2xl" :open="$errors->any() && old('_form') === 'lead-edit'">
            @include('crm.leads.form', ['lead' => $lead])
        </x-modal>
        @if ($open)
            @include('crm.partials.close-modal', ['action' => route('crm.leads.close', $lead->id)])
        @endif
    @endif
    @if (can('lead.assign') && $open && $owners)
        @include('crm.partials.assign-modal', ['action' => route('crm.leads.assign', $lead->id), 'current' => $lead->owner_id])
    @endif
    @if ($open && can('deal.create') && $canEdit)
        @include('crm.leads.convert')
    @endif
@endsection
