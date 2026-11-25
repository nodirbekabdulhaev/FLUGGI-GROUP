@extends('layouts.app')
@section('title', t('accounting.loans.title'))
@section('content')
    <x-page-header :title="t('accounting.loans.title')" :subtitle="t('accounting.loans.subtitle')">
        @if ($canManage)<button type="button" class="btn btn-primary" x-data @click="$dispatch('open-modal', 'loan-new')"><x-icon name="plus" /> {{ t('accounting.loans.new') }}</button>@endif
    </x-page-header>
    @include('accounting.partials.tabs', ['active' => '/accounting/loans'])

    @foreach ($totals as $cur => $t)
        <div class="mb-4 grid gap-3 sm:grid-cols-3">
            <x-stat :label="t('accounting.loans.leftTotal').' · '.$cur" :value="money((string) $t['left'], $cur)" :hint="t('accounting.loans.leftHint')" />
            <x-stat :label="t('accounting.loans.balance').' · '.$cur" :value="money((string) $t['balance'], $cur)" :hint="t('accounting.loans.balanceHint')" />
            <x-stat :label="t('accounting.loans.overdue')" :value="(string) $t['overdue']" :hint="t('accounting.loans.overdueHint')" />
        </div>
    @endforeach

    @if (! $rows)
        <div class="card"><x-empty icon="wallet" :title="t('accounting.loans.empty')">{{ t('accounting.loans.emptyText') }}</x-empty></div>
    @else
        <x-table>
            <thead><tr>
                <th>{{ t('accounting.loans.name') }}</th><th class="text-right">{{ t('accounting.loans.principal') }}</th><th class="text-right">{{ t('accounting.loans.balance') }}</th>
                <th class="text-right">{{ t('accounting.loans.leftTotal') }}</th><th>{{ t('accounting.loans.nextPayment') }}</th><th>{{ t('accounting.loans.progress') }}</th>
            </tr></thead>
            <tbody>
            @foreach ($rows as $r)
                @php $l = $r['loan']; @endphp
                <tr @class(['opacity-60' => ! $l->is_active])>
                    <td class="font-medium"><a class="link" href="/accounting/loans/{{ $l->id }}">{{ $l->name }}</a>
                        <span class="block text-xs font-normal text-muted-foreground">{{ $l->lender ?: '—' }}@if ($l->rate !== null) · {{ $l->rate }}%@endif @if (! $l->is_active) · {{ t('accounting.loans.closed') }}@endif</span></td>
                    <td class="whitespace-nowrap text-right tabular-nums">{{ money((string) $l->principal, $l->currency) }}</td>
                    <td class="whitespace-nowrap text-right tabular-nums">{{ money((string) $r['balance'], $l->currency) }}</td>
                    <td class="whitespace-nowrap text-right font-medium tabular-nums">{{ money((string) $r['leftTotal'], $l->currency) }}</td>
                    <td class="whitespace-nowrap">
                        @if ($r['next']){{ d($r['next']->due_date) }} · {{ money((string) $r['next']->total, $l->currency) }}
                            @if ($r['overdue'])<x-badge color="red">{{ t('accounting.loans.overdueN', ['n' => $r['overdue']]) }}</x-badge>@endif
                        @else<span class="text-muted-foreground">{{ $r['count'] ? t('accounting.loans.allPaid') : t('accounting.loans.noSchedule') }}</span>@endif</td>
                    <td class="whitespace-nowrap text-sm text-muted-foreground">{{ $r['paidCount'] }} / {{ $r['count'] }}</td>
                </tr>
            @endforeach
            </tbody>
        </x-table>
    @endif

    @if ($canManage)
        <x-modal name="loan-new" :title="t('accounting.loans.new')" size="max-w-3xl" :open="$errors->any() && old('_form') === 'loan'">
            <form method="POST" action="/accounting/loans" class="space-y-4">
                @csrf
                <input type="hidden" name="_form" value="loan">
                @include('accounting.partials.loan-form', ['loan' => null, 'owners' => $owners])
                <div>
                    <p class="label">{{ t('accounting.loans.scheduleManual') }}</p>
                    @include('accounting.partials.loan-rows')
                    <p class="hint">{{ t('accounting.loans.importLater') }}</p>
                </div>
                <div class="flex justify-end gap-2">
                    <button type="button" class="btn btn-outline" @click="$dispatch('close-modal', 'loan-new')">{{ t('common.cancel') }}</button>
                    <button class="btn btn-primary">{{ t('common.save') }}</button>
                </div>
            </form>
        </x-modal>
    @endif
@endsection
