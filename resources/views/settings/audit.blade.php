@extends('layouts.app')
@section('title', t('settings.auditTitle'))
@section('content')
    <x-page-header :title="t('settings.title')" />
    @include('settings.partials.tabs')
    <x-card :title="t('settings.auditTitle')" :subtitle="t('settings.auditText')" :padding="false">
        <form method="GET" class="flex flex-col gap-3 border-b p-4 sm:flex-row sm:items-end">
            <div><label class="label" for="a-type">{{ t('audit.entityType') }}</label><input id="a-type" name="entity_type" value="{{ $filters['entity_type'] ?? '' }}" class="input sm:w-44"></div>
            <div><label class="label" for="a-from">{{ t('period.from') }}</label><input id="a-from" type="date" name="from" value="{{ $filters['from'] ?? '' }}" class="input"></div>
            <div><label class="label" for="a-to">{{ t('period.to') }}</label><input id="a-to" type="date" name="to" value="{{ $filters['to'] ?? '' }}" class="input"></div>
            <button class="btn btn-outline">{{ t('common.filter') }}</button>
        </form>
        @if ($logs->isEmpty())
            <x-empty :title="t('settings.auditEmpty')" />
        @else
            <ul class="divide-y">
                @foreach ($logs as $log)
                    <li class="grid gap-1 px-4 py-3 text-sm sm:grid-cols-[10rem,1fr] sm:px-5">
                        <span class="text-muted-foreground">{{ dt($log->created_at) }}</span>
                        <div class="min-w-0">
                            <p><span class="font-medium">{{ $log->actor?->full_name ?? t('settings.auditSystem') }}</span>
                                <span class="text-muted-foreground">— {{ \App\Support\Lang::has('audit.'.$log->action) ? t('audit.'.$log->action) : $log->action }}</span></p>
                            @if ($log->changes)
                                <ul class="mt-1 grid gap-0.5 text-xs text-muted-foreground">
                                    @foreach ($log->changes as $field => $change)
                                        @php($show = fn ($v) => $v === null || $v === '' ? '—' : (is_scalar($v) ? (is_bool($v) ? ($v ? 'true' : 'false') : (string) $v) : json_encode($v, JSON_UNESCAPED_UNICODE)))
                                        <li class="break-all"><span class="font-mono">{{ $field }}</span>: {{ $show($change['old'] ?? null) }} → {{ $show($change['new'] ?? null) }}</li>
                                    @endforeach
                                </ul>
                            @endif
                            @if ($log->ip)<p class="text-xs text-muted-foreground/70">IP {{ $log->ip }}</p>@endif
                        </div>
                    </li>
                @endforeach
            </ul>
            <div class="border-t px-3 py-2">{{ $logs->links('vendor.pagination.fluggi') }}</div>
        @endif
    </x-card>
@endsection
