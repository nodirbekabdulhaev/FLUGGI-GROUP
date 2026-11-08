@extends('layouts.app')
@section('title', t('accounting.taxReport.title'))
@section('content')
    @php
        $slotsShown = max(1, $slots);
        $rowWarnings = collect($rows)->filter(fn ($r) => $r['warnings'] || $r['mismatch'])->count();
    @endphp
    <x-page-header :title="t('accounting.taxReport.title')" :subtitle="t('accounting.taxReport.subtitle')">
        <form method="GET"><input type="month" name="period" value="{{ $period }}" class="input w-44" aria-label="{{ t('accounting.fields.month') }}" onchange="this.form.submit()"></form>
    </x-page-header>
    @include('accounting.partials.tabs', ['active' => '/accounting/tax-report'])

    <div class="mb-4 grid gap-3 sm:grid-cols-4">
        <x-stat :label="t('accounting.taxReport.people')" :value="count($rows)" />
        <x-stat :label="t('accounting.taxReport.monthIncome')" :value="money($totals['monthIncome'])" :hint="t('accounting.taxReport.ytd').': '.money($totals['ytdIncome'])" />
        <x-stat :label="t('accounting.taxReport.monthNdfl')" :value="money($totals['monthNdfl'])" :hint="t('accounting.taxReport.ytd').': '.money($totals['ytdNdfl'])" />
        <x-stat :label="t('accounting.taxReport.monthExempt')" :value="money($totals['monthExempt'])" :hint="t('accounting.taxReport.ytd').': '.money($totals['ytdExempt'])" />
    </div>

    <x-card class="mb-4">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div class="text-sm">
                <p class="font-medium">{{ t('accounting.taxReport.file') }}: <span class="break-all font-mono text-xs">{{ $filename }}</span></p>
                <p class="text-muted-foreground">{{ t('accounting.taxReport.fileHint') }}</p>
            </div>
            @if ($canDownload)
                <a href="/accounting/tax-report/download?period={{ $period }}" @class(['btn btn-primary h-12 shrink-0 px-6 text-base', 'pointer-events-none opacity-50' => ! $rows]) @if (! $rows) aria-disabled="true" @endif>
                    <x-icon name="download" /> {{ t('accounting.taxReport.download') }}</a>
            @else
                <p class="text-sm text-muted-foreground">{{ t('accounting.taxReport.noDownload') }}</p>
            @endif
        </div>
    </x-card>

    @foreach ($warnings as $w)
        <p class="mb-2 flex items-center gap-2 text-sm text-warning"><x-icon name="alert" />{{ t('accounting.taxReport.warnings.'.$w) }}
            @if ($canManage && in_array($w, ['innMissing', 'codeMissing'], true))<a class="link" href="/accounting/settings">{{ t('accounting.tabs.settings') }}</a>@endif</p>
    @endforeach
    @if ($drafts)<p class="mb-2 flex items-center gap-2 text-sm text-warning"><x-icon name="alert" />{{ t('accounting.taxes.draftsWarning', ['count' => $drafts]) }}</p>@endif
    @if ($rowWarnings)<p class="mb-2 flex items-center gap-2 text-sm text-warning"><x-icon name="alert" />{{ t('accounting.taxReport.rowWarnings', ['count' => $rowWarnings]) }}</p>@endif

    @if (! $rows)
        <div class="card"><x-empty icon="file" :title="t('accounting.taxReport.empty')">{{ t('accounting.taxReport.emptyText') }} <a class="link" href="/accounting/people">{{ t('accounting.tabs.people') }}</a></x-empty></div>
    @else
        <x-table>
            <thead>
            <tr class="align-bottom text-xs">
                <th>№</th><th class="min-w-48">{{ t('accounting.taxReport.cols.name') }}</th><th>{{ t('accounting.taxReport.cols.position') }}</th><th>{{ t('accounting.taxReport.cols.pinfl') }}</th>
                <th>{{ t('accounting.taxReport.cols.workplaceDate') }}</th><th>{{ t('accounting.taxReport.cols.resident') }}</th><th>{{ t('accounting.taxReport.cols.status') }}</th>
                <th>{{ t('accounting.taxReport.cols.contract') }}</th><th>{{ t('accounting.taxReport.cols.rate') }}</th><th>{{ t('accounting.taxReport.cols.workplace') }}</th>
                <th class="text-right">{{ t('accounting.taxReport.cols.ytdIncome') }}</th><th class="text-right">{{ t('accounting.taxReport.cols.monthIncome') }}</th>
                <th class="text-right">{{ t('accounting.taxReport.cols.ytdNdfl') }}</th><th class="text-right">{{ t('accounting.taxReport.cols.monthNdfl') }}</th>
                @for ($k = 0; $k < $slotsShown; $k++)
                    <th>{{ t('accounting.taxReport.cols.benefitCode') }}</th><th class="text-right">{{ t('accounting.taxReport.cols.benefitYtd') }}</th><th class="text-right">{{ t('accounting.taxReport.cols.benefitMonth') }}</th>
                @endfor
            </tr>
            <tr class="text-xs text-muted-foreground">
                @for ($c = 1; $c <= 14 + 3 * $slotsShown; $c++)<th @class(['text-right' => $c > 10])>{{ $c }}</th>@endfor
            </tr>
            </thead>
            <tbody>
            <tr class="bg-muted/40 font-semibold">
                <td colspan="10" class="text-right">{{ t('accounting.taxReport.total') }}</td>
                <td class="whitespace-nowrap text-right tabular-nums">{{ money($totals['ytdIncome']) }}</td>
                <td class="whitespace-nowrap text-right tabular-nums">{{ money($totals['monthIncome']) }}</td>
                <td class="whitespace-nowrap text-right tabular-nums">{{ money($totals['ytdNdfl']) }}</td>
                <td class="whitespace-nowrap text-right tabular-nums">{{ money($totals['monthNdfl']) }}</td>
                @for ($k = 0; $k < $slotsShown; $k++)
                    <td></td>
                    <td class="whitespace-nowrap text-right tabular-nums">{{ isset($totals['benefits'][$k]) ? money($totals['benefits'][$k]['ytd']) : '' }}</td>
                    <td class="whitespace-nowrap text-right tabular-nums">{{ isset($totals['benefits'][$k]) ? money($totals['benefits'][$k]['month']) : '' }}</td>
                @endfor
            </tr>
            @foreach ($rows as $i => $r)
                <tr>
                    <td class="tabular-nums">{{ $i + 1 }}</td>
                    <td>
                        @if ($canManage)<a class="link font-medium" href="/accounting/people?edit={{ $r['personId'] }}">{{ $r['name'] }}</a>@else<span class="font-medium">{{ $r['name'] }}</span>@endif
                        @foreach ($r['warnings'] as $w)<span class="mt-0.5 block"><x-badge color="yellow">{{ t('accounting.taxReport.rowWarning.'.$w) }}</x-badge></span>@endforeach
                        @if ($r['mismatch'])<span class="mt-0.5 block"><x-badge color="yellow">{{ t('accounting.taxReport.rowWarning.ndflMismatch', ['months' => implode(', ', $r['mismatch'])]) }}</x-badge></span>@endif
                        @if ($r['fromSalary'])<span class="block text-xs text-muted-foreground">{{ t('accounting.taxReport.fromSalary', ['count' => $r['fromSalary']]) }}</span>@endif
                    </td>
                    <td>{{ $r['position'] !== '' ? $r['position'] : '—' }}</td>
                    <td class="whitespace-nowrap tabular-nums">{{ $r['pinfl'] !== '' ? $r['pinfl'] : '—' }}</td>
                    <td class="whitespace-nowrap tabular-nums">{{ $r['workplaceDate'] }}</td>
                    <td class="tabular-nums">{{ $r['resident'] }}</td>
                    <td class="tabular-nums">{{ $r['status'] }}</td>
                    <td class="tabular-nums">{{ $r['contract'] }}</td>
                    <td class="tabular-nums">{{ $r['rate'] }}</td>
                    <td class="tabular-nums">{{ $r['workplaceType'] }}</td>
                    <td class="whitespace-nowrap text-right tabular-nums">{{ money($r['ytdIncome']) }}</td>
                    <td class="whitespace-nowrap text-right tabular-nums">{{ money($r['monthIncome']) }}</td>
                    <td class="whitespace-nowrap text-right tabular-nums">{{ money($r['ytdNdfl']) }}</td>
                    <td class="whitespace-nowrap text-right tabular-nums">{{ money($r['monthNdfl']) }}</td>
                    @for ($k = 0; $k < $slotsShown; $k++)
                        @php $b = $r['benefits'][$k] ?? null; @endphp
                        <td class="tabular-nums">{{ $b['code'] ?? '' }}</td>
                        <td class="whitespace-nowrap text-right tabular-nums">{{ $b ? money($b['ytd']) : '' }}</td>
                        <td class="whitespace-nowrap text-right tabular-nums">{{ $b ? money($b['month']) : '' }}</td>
                    @endfor
                </tr>
            @endforeach
            </tbody>
        </x-table>
    @endif
    <p class="mt-3 text-xs text-muted-foreground">{{ t('accounting.taxReport.how') }}</p>
    <p class="mt-1 text-xs text-muted-foreground">{{ t('accounting.taxReport.codes') }}</p>
@endsection
