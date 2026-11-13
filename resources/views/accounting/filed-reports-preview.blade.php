@extends('layouts.app')
@section('title', t('accounting.filed.previewTitle'))
@section('content')
    @php
        use Brick\Math\BigDecimal;
        $new = collect($plan['people'])->where('exists', false)->count();
        $upd = collect($plan['people'])->where('exists', true)->count();
    @endphp
    <x-page-header :title="t('accounting.filed.previewTitle')" :subtitle="t('accounting.filed.previewSubtitle', ['months' => count($plan['months']), 'from' => $plan['first'] ?? '—', 'to' => $plan['last'] ?? '—'])">
        <form method="POST" action="/accounting/filed-reports/{{ $token }}/cancel">@csrf<button class="btn btn-outline">{{ t('common.cancel') }}</button></form>
        <form method="POST" action="/accounting/filed-reports/{{ $token }}/confirm">@csrf<button class="btn btn-primary"><x-icon name="check" /> {{ t('accounting.filed.confirm') }}</button></form>
    </x-page-header>
    @include('accounting.partials.tabs', ['active' => '/accounting/filed-reports'])

    <div class="mb-4 grid gap-3 sm:grid-cols-4">
        <x-stat :label="t('accounting.filed.months')" :value="count($plan['months'])" />
        <x-stat :label="t('accounting.filed.newPeople')" :value="$new" />
        <x-stat :label="t('accounting.filed.updatedPeople')" :value="$upd" />
        <x-stat :label="t('accounting.filed.noIncomePeople')" :value="$plan['noIncomePeople']" :hint="t('accounting.filed.noIncomeHint')" />
    </div>
    @foreach ($data['errors'] as $e)<p class="mb-2 flex items-center gap-2 text-sm text-danger"><x-icon name="alert" />{{ $e }}</p>@endforeach
    @if ($plan['gaps'])<p class="mb-2 flex items-center gap-2 text-sm text-warning"><x-icon name="alert" />{{ t('accounting.filed.gaps', ['months' => implode(', ', $plan['gaps'])]) }}</p>@endif
    @foreach ($plan['warnings'] as $w)
        <p class="mb-2 flex items-center gap-2 text-sm text-warning"><x-icon name="alert" />{{ t('accounting.filed.mismatch', ['month' => \App\Services\Accounting\Accounting::monthLabel($w['period']), 'filed' => money($w['filed']), 'due' => money($w['due'])]) }}</p>
    @endforeach

    @if ($plan['acts'])
        <x-table class="mb-4">
            <caption class="px-3 pb-2 pt-3 text-left text-sm font-semibold">{{ t('accounting.filed.actsTitle') }}</caption>
            <thead><tr><th>{{ t('accounting.taxes.yearly.year') }}</th><th>{{ t('accounting.taxes.tax') }}</th><th class="text-right">{{ t('accounting.filed.actDue') }}</th><th class="text-right">{{ t('accounting.filed.actPaid') }}</th><th class="text-right">{{ t('accounting.filed.actBalance') }}</th></tr></thead>
            <tbody>
            @foreach ($plan['acts'] as $year => $a)
                @foreach (\App\Services\Accounting\FiledReports::ACT_CODES as $code => $cat)
                    @php $r = $a['rows'][(string) $code] ?? null; @endphp
                    @if ($r)
                        <tr>
                            <td class="whitespace-nowrap font-medium">@if ($loop->first){{ $year }}<span class="block text-xs font-normal text-muted-foreground">{{ $a['yearEnd'] ? t('accounting.filed.onYearEnd') : t('accounting.filed.onDate', ['date' => \Carbon\CarbonImmutable::parse($a['asOf'])->format('d.m.Y')]) }}</span>@endif</td>
                            <td>{{ t('accounting.category.'.$cat) }}</td>
                            <td class="whitespace-nowrap text-right tabular-nums">{{ money($r['accrued']) }}</td>
                            <td class="whitespace-nowrap text-right tabular-nums">{{ money($r['paid']) }}</td>
                            <td class="whitespace-nowrap text-right tabular-nums">{{ money((string) BigDecimal::of($r['closingDebt'])->minus($r['closingOverpay'])) }}</td>
                        </tr>
                    @endif
                @endforeach
            @endforeach
            </tbody>
        </x-table>
    @endif
    <x-table class="mb-4">
        <caption class="px-3 pb-2 pt-3 text-left text-sm font-semibold">{{ t('accounting.filed.peopleTitle') }}</caption>
        <thead><tr><th>{{ t('accounting.filed.person') }}</th><th>PINFL</th><th>{{ t('accounting.filed.position') }}</th><th>{{ t('accounting.filed.hired') }}</th><th>{{ t('accounting.filed.fired') }}</th><th class="text-right">{{ t('accounting.filed.salary') }}</th><th class="text-right">{{ t('accounting.filed.paidMonths') }}</th><th></th></tr></thead>
        <tbody>
        @foreach ($plan['people'] as $p)
            <tr>
                <td class="font-medium">{{ $p['exists'] ? $p['existingName'] : $p['name'] }}@if ($p['exists'] && $p['existingName'] !== $p['name'])<span class="block text-xs font-normal text-muted-foreground">{{ $p['name'] }}</span>@endif</td>
                <td class="font-mono text-xs">{{ $p['pinfl'] }}</td>
                <td>{{ $p['position'] ?: '—' }}</td>
                <td class="whitespace-nowrap">{{ \Carbon\CarbonImmutable::parse($p['hired'])->format('d.m.Y') }}</td>
                <td class="whitespace-nowrap">{{ $p['fired'] ? \Carbon\CarbonImmutable::parse($p['fired'])->format('d.m.Y') : '—' }}</td>
                <td class="whitespace-nowrap text-right tabular-nums">{{ money($p['salary']) }}@if (count($p['history']) > 1)<span class="block text-xs font-normal text-muted-foreground">{{ t('accounting.filed.changes', ['count' => count($p['history']) - 1]) }}</span>@endif</td>
                <td class="text-right tabular-nums">{{ $p['months'] }}</td>
                <td><x-badge :color="$p['exists'] ? 'blue' : 'green'">{{ $p['exists'] ? t('accounting.filed.willUpdate') : t('accounting.filed.willCreate') }}</x-badge></td>
            </tr>
        @endforeach
        </tbody>
    </x-table>

    <x-table>
        <caption class="px-3 pb-2 pt-3 text-left text-sm font-semibold">{{ t('accounting.filed.monthsTitle') }}</caption>
        <thead><tr><th>{{ t('accounting.fields.month') }}</th><th>{{ t('accounting.filed.file') }}</th><th class="text-right">{{ t('accounting.filed.people') }}</th><th class="text-right">{{ t('accounting.filed.income') }}</th>
            <th class="text-right">{{ t('accounting.filed.ndflYtd') }}</th><th class="text-right">{{ t('accounting.filed.due') }}</th></tr></thead>
        <tbody>
        @foreach ($plan['months'] as $p => $m)
            <tr>
                <td class="whitespace-nowrap font-medium">{{ \App\Services\Accounting\Accounting::monthLabel($p) }}</td>
                <td class="max-w-56 truncate text-xs text-muted-foreground" title="{{ $m['file'] }}">{{ $m['file'] }}</td>
                <td class="text-right tabular-nums">{{ $m['people'] }}</td>
                <td class="whitespace-nowrap text-right tabular-nums">{{ money((string) $m['income']) }}</td>
                <td class="whitespace-nowrap text-right tabular-nums">{{ money((string) $m['ndflYtd']) }}</td>
                <td @class(['whitespace-nowrap text-right font-medium tabular-nums', 'text-warning' => $m['mismatch']])>{{ money((string) $m['due']) }}</td>
            </tr>
        @endforeach
        </tbody>
    </x-table>
    <p class="mt-3 text-xs text-muted-foreground">{{ t('accounting.filed.previewHint') }}</p>
@endsection
