@extends('layouts.app')
@section('title', t('templates.title'))
@section('content')
    <x-page-header :title="t('settings.title')" />
    @include('settings.partials.tabs')
    <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div><h2 class="font-semibold">{{ t('templates.title') }}</h2><p class="text-sm text-muted-foreground">{{ t('templates.subtitle') }}</p></div>
        <button type="button" class="btn btn-primary btn-sm" @click="$dispatch('open-modal', 'tpl-new')"><x-icon name="plus" /> {{ t('templates.new') }}</button>
    </div>
    @if ($templates->isEmpty())
        <x-card><x-empty icon="copy" :title="t('templates.empty')" /></x-card>
    @else
        <div class="grid gap-3 md:grid-cols-2">
            @foreach ($templates as $tpl)
                <div class="card card-body grid content-start gap-3">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <p class="font-medium">{{ $tpl->name }}</p>
                            <p class="text-sm text-muted-foreground">{{ $tpl->service_id ? ($services[$tpl->service_id] ?? '') : t('templates.noService') }} · {{ t('templates.count', ['count' => $tpl->tasks->count()]) }}</p>
                        </div>
                        <div class="flex items-center gap-2">
                            <x-badge :color="$tpl->is_active ? 'green' : 'gray'">{{ $tpl->is_active ? t('templates.active') : t('templates.inactive') }}</x-badge>
                            <button type="button" class="btn btn-ghost btn-icon" aria-label="{{ t('templates.edit') }}" @click="$dispatch('open-modal', 'tpl-{{ $tpl->id }}')"><x-icon name="edit" /></button>
                        </div>
                    </div>
                    <ol class="list-decimal pl-5 text-sm text-muted-foreground">
                        @foreach ($tpl->tasks as $x)<li>{{ $x->title }}@if ($x->role) — {{ t('specialties.'.$x->role) }}@endif</li>@endforeach
                    </ol>
                </div>
                <x-modal :name="'tpl-'.$tpl->id" :title="t('templates.edit')" size="max-w-3xl">
                    @include('settings.partials.template-form', ['template' => $tpl, 'modal' => 'tpl-'.$tpl->id])
                </x-modal>
            @endforeach
        </div>
    @endif
    <x-modal name="tpl-new" :title="t('templates.new')" size="max-w-3xl" :open="$errors->any()">
        @include('settings.partials.template-form', ['template' => null, 'modal' => 'tpl-new'])
    </x-modal>
@endsection
