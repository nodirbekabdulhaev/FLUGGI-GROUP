@extends('layouts.app')
@section('title', t('schedules.title'))
@section('content')
    <x-page-header :title="t('settings.title')" />
    @include('settings.partials.tabs')
    <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div><h2 class="font-semibold">{{ t('schedules.title') }}</h2><p class="max-w-2xl text-sm text-muted-foreground">{{ t('schedules.subtitle') }}</p></div>
        @if (access()->can('schedule.manage', 'ALL'))
            <button type="button" class="btn btn-primary btn-sm" @click="$dispatch('open-modal', 'ws-new')"><x-icon name="plus" /> {{ t('schedules.new') }}</button>
        @endif
    </div>
    <x-card :padding="false">
        @if ($schedules->isEmpty())
            <x-empty icon="calendar" :title="t('schedules.title')" />
        @else
            <ul class="divide-y">
                @foreach ($schedules as $s)
                    <li class="flex flex-wrap items-center gap-3 px-4 py-3 sm:px-5">
                        <div class="min-w-0 flex-1">
                            <p class="font-medium">{{ $s->name }} · {{ $s->start_time }}–{{ $s->end_time }}</p>
                            <p class="text-sm text-muted-foreground">{{ $s->role_code ? t('roles.'.$s->role_code) : t('schedules.noRole') }} · {{ collect($s->work_days)->map(fn ($dd) => t('schedules.weekdays.'.$dd))->implode(', ') }} · {{ t('schedules.grace') }}: {{ $s->grace_minutes }}</p>
                            @if ($s->employees->isNotEmpty())<p class="text-xs text-muted-foreground">{{ $s->employees->map(fn ($e) => $e->user?->full_name)->filter()->implode(', ') }}</p>@endif
                        </div>
                        <x-badge :color="$s->is_active ? 'green' : 'gray'">{{ $s->is_active ? t('schedules.active') : t('schedules.inactive') }}</x-badge>
                        @if (access()->can('schedule.manage', 'ALL'))
                            <button type="button" class="btn btn-ghost btn-icon" aria-label="{{ t('schedules.edit') }}" @click="$dispatch('open-modal', 'ws-{{ $s->id }}')"><x-icon name="edit" /></button>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </x-card>
    @if (access()->can('schedule.manage', 'ALL'))
        @foreach ($schedules as $s)
            <x-modal :name="'ws-'.$s->id" :title="t('schedules.edit')">@include('settings.partials.schedule-form', ['schedule' => $s])</x-modal>
        @endforeach
        <x-modal name="ws-new" :title="t('schedules.new')" :open="$errors->any()">@include('settings.partials.schedule-form', ['schedule' => null])</x-modal>
    @endif
@endsection
