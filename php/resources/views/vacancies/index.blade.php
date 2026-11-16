@extends('layouts.app')
@section('title', t('vacancies.title'))
@section('content')
    @php $colors = ['NEW' => 'blue', 'VIEWED' => 'gray', 'INTERVIEW' => 'yellow', 'HIRED' => 'green', 'REJECTED' => 'red']; @endphp
    <x-page-header :title="t('vacancies.title')" :subtitle="t('vacancies.subtitle')">
        <x-view-toggle :view="$view" key="vacancies" />
        @if ($canManage)<form method="POST" action="/vacancies/rescore">@csrf <button class="btn btn-outline btn-sm"><x-icon name="refresh" /> {{ t('vacancies.rescore') }}</button></form>@endif
    </x-page-header>
    @include('vacancies.partials.tabs', ['active' => '/vacancies'])
    <div class="mb-4 flex flex-wrap items-center gap-2">
        @if ($view === 'list')
        <a href="/vacancies?view=list" @class(['btn btn-sm', empty($filters['status']) ? 'btn-primary' : 'btn-outline'])>{{ t('vacancies.all') }} · {{ array_sum($counts) }}</a>
        @foreach (\App\Services\Vacancies\VacancyService::STATUSES as $s)
            <a href="/vacancies?view=list&status={{ $s }}" @class(['btn btn-sm', ($filters['status'] ?? null) === $s ? 'btn-primary' : 'btn-outline'])>{{ t('vacancies.statuses.'.$s) }} · {{ $counts[$s] ?? 0 }}</a>
        @endforeach
        @endif
        <form method="GET" class="ml-auto flex gap-2">
            <input type="hidden" name="view" value="{{ $view }}">
            @if (! empty($filters['status']))<input type="hidden" name="status" value="{{ $filters['status'] }}">@endif
            <input type="search" name="q" value="{{ $filters['q'] ?? '' }}" class="input w-64" placeholder="{{ t('vacancies.search') }}" aria-label="{{ t('vacancies.search') }}">
        </form>
    </div>

    @if ($view === 'kanban')
        @include('vacancies.partials.board')
    @elseif ($items->isEmpty())
        <div class="card"><x-empty icon="briefcase" :title="t('vacancies.empty')">{{ t('vacancies.emptyText') }}</x-empty></div>
    @else
        <x-table>
            <thead><tr>
                <th>{{ t('vacancies.date') }}</th><th>{{ t('vacancies.candidate') }}</th><th>{{ t('vacancies.vacancy') }}</th><th>{{ t('vacancies.message') }}</th><th>{{ t('vacancies.status') }}</th><th>{{ t('vacancies.note') }}</th>@if ($canManage)<th></th>@endif
            </tr></thead>
            <tbody>
            @foreach ($items as $a)
                <tr>
                    <td class="whitespace-nowrap text-sm">{{ d($a->created_at) }}</td>
                    <td class="font-medium"><a class="link" href="/vacancies/{{ $a->id }}">{{ $a->name }}</a>@if ($a->score !== null) <x-badge :color="['excellent' => 'green', 'strong' => 'blue', 'medium' => 'yellow', 'weak' => 'red'][$a->score_level] ?? 'gray'">{{ $a->score }}</x-badge>@endif
                        <span class="block text-xs font-normal"><a class="link" href="tel:{{ $a->phone }}">{{ $a->phone }}</a>@if ($a->email) · <a class="link" href="mailto:{{ $a->email }}">{{ $a->email }}</a>@endif</span></td>
                    <td>{{ $a->vacancy_name ?: '—' }}@if ($a->page_url)<a class="block text-xs link" href="{{ $a->page_url }}" target="_blank" rel="noopener noreferrer">{{ t('vacancies.page') }}</a>@endif</td>
                    <td class="max-w-xs text-sm text-muted-foreground">{{ $a->message ?: '—' }}</td>
                    @if ($canManage)
                        <td><form method="POST" action="/vacancies/{{ $a->id }}" id="vac-{{ $a->id }}">@csrf @method('PATCH')</form><select name="status" form="vac-{{ $a->id }}" class="input" aria-label="{{ t('vacancies.status') }}" onchange="this.form.submit()">
                            @foreach (\App\Services\Vacancies\VacancyService::STATUSES as $s)<option value="{{ $s }}" @selected($a->status === $s)>{{ t('vacancies.statuses.'.$s) }}</option>@endforeach
                        </select></td>
                        <td><textarea name="comment" form="vac-{{ $a->id }}" rows="2" class="input min-w-48" aria-label="{{ t('vacancies.note') }}" onchange="this.form.submit()">{{ $a->comment }}</textarea></td>
                        <td class="whitespace-nowrap">
                            <form method="POST" action="/vacancies/{{ $a->id }}" onsubmit="return confirm(@js(t('vacancies.deleteConfirm')))">@csrf @method('DELETE')
                                <button class="btn btn-ghost btn-icon" aria-label="{{ t('common.delete') }}"><x-icon name="trash" /></button></form>
                        </td>
                    @else
                        <td><x-badge :color="$colors[$a->status]">{{ t('vacancies.statuses.'.$a->status) }}</x-badge></td>
                        <td class="max-w-xs text-sm text-muted-foreground">{{ $a->comment ?: '—' }}</td>
                    @endif
                </tr>
            @endforeach
            </tbody>
        </x-table>
        <div class="mt-4">{{ $items->links('vendor.pagination.fluggi') }}</div>
    @endif
@endsection
