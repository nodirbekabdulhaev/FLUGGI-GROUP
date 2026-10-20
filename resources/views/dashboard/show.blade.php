@extends('layouts.app')
@section('title', t('nav.dashboard'))
@section('content')
    @php
        use App\Support\Format;
        $user = $access->user;
        $first = explode(' ', trim($user->full_name))[0] ?: $user->full_name;
        $team = $user->team?->name;
        $revenueTarget = fn ($row) => collect($row['targets'])->firstWhere('metric', 'REVENUE');
    @endphp
    <x-page-header :title="t('dashboard.greeting', ['name' => $first])" :subtitle="t('dashboard.subtitle', ['role' => t('roles.'.$access->roleCode), 'team' => $team ?? 'none'])">
        @include('dashboard.partials.period')
    </x-page-header>

    <div class="grid gap-8">
        @isset($d['ceo'])
            @php($c = $d['ceo'])
            <section class="grid gap-3">
                <h2 class="font-semibold">{{ t('dash.ceoTitle') }}</h2>
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    @include('dashboard.partials.stat', ['label' => t('dash.revenue'), 'value' => money($c['revenueUzs']), 'href' => '/finance/revenue'])
                    @include('dashboard.partials.stat', ['label' => t('dash.profit'), 'value' => money($c['profitUzs']), 'href' => '/finance/profit'])
                    @include('dashboard.partials.stat', ['label' => t('dash.paid'), 'value' => money($c['paidUzs']), 'href' => '/finance/payments'])
                    @include('dashboard.partials.stat', ['label' => t('dash.expected'), 'value' => money($c['expectedUzs'])])
                    @include('dashboard.partials.stat', ['label' => t('dash.newLeads'), 'value' => $c['newLeads'], 'href' => '/sales/leads'])
                    @include('dashboard.partials.stat', ['label' => t('dash.newDeals'), 'value' => $c['newDeals'], 'href' => '/sales/deals'])
                    @include('dashboard.partials.stat', ['label' => t('dash.contracts'), 'value' => $c['contracts'], 'href' => '/sales/contracts'])
                    @include('dashboard.partials.stat', ['label' => t('dash.projectsInProgress'), 'value' => $c['projectsInProgress'], 'href' => '/projects/active'])
                </div>
            </section>
        @endisset

        @isset($d['team'])
            @php($tm = $d['team'])
            <section class="grid gap-3">
                <h2 class="font-semibold">{{ t('dash.teamTitle', ['team' => $tm['team']?->name ?? '']) }}</h2>
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="card card-body grid content-start gap-1">
                        <p class="text-sm text-muted-foreground">{{ t('dash.teamSales') }}</p>
                        <p class="text-2xl font-semibold tabular-nums">{{ money($tm['kpi']['teamRevenueUzs']) }}</p>
                        <div class="text-xs text-muted-foreground">
                            @if ((float) $tm['kpi']['planUzs'] > 0)
                                {{ t('dash.target') }}: {{ money($tm['kpi']['planUzs']) }} @include('people.partials.pct-bar', ['pct' => $tm['kpi']['planPct'], 'class' => 'mt-1'])
                            @else
                                {{ t('dash.noTarget') }}
                            @endif
                        </div>
                    </div>
                    @include('dashboard.partials.stat', ['label' => t('dash.leads'), 'value' => $tm['leads']])
                    @include('dashboard.partials.stat', ['label' => t('dash.meetings'), 'value' => $tm['meetings']])
                    @include('dashboard.partials.stat', ['label' => t('dash.proposals'), 'value' => $tm['proposals']])
                    @include('dashboard.partials.stat', ['label' => t('dash.contracts'), 'value' => $tm['contracts']])
                    @include('dashboard.partials.stat', ['label' => t('dash.payments'), 'value' => $tm['payments']])
                    @include('dashboard.partials.stat', ['label' => t('dash.avgCheck'), 'value' => money($tm['kpi']['avgCheckUzs'])])
                    @include('dashboard.partials.stat', ['label' => t('dash.conversion'), 'value' => $tm['kpi']['conversionPct'] ? $tm['kpi']['conversionPct'].'%' : '—'])
                </div>
                <x-card :title="t('dash.managersTable')" :padding="false">
                    <div class="overflow-x-auto"><table class="table">
                        <thead><tr><th>{{ t('dash.manager') }}</th><th class="text-right">{{ t('dash.leads') }}</th><th class="text-right">{{ t('dash.deals') }}</th><th class="text-right">{{ t('dash.sales') }}</th><th>KPI</th></tr></thead>
                        <tbody>
                        @foreach ($tm['managers'] as $m)
                            <tr>
                                <td class="font-medium">{{ $m['user']->full_name }}</td>
                                <td class="text-right tabular-nums">{{ $m['manager']['leads'] ?? 0 }}</td>
                                <td class="text-right tabular-nums">{{ $m['manager']['orders'] ?? 0 }}</td>
                                <td class="whitespace-nowrap text-right tabular-nums">{{ money($m['manager']['revenueUzs'] ?? 0) }}</td>
                                <td>@include('people.partials.pct-bar', ['pct' => $m['kpiPct']])</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table></div>
                </x-card>
            </section>
        @endisset

        @if (isset($d['own']) && $d['own']['kpi']['manager'])
            @php($own = $d['own'])
            @php($mk = $own['kpi']['manager'])
            @php($rt = $revenueTarget($own['kpi']))
            <section class="grid gap-3">
                <h2 class="font-semibold">{{ t('dash.ownTitle') }}</h2>
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    @include('dashboard.partials.stat', ['label' => t('dash.myLeads'), 'value' => $mk['leads'], 'href' => '/sales/leads'])
                    @include('dashboard.partials.stat', ['label' => t('dash.myDeals'), 'value' => $own['deals'], 'href' => '/sales/deals'])
                    @include('dashboard.partials.stat', ['label' => t('dash.myMeetings'), 'value' => $mk['meetings'], 'href' => '/sales/meetings'])
                    @include('dashboard.partials.stat', ['label' => t('dash.myProposals'), 'value' => $mk['proposals'], 'href' => '/sales/proposals'])
                    @include('dashboard.partials.stat', ['label' => t('dash.mySales'), 'value' => money($mk['revenueUzs']), 'hint' => $mk['orders'].' · '.t('dash.avgCheck').' '.Format::moneyShort($mk['avgCheckUzs'])])
                    <div class="card card-body grid content-start gap-1">
                        <p class="text-sm text-muted-foreground">{{ t('dash.myTarget') }}</p>
                        <p class="text-2xl font-semibold tabular-nums">{{ $rt ? Format::moneyShort($rt['target'], $rt['currency']) : '—' }}</p>
                        @include('people.partials.pct-bar', ['pct' => $rt['pct'] ?? null])
                    </div>
                    @include('dashboard.partials.stat', ['label' => t('dash.myKpi'), 'value' => $own['kpi']['kpiPct'] ? $own['kpi']['kpiPct'].'%' : '—', 'href' => '/kpi', 'hint' => t('dash.openKpi')])
                    @include('dashboard.partials.stat', ['label' => t('dash.myCommission'), 'value' => money($own['commissionUzs']), 'href' => '/finance/commissions'])
                    @include('dashboard.partials.stat', ['label' => t('dash.myTasks'), 'value' => $own['tasksOpen'], 'href' => '/tasks', 'hint' => $own['tasksOverdue'] ? t('dash.tasksOverdue', ['count' => $own['tasksOverdue']]) : null, 'danger' => $own['tasksOverdue'] > 0])
                    @include('people.partials.attendance-card', ['today' => $d['attendance']])
                </div>
            </section>
        @endif

        @isset($d['executor'])
            @php($ex = $d['executor'])
            <section class="grid gap-3">
                <h2 class="font-semibold">{{ t('dash.executorTitle') }}</h2>
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    @include('dashboard.partials.stat', ['label' => t('dash.projects'), 'value' => $ex['projects'], 'href' => '/projects/active'])
                    @include('dashboard.partials.stat', ['label' => t('dash.today'), 'value' => $ex['today'], 'href' => '/tasks?view=today'])
                    @include('dashboard.partials.stat', ['label' => t('dash.overdue'), 'value' => $ex['overdue'], 'href' => '/tasks?view=overdue', 'danger' => $ex['overdue'] > 0])
                    @include('dashboard.partials.stat', ['label' => t('dash.inProgress'), 'value' => $ex['inProgress'], 'href' => '/tasks?view=in_progress'])
                    @include('dashboard.partials.stat', ['label' => t('dash.done'), 'value' => $ex['done']])
                </div>
            </section>
        @endisset

        @isset($d['projects'])
            @php($pm = $d['projects'])
            <section class="grid gap-3">
                <h2 class="font-semibold">{{ t('dash.pmTitle', ['directions' => implode(', ', $pm['directions']) ?: '—']) }}</h2>
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    @include('dashboard.partials.stat', ['label' => t('dash.pmActive'), 'value' => $pm['active'], 'href' => '/projects/active'])
                    @include('dashboard.partials.stat', ['label' => t('dash.pmOverdueProjects'), 'value' => $pm['overdueProjects'], 'href' => '/projects/overdue', 'danger' => $pm['overdueProjects'] > 0])
                    @include('dashboard.partials.stat', ['label' => t('dash.pmUnassigned'), 'value' => $pm['unassigned'], 'href' => '/projects/active', 'hint' => t('dash.pmUnassignedHint'), 'danger' => $pm['unassigned'] > 0])
                    @include('dashboard.partials.stat', ['label' => t('dash.pmCompleted'), 'value' => $pm['completed'], 'href' => '/projects/completed'])
                    @include('dashboard.partials.stat', ['label' => t('dash.pmTasksOpen'), 'value' => $pm['tasksOpen'], 'href' => '/tasks'])
                    @include('dashboard.partials.stat', ['label' => t('dash.pmTasksToday'), 'value' => $pm['tasksToday'], 'href' => '/tasks?view=today'])
                    @include('dashboard.partials.stat', ['label' => t('dash.pmTasksOverdue'), 'value' => $pm['tasksOverdue'], 'href' => '/tasks?view=overdue', 'danger' => $pm['tasksOverdue'] > 0])
                </div>
            </section>
        @endisset

        @if (! empty($d['myKpi']))
            @include('dashboard.partials.my-kpi', ['k' => $d['myKpi']])
        @endif

        @if (! isset($d['own']) && $d['attendance'])
            <section class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">@include('people.partials.attendance-card', ['today' => $d['attendance']])</section>
        @endif

        @if (! isset($d['ceo']) && ! isset($d['team']) && ! isset($d['own']) && ! isset($d['executor']) && ! isset($d['projects']))
            <x-card><p class="text-sm text-muted-foreground">{{ t('dashboard.accessText') }}</p></x-card>
        @endif
    </div>
@endsection
