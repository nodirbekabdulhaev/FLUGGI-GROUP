@extends('layouts.app')
@section('title', t('boardColors.title'))
@section('content')
    <x-page-header :title="t('boardColors.title')" :subtitle="t('boardColors.subtitle')" />
    @include('settings.partials.tabs')
    @unless ($canEdit)
        <p class="mb-4 rounded-2xl border border-white bg-warning-soft px-4 py-3 text-sm text-warning">{{ t('boardColors.readOnly') }}</p>
    @endunless
    <div class="grid gap-6">
        @foreach ($boards as $board => $colors)
            <x-card :title="t('boardColors.'.$board)">
                <form method="POST" action="{{ route('settings.boardColors.update', $board) }}" class="grid gap-5">
                    @csrf @method('PUT')
                    @error('colors')<p class="error">{{ $message }}</p>@enderror
                    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                        @foreach ($colors as $status => $color)
                            @php($label = $board === 'smm' ? t('smm.status.'.$status) : t('tasks.status.'.$status))
                            <div class="kanban-col w-auto p-3" style="--col: {{ $color }}" data-color-preview>
                                <div class="mb-3 flex items-center gap-2">
                                    <span class="kanban-title">{{ $label }}</span>
                                </div>
                                <x-color-picker :name="'colors['.$status.']'" :value="$color" :disabled="! $canEdit" class="rounded-xl bg-white/80 p-3" />
                                @error('colors.'.$status)<p class="error">{{ $message }}</p>@enderror
                            </div>
                        @endforeach
                    </div>
                    @if ($canEdit)
                        <div class="flex justify-end"><button class="btn btn-primary">{{ t('common.save') }}</button></div>
                    @endif
                </form>
            </x-card>
        @endforeach

        <x-card :title="t('boardColors.stages')" :subtitle="t('boardColors.stagesHint')">
            <div class="flex flex-wrap gap-2">
                @foreach ($stages as $s)
                    <span class="kanban-col w-auto flex-row items-center gap-2 rounded-full px-3 py-1.5" style="--col: {{ $s->color }}">
                        <span class="size-2.5 rounded-full" style="background-color: {{ $s->color }}" aria-hidden="true"></span>
                        <span class="kanban-title text-xs">{{ $s->label() }}</span>
                    </span>
                @endforeach
            </div>
            @if ($canStages)
                <a href="{{ url('/settings/references') }}" class="btn btn-outline btn-sm mt-4"><x-icon name="settings" /> {{ t('boardColors.openReferences') }}</a>
            @endif
        </x-card>
    </div>
@endsection
