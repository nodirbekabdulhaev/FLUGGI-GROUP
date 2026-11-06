@extends('layouts.app')
@section('title', t('settings.title'))
@section('content')
    <x-page-header :title="t('settings.title')" :subtitle="t('settings.subtitle')" />
    @if (empty($tabs))
        <x-card><x-empty icon="lock" :title="t('common.forbiddenTitle')">{{ t('common.forbiddenText') }}</x-empty></x-card>
    @else
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($tabs as $tabItem)
                <a href="{{ $tabItem['href'] }}" class="card card-body flex items-center justify-between gap-3 hover:bg-muted/40">
                    <span class="font-medium">{{ t('settings.'.$tabItem['key']) }}</span>
                    <x-icon name="chevron-right" class="size-4 text-muted-foreground" />
                </a>
            @endforeach
        </div>
    @endif
@endsection
