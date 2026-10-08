@extends('layouts.app')
@section('title', __('Воронка'))
@section('actions')<a href="{{ route('leads.index') }}" class="btn-secondary">{{ __('Список') }}</a>@can('leads.manage')<a href="{{ route('leads.create') }}" class="btn-primary"><x-icon name="plus" class="h-4 w-4" /> {{ __('Добавить лид') }}</a>@endcan @endsection
@section('content')
<div class="-mx-3 overflow-x-auto px-3 pb-4">
    <div class="flex gap-3" style="min-width: max-content">
    @foreach($columns as $col)
        @php $s = $col['status']; @endphp
        <div class="w-72 shrink-0">
            <div class="mb-2 flex items-center justify-between px-1"><x-badge :color="$s->color">{{ $s->name }}</x-badge><span class="text-xs font-semibold text-slate-500">{{ $counts[$s->id] ?? 0 }}</span></div>
            <div class="space-y-2 rounded-xl bg-slate-100/70 p-2 dark:bg-slate-900/60">
                @forelse($col['leads'] as $l)
                    <div class="card p-3 text-sm">
                        <a href="{{ route('leads.show', $l) }}" class="link font-medium">{{ $l->full_name }}</a>
                        <div class="text-xs text-slate-500">{{ $l->phone }}</div>
                        <div class="mt-1 text-xs text-slate-500">{{ $l->course?->name }} · {{ $l->source?->name }}</div>
                        <div class="mt-1 flex items-center justify-between text-[11px] text-slate-400"><span>{{ $l->manager?->name }}</span><span>{{ $l->created_at->format('d.m') }}</span></div>
                        @can('leads.manage')
                        <form method="POST" action="{{ route('leads.status', $l) }}" class="mt-2">@csrf
                            <select name="status_id" onchange="this.form.submit()" class="input !py-1 text-xs"><option value="">{{ __('Переместить…') }}</option>@foreach($statuses as $st)@if($st->id !== $s->id)<option value="{{ $st->id }}">{{ $st->name }}</option>@endif @endforeach</select>
                        </form>
                        @endcan
                    </div>
                @empty<div class="py-4 text-center text-xs text-slate-400">—</div>
                @endforelse
                @if(($counts[$s->id] ?? 0) > 25)<a href="{{ route('leads.index', ['status_id' => $s->id]) }}" class="block py-1 text-center text-xs link">{{ __('Показать все') }} ({{ $counts[$s->id] }})</a>@endif
            </div>
        </div>
    @endforeach
    </div>
</div>
@endsection
