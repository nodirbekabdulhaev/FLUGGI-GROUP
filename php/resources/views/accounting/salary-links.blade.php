@extends('layouts.app')
@section('title', t('accounting.salaryLinks.title'))
@section('content')
    <x-page-header :title="t('accounting.salaryLinks.title')" :subtitle="t('accounting.salaryLinks.subtitle')">
        <form method="GET"><input type="number" name="year" value="{{ $year }}" min="2000" max="2100" class="input w-28" aria-label="{{ t('accounting.taxes.yearly.year') }}" onchange="this.form.submit()"></form>
        <a href="/accounting/payroll" class="btn btn-outline">{{ t('accounting.tabs.payroll') }}</a>
    </x-page-header>
    @include('accounting.partials.tabs', ['active' => '/accounting/payroll'])

    <p class="mb-3 text-sm text-muted-foreground">{{ t('accounting.salaryLinks.how', ['total' => $plan['total'], 'withMonth' => $plan['withMonth']]) }}</p>
    @if (! $plan['rows'])
        <div class="card"><x-empty icon="check" :title="t('accounting.salaryLinks.empty')">{{ t('accounting.salaryLinks.emptyText') }}</x-empty></div>
    @else
        <form method="POST" action="/accounting/payroll/link" x-data="{ all: true }">
            @csrf
            <input type="hidden" name="year" value="{{ $year }}">
            <x-table>
                <thead><tr>
                    <th class="w-8"><input type="checkbox" x-model="all" @change="$root.querySelectorAll('input[name=\'ids[]\']').forEach(c => c.checked = all)" aria-label="{{ t('accounting.salaryLinks.all') }}"></th>
                    <th>{{ t('accounting.fields.date') }}</th><th>{{ t('accounting.fields.counterparty') }}</th><th>{{ t('accounting.salaryLinks.text') }}</th><th class="text-right">{{ t('accounting.fields.amount') }}</th>
                    <th>{{ t('accounting.salaryLinks.month') }}</th><th>{{ t('accounting.fields.person') }}</th>
                </tr></thead>
                <tbody>
                @foreach ($plan['rows'] as $r)
                    <tr class="align-top">
                        <td><input type="checkbox" name="ids[]" value="{{ $r['id'] }}" checked></td>
                        <td class="whitespace-nowrap">{{ d($r['date']) }}</td>
                        <td class="max-w-48 break-words">{{ $r['counterparty'] ?: '—' }}</td>
                        <td class="max-w-96 break-words text-xs text-muted-foreground">{{ $r['text'] }}</td>
                        <td class="whitespace-nowrap text-right tabular-nums">{{ money($r['amount'], $r['currency']) }}</td>
                        <td class="whitespace-nowrap">@if ($r['changePeriod'])<span class="text-muted-foreground">{{ $r['oldPeriod'] ?: '—' }}</span> → <span class="font-medium">{{ $r['newPeriod'] }}</span>@if (! $r['fromText'])<span class="block text-xs text-muted-foreground">{{ t('accounting.salaryLinks.byDate') }}</span>@endif @else {{ $r['newPeriod'] }} @endif</td>
                        <td>{{ $r['newPerson'] ?? $r['person'] ?? '—' }}@if ($r['newPerson'])<span class="block text-xs text-primary">{{ t('accounting.salaryLinks.byName') }}</span>@endif</td>
                    </tr>
                @endforeach
                </tbody>
            </x-table>
            <div class="mt-4 flex justify-end"><button class="btn btn-primary"><x-icon name="check" /> {{ t('accounting.salaryLinks.apply') }}</button></div>
        </form>
    @endif
@endsection
