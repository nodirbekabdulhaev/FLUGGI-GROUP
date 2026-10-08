@extends('layouts.app')
@section('title', __($cfg['title']))
@section('actions')
    @isset($cfg['export'])<a href="{{ request()->fullUrlWithQuery(['export' => 'csv']) }}" class="btn-secondary"><x-icon name="download" class="h-4 w-4" /> CSV</a>@endisset
    @if($canManage && ($cfg['create'] ?? true))<a href="{{ route($cfg['route'].'.create') }}" class="btn-primary"><x-icon name="plus" class="h-4 w-4" /> {{ __($cfg['add'] ?? 'Добавить') }}</a>@endif
@endsection
@section('content')
@isset($summary)<div class="mb-3 grid grid-cols-2 gap-3 md:grid-cols-4">@foreach($summary as $label => $val)<x-stat :label="__($label)" :value="$val" />@endforeach</div>@endisset
<div class="card" x-data="{ f: window.innerWidth >= 768 }">
    <button type="button" @click="f = !f" class="flex w-full items-center justify-between border-b px-4 py-3 text-sm font-medium md:hidden dark:border-slate-800">{{ __('Фильтры') }} <span x-text="f ? '▲' : '▼'"></span></button>
    <form method="GET" x-show="f" class="flex flex-wrap items-end gap-2 border-b border-slate-200 p-3 dark:border-slate-800">
        @if(! empty($cfg['search']))
        <div class="min-w-48 flex-1"><label class="label">{{ __('Поиск') }}</label><input type="search" name="q" value="{{ request('q') }}" class="input" placeholder="{{ __('Поиск…') }}"></div>
        @endif
        @foreach($cfg['filters'] ?? [] as $f)
            @php $opts = $f['options']; if ($opts instanceof Closure) $opts = $opts(); @endphp
            <div><label class="label">{{ __($f['label']) }}</label>
                <select name="{{ $f['name'] }}" class="input !w-auto"><option value="">{{ __('Все') }}</option>
                    @foreach($opts as $k => $v)<option value="{{ $k }}" @selected((string)request($f['name']) === (string)$k)>{{ __($v) }}</option>@endforeach
                </select></div>
        @endforeach
        <button class="btn-secondary">{{ __('Применить') }}</button>
        @if(request()->query())<a href="{{ url()->current() }}" class="btn-ghost">{{ __('Сбросить') }}</a>@endif
    </form>

    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-slate-200 dark:divide-slate-800">
            <thead class="bg-slate-50 dark:bg-slate-800/50"><tr>
                @foreach($cfg['columns'] as $col)<th class="th">{{ __($col['label']) }}</th>@endforeach
                @if($canManage || $canDelete)<th class="th"></th>@endif
            </tr></thead>
            <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
            @forelse($rows as $row)
                <tr class="hover:bg-slate-50/60 dark:hover:bg-slate-800/40">
                    @foreach($cfg['columns'] as $col)
                        @php
                          $v = isset($col['value']) ? $col['value']($row) : data_get($row, $col['key'] ?? 'id');
                          $href = ($col['link'] ?? false) ? (($cfg['show'] ?? false) ? route($cfg['route'].'.show', $row->id) : ($canManage ? route($cfg['route'].'.edit', $row->id) : null)) : null;
                        @endphp
                        <td class="td">
                            @if(isset($col['badge']))
                                @php $b = $col['badge'][$v] ?? [$v, 'slate']; @endphp <x-badge :color="$b[1]">{{ __($b[0]) }}</x-badge>
                            @elseif(($col['type'] ?? '') === 'money') {{ money($v) }}
                            @elseif(($col['type'] ?? '') === 'date') {{ fdate($v) }}
                            @elseif(($col['type'] ?? '') === 'bool') {{ $v ? '✓' : '—' }}
                            @elseif(($col['type'] ?? '') === 'file') @if($v)<a class="link" target="_blank" rel="noopener" href="{{ route('files.show', ['expense', $row->id]) }}">📎</a>@endif
                            @elseif($href)<a href="{{ $href }}" class="link font-medium">{{ $v ?: '—' }}</a>
                            @else {{ $v === null || $v === '' ? '—' : $v }}
                            @endif
                        </td>
                    @endforeach
                    @if($canManage || $canDelete)
                    <td class="td whitespace-nowrap text-right">
                        @if($canManage)<a href="{{ route($cfg['route'].'.edit', $row->id) }}" class="btn-ghost btn-sm" title="{{ __('Изменить') }}"><x-icon name="edit" class="h-4 w-4" /></a>@endif
                        @if($canDelete)
                        <form method="POST" action="{{ route($cfg['route'].'.destroy', $row->id) }}" class="inline" onsubmit="return confirm('{{ __('Удалить запись?') }}')">@csrf @method('DELETE')
                            <button class="btn-ghost btn-sm text-rose-600" title="{{ __('Удалить') }}"><x-icon name="trash" class="h-4 w-4" /></button></form>
                        @endif
                    </td>
                    @endif
                </tr>
            @empty
                <tr><td colspan="{{ count($cfg['columns']) + 1 }}" class="px-3 py-10 text-center text-sm text-slate-400">{{ __('Нет данных') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="border-t border-slate-200 p-3 dark:border-slate-800">{{ $rows->links() }}</div>
</div>
@if($errors->has('delete'))<div class="mt-3 rounded-lg border border-rose-200 bg-rose-50 px-4 py-2 text-sm text-rose-800">{{ $errors->first('delete') }}</div>@endif
@endsection
