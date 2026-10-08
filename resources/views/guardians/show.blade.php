@extends('layouts.app')
@section('title', $guardian->full_name)
@section('actions')@can('students.manage')<a href="{{ route('guardians.edit', $guardian->id) }}" class="btn-secondary"><x-icon name="edit" class="h-4 w-4" /> {{ __('Изменить') }}</a>@endcan @endsection
@section('content')
<div class="grid gap-4 lg:grid-cols-3">
    <div class="card card-body"><dl class="grid grid-cols-3 gap-y-2 text-sm">
        <dt class="text-slate-500">{{ __('Телефон') }}</dt><dd class="col-span-2">{{ $guardian->phone ?? '—' }}</dd>
        <dt class="text-slate-500">Telegram</dt><dd class="col-span-2">{{ $guardian->telegram ?? '—' }} @if($guardian->telegramAccount?->isLinked())<x-badge color="emerald">✓ bot</x-badge>@endif</dd>
        <dt class="text-slate-500">{{ __('Адрес') }}</dt><dd class="col-span-2">{{ $guardian->address ?? '—' }}</dd></dl></div>
    <div class="card lg:col-span-2"><div class="border-b p-4 text-sm font-semibold dark:border-slate-800">{{ __('Дети') }}</div>
        <ul class="divide-y dark:divide-slate-800">
        @forelse($guardian->children as $c)
            @php $b = $c->balance; @endphp
            <li class="flex items-center justify-between p-4 text-sm"><div><a class="link font-medium" href="{{ route('students.show', $c) }}">{{ $c->full_name }}</a><div class="text-xs text-slate-500">{{ $c->pivot->relation }}</div></div>
                <span class="{{ $b > 0 ? 'font-medium text-rose-600' : 'text-slate-400' }}">{{ $b > 0 ? __('Долг').': '.money($b) : '—' }}</span></li>
        @empty<li class="p-4 text-sm text-slate-400">—</li>@endforelse
        </ul></div>
</div>
@endsection
