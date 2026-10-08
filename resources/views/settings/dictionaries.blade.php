@extends('layouts.app')
@section('title', __('Справочники'))
@section('content')
<div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
@foreach([
 ['dict_statuses', 'Статусы лидов', 'Воронка продаж и этапы конверсии', 'funnel'],
 ['dict_sources', 'Источники лидов', 'Instagram, Telegram, сайт, рекомендации…', 'tag'],
 ['dict_methods', 'Способы оплаты', 'Наличные, Click, Payme, Uzum…', 'cash'],
 ['dict_categories', 'Категории расходов', 'Аренда, реклама, коммунальные…', 'receipt'],
 ['dict_reasons', 'Причины отчисления', 'Для анализа оттока учеников', 'alert'],
 ['dict_subjects', 'Предметы', 'Для курсов и преподавателей', 'book'],
] as [$r, $t, $d, $i])
    <a href="{{ route($r.'.index') }}" class="card flex items-start gap-3 p-4 transition hover:border-brand-500"><x-icon :name="$i" class="mt-0.5 text-brand-600" /><div><div class="font-medium">{{ __($t) }}</div><div class="text-xs text-slate-500">{{ __($d) }}</div></div></a>
@endforeach
    <a href="{{ route('branches.index') }}" class="card flex items-start gap-3 p-4 transition hover:border-brand-500"><x-icon name="building" class="mt-0.5 text-brand-600" /><div><div class="font-medium">{{ __('Филиалы') }}</div><div class="text-xs text-slate-500">{{ __('Филиалы и аудитории') }}</div></div></a>
</div>
@endsection
