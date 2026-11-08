@extends('layouts.app')
@section('title', t('accounting.filed.title'))
@section('content')
    @php
        use Brick\Math\BigDecimal;
        $byYear = collect($ledger['rows'])->groupBy(fn ($r) => substr($r['period'], 0, 4))->sortKeysDesc();
        $balance = BigDecimal::of($ledger['balance']);
    @endphp
    <x-page-header :title="t('accounting.filed.title')" :subtitle="t('accounting.filed.subtitle')">
        <form method="GET"><input type="month" name="to" value="{{ $to }}" class="input w-44" aria-label="{{ t('accounting.filed.to') }}" onchange="this.form.submit()"></form>
    </x-page-header>
    @include('accounting.partials.tabs', ['active' => '/accounting/filed-reports'])

    <div class="mb-4 grid gap-3 sm:grid-cols-3">
        <x-stat :label="t('accounting.filed.totalDue')" :value="money($ledger['totalDue'])" :hint="t('accounting.filed.since', ['month' => $ledger['rows'] ? \App\Services\Accounting\Accounting::monthLabel($ledger['from']) : '—'])" />
        <x-stat :label="t('accounting.filed.totalPaid')" :value="money($ledger['totalPaid'])" />
        <x-stat :label="$balance->isNegative() ? t('accounting.filed.overpaid') : t('accounting.filed.debt')" :value="money((string) $balance->abs())" :hint="t('accounting.filed.balanceHint', ['month' => \App\Services\Accounting\Accounting::monthLabel($to)])" />
    </div>

    @if ($ledger['firstFiled'] && $ledger['from'] > $ledger['firstFiled'])<p class="mb-3 flex items-center gap-2 text-sm text-warning"><x-icon name="alert" />{{ t('accounting.taxes.startLate', ['from' => \App\Services\Accounting\Accounting::monthLabel($ledger['from']), 'first' => \App\Services\Accounting\Accounting::monthLabel($ledger['firstFiled'])]) }} <a class="link" href="/accounting/settings#tax-balance">{{ t('accounting.tabs.settings') }}</a></p>@endif
    @if ($canManage)
        <x-card :title="t('accounting.filed.uploadTitle')" class="mb-4">
            <form method="POST" action="/accounting/filed-reports" enctype="multipart/form-data" class="grid gap-3 sm:grid-cols-[1fr_auto] sm:items-end">
                @csrf
                <div>
                    <label class="label" for="filed-files">{{ t('accounting.filed.files') }}<span class="text-danger"> *</span></label>
                    <input id="filed-files" type="file" name="files[]" class="input" accept=".xltx,.xlsx,.xls,.zip" multiple required>
                    @error('files')<p class="error">{{ $message }}</p>@else<p class="hint">{{ t('accounting.filed.filesHint') }}</p>@enderror
                    @error('files.*')<p class="error">{{ $message }}</p>@enderror
                </div>
                <button class="btn btn-primary"><x-icon name="upload" /> {{ t('accounting.filed.read') }}</button>
            </form>
            <ul class="mt-3 list-disc space-y-1 pl-5 text-sm text-muted-foreground">
                <li>{{ t('accounting.filed.how1') }}</li>
                <li>{{ t('accounting.filed.how2') }}</li>
                <li>{{ t('accounting.filed.how3') }}</li>
                <li>{{ t('accounting.filed.how4') }}</li>
            </ul>
        </x-card>
    @endif

    @if (! $ledger['rows'])
        <div class="card"><x-empty icon="file" :title="t('accounting.filed.empty')">{{ t('accounting.filed.emptyText') }}</x-empty></div>
    @else
        <p class="mb-2 text-sm text-muted-foreground">{{ t('accounting.filed.ledgerHint', ['month' => \App\Services\Accounting\Accounting::monthLabel($ledger['from'])]) }}
            @if ($canManage)<a class="link" href="/accounting/settings#tax-balance">{{ t('accounting.tabs.settings') }}</a>@endif</p>
        @foreach ($byYear as $year => $rows)
            @php
                $yDue = $rows->reduce(fn ($c, $r) => $c->plus($r['due']), BigDecimal::zero());
                $yPaid = $rows->reduce(fn ($c, $r) => $c->plus($r['paid']), BigDecimal::zero());
            @endphp
            <x-table class="mb-4">
                <caption class="px-3 pb-2 pt-3 text-left text-sm font-semibold">{{ $year }} · {{ t('accounting.filed.due') }} {{ money((string) $yDue) }} · {{ t('accounting.filed.paid') }} {{ money((string) $yPaid) }}</caption>
                <thead><tr>
                    <th>{{ t('accounting.fields.month') }}</th><th class="text-right">{{ t('accounting.filed.people') }}</th><th class="text-right">{{ t('accounting.filed.income') }}</th>
                    <th class="text-right">{{ t('accounting.filed.due') }}</th><th class="text-right">{{ t('accounting.filed.paid') }}</th><th class="text-right">{{ t('accounting.filed.balance') }}</th><th>{{ t('accounting.filed.source') }}</th>
                </tr></thead>
                <tbody>
                @foreach ($rows->sortByDesc('period') as $r)
                    @php $b = BigDecimal::of($r['balance']); @endphp
                    <tr>
                        <td class="whitespace-nowrap font-medium"><a class="link" href="/accounting/taxes?period={{ $r['period'] }}">{{ \App\Services\Accounting\Accounting::monthLabel($r['period']) }}</a>
                            @if ($r['reset'])<span class="block text-xs font-normal text-primary" title="{{ t('accounting.filed.resetHint') }}">{{ t('accounting.filed.reset', ['sum' => money($r['opening'])]) }}</span>@endif</td>
                        <td class="text-right tabular-nums">{{ $r['people'] ?? '—' }}</td>
                        <td class="whitespace-nowrap text-right tabular-nums">{{ money($r['income']) }}</td>
                        <td class="whitespace-nowrap text-right font-medium tabular-nums">{{ money($r['due']) }}
                            @if ($r['filedMonth'] !== null && BigDecimal::of($r['filedMonth'])->minus($r['due'])->abs()->isGreaterThan(1))<span class="block text-xs font-normal text-warning" title="{{ t('accounting.filed.mismatchHint') }}">{{ t('accounting.filed.inFile') }}: {{ money($r['filedMonth']) }}</span>@endif</td>
                        <td class="whitespace-nowrap text-right tabular-nums"><a class="link" href="/accounting/operations?category=TAX_NDFL&period={{ $r['period'] }}">{{ money($r['paid']) }}</a></td>
                        <td @class(['whitespace-nowrap text-right font-semibold tabular-nums', 'text-warning' => $b->isPositive(), 'text-success' => $b->isNegative()])>{{ money($r['balance']) }}</td>
                        <td class="text-xs text-muted-foreground">{{ t('accounting.filed.sources.'.$r['source']) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </x-table>
        @endforeach
    @endif
    @if ($compare)
        <x-table class="mt-6">
            <caption class="px-3 pb-2 pt-3 text-left text-sm font-semibold">{{ t('accounting.filed.compareTitle') }}<span class="block text-xs font-normal text-muted-foreground">{{ t('accounting.filed.compareHint') }}</span></caption>
            <thead><tr>
                <th>{{ t('accounting.taxes.yearly.year') }}</th><th>{{ t('accounting.taxes.tax') }}</th><th class="text-right">{{ t('accounting.filed.actDue') }}</th><th class="text-right">{{ t('accounting.filed.ourDue') }}</th><th class="text-right">Δ</th>
                <th class="text-right">{{ t('accounting.filed.actPaid') }}</th><th class="text-right">{{ t('accounting.filed.ourPaid') }}</th><th class="text-right">Δ</th>
                <th class="text-right">{{ t('accounting.filed.actBalance') }}</th><th class="text-right">{{ t('accounting.filed.ourBalance') }}</th>
            </tr></thead>
            <tbody>
            @foreach (collect($compare)->sortByDesc('year')->groupBy('year') as $year => $list)
                @foreach ($list as $i => $c)
                    <tr>
                        <td class="whitespace-nowrap font-medium">@if ($i === 0){{ $year }}<span class="block text-xs font-normal text-muted-foreground">{{ $c['yearEnd'] ? t('accounting.filed.onYearEnd') : t('accounting.filed.onDate', ['date' => \Carbon\CarbonImmutable::parse($c['asOf'])->format('d.m.Y')]) }}</span>@endif</td>
                        <td>{{ t('accounting.category.'.$c['cat']) }}</td>
                        <td class="whitespace-nowrap text-right tabular-nums">{{ money($c['actDue']) }}</td>
                        <td class="whitespace-nowrap text-right tabular-nums">{{ money($c['ourDue']) }}</td>
                        <td @class(['whitespace-nowrap text-right tabular-nums', 'text-warning' => BigDecimal::of($c['diffDue'])->abs()->isGreaterThan(1)])>{{ money($c['diffDue']) }}</td>
                        <td class="whitespace-nowrap text-right tabular-nums">{{ money($c['actPaid']) }}</td>
                        <td class="whitespace-nowrap text-right tabular-nums">{{ money($c['ourPaid']) }}</td>
                        <td @class(['whitespace-nowrap text-right tabular-nums', 'text-warning' => BigDecimal::of($c['diffPaid'])->abs()->isGreaterThan(1)])>{{ money($c['diffPaid']) }}</td>
                        <td class="whitespace-nowrap text-right tabular-nums">{{ money($c['actBalance']) }}</td>
                        <td @class(['whitespace-nowrap text-right font-medium tabular-nums', 'text-warning' => BigDecimal::of($c['ourBalance'])->minus($c['actBalance'])->abs()->isGreaterThan(1)])>{{ money($c['ourBalance']) }}</td>
                    </tr>
                @endforeach
            @endforeach
            </tbody>
        </x-table>
        <p class="mt-2 text-xs text-muted-foreground">{{ t('accounting.filed.compareExplain') }}</p>
    @endif
    <p class="mt-3 text-xs text-muted-foreground">{{ t('accounting.filed.explain') }}</p>
@endsection
