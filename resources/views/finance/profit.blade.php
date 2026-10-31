@extends('layouts.app')
@section('title', t('finance.profit.title'))
@section('content')
    <x-page-header :title="t('finance.profit.title')" :subtitle="t('finance.profit.subtitle')" />
    <div class="card">
        <form method="GET" class="flex gap-2 border-b p-4">
            <input type="search" name="q" value="{{ $q }}" class="input sm:max-w-xs" placeholder="{{ t('projects.search') }}" aria-label="{{ t('projects.search') }}">
            <button class="btn btn-outline">{{ t('common.search') }}</button>
        </form>
        @if ($projects->isEmpty())
            <x-empty icon="trending" :title="t('finance.profit.empty')" />
        @else
            <div class="overflow-x-auto">
                <table class="table">
                    <thead><tr>
                        <th>{{ t('projects.col.project') }}</th><th>{{ t('projects.col.status') }}</th>
                        <th class="text-right">{{ t('finance.project.revenue') }}</th><th class="text-right">{{ t('finance.project.collected') }}</th>
                        <th class="text-right">{{ t('finance.project.expenses') }}</th><th class="text-right">{{ t('finance.project.grossProfit') }}</th>
                        <th class="text-right">{{ t('finance.project.margin') }}</th><th class="text-right">{{ t('finance.project.commissions') }}</th><th class="text-right">{{ t('finance.project.overhead') }}</th>
                        <th class="text-right">{{ t('finance.project.salaries') }}</th><th class="text-right">{{ t('finance.project.netProfit') }}</th>
                    </tr></thead>
                    <tbody>
                    @foreach ($projects as $p)
                        @php($f = $p->fin)
                        <tr>
                            <td><a href="/projects/{{ $p->id }}?tab=finance" class="font-medium hover:underline">{{ \App\Support\Format::code('P', $p->number) }} · {{ $p->name }}</a>
                                <span class="block text-xs text-muted-foreground">{{ $p->client?->name }}</span></td>
                            <td>@include('projects.partials.project-status', ['status' => $p->status])</td>
                            <td class="whitespace-nowrap text-right tabular-nums">{{ money($f['revenueUzs']) }}</td>
                            <td class="whitespace-nowrap text-right tabular-nums">{{ money($f['collectedUzs']) }}</td>
                            <td class="whitespace-nowrap text-right tabular-nums">{{ money($f['expensesUzs']) }}</td>
                            <td @class(['whitespace-nowrap text-right font-semibold tabular-nums', 'text-danger' => str_starts_with($f['grossProfitUzs'], '-')])>{{ money($f['grossProfitUzs']) }}</td>
                            <td class="text-right tabular-nums">{{ $f['marginPct'] === null ? '—' : $f['marginPct'].'%' }}</td>
                            <td class="whitespace-nowrap text-right tabular-nums">{{ money($f['commissionsUzs']) }}</td>
                            <td class="whitespace-nowrap text-right tabular-nums">{{ money($f['overheadUzs']) }}</td>
                            <td class="whitespace-nowrap text-right tabular-nums">{{ money($f['salaryUzs']) }}</td>
                            <td @class(['whitespace-nowrap text-right tabular-nums', 'text-danger' => str_starts_with($f['netProfitUzs'], '-')])>{{ money($f['netProfitUzs']) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <div class="border-t px-3 py-2">{{ $projects->links('vendor.pagination.fluggi') }}</div>
        @endif
    </div>
@endsection
