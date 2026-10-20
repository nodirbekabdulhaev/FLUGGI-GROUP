@extends('layouts.app')
@section('title', t('partner.title'))
@section('content')
    @php($canRequest = $settings['partnerId'] !== null)
    @php($statusColor = ['REQUESTED' => 'yellow', 'APPROVED' => 'green', 'REJECTED' => 'red'])
    <div x-data="{ pid: @js(old('project_id', '')), pname: '', left: '', rid: '' }">
        <x-page-header :title="t('partner.title')" :subtitle="$partner ? t('partner.subtitle', ['name' => $partner->full_name, 'pct' => $settings['partnerPct']]) : t('partner.subtitleNone')" />

        @if (! $partner)
            <x-card>
                <x-empty icon="users" :title="t('partner.notConfigured')">
                    @if (\App\Services\Finance\PartnerService::canManageSettings())<a class="link" href="/settings/finance">{{ t('partner.configure') }}</a>@endif
                </x-empty>
            </x-card>
        @else
            <div class="mb-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <x-stat :label="t('partner.totals.share')" :value="money($totals['share'])" :hint="t('partner.totals.net').': '.money($totals['net'])" />
                <x-stat :label="t('partner.totals.paid')" :value="money($totals['paid'])" tone="good" />
                <x-stat :label="t('partner.totals.requested')" :value="money($totals['requested'])" :tone="$pending->isNotEmpty() ? 'warn' : null" />
                <x-stat :label="t('partner.totals.remaining')" :value="money($totals['remaining'])" />
            </div>

            @if ($pending->isNotEmpty())
                <x-card class="mb-6" :title="t('partner.pendingTitle', ['count' => $pending->count()])" :subtitle="$canDecide ? t('partner.pendingText') : t('partner.pendingWait')" :padding="false">
                    <div class="overflow-x-auto">
                        <table class="table">
                            <thead><tr><th>{{ t('partner.col.project') }}</th><th class="text-right">{{ t('partner.col.amount') }}</th><th>{{ t('partner.col.requestedBy') }}</th><th>{{ t('partner.col.comment') }}</th><th></th></tr></thead>
                            <tbody>
                            @foreach ($pending as $po)
                                <tr>
                                    <td><span class="font-medium">{{ \App\Support\Format::code('P', $po->project?->number) }} · {{ $po->project?->name }}</span><span class="block text-xs text-muted-foreground">{{ dt($po->created_at) }}</span></td>
                                    <td class="whitespace-nowrap text-right font-medium tabular-nums">{{ money($po->amount_uzs) }}</td>
                                    <td>{{ $po->requestedBy?->full_name }}</td>
                                    <td class="max-w-xs whitespace-pre-wrap text-sm">{{ $po->comment ?: '—' }}</td>
                                    <td class="whitespace-nowrap text-right">
                                        @if ($canDecide)
                                            <form method="POST" action="/finance/partner/payouts/{{ $po->id }}/approve" class="inline" data-confirm="{{ t('partner.approveConfirm', ['amount' => money($po->amount_uzs)]) }}">@csrf
                                                <button class="btn btn-primary btn-sm">{{ t('partner.approve') }}</button></form>
                                            <button type="button" class="btn btn-outline btn-sm" @click="rid = @js($po->id); $dispatch('open-modal', 'payout-reject')">{{ t('partner.reject') }}</button>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                </x-card>
            @endif

            <x-card class="mb-6" :title="t('partner.projectsTitle')" :subtitle="t('partner.projectsText', ['pct' => $settings['partnerPct']])" :padding="false">
                @if ($rows->isEmpty())
                    <x-empty icon="gauge" :title="t('partner.noProjects')" />
                @else
                    <div class="overflow-x-auto">
                        <table class="table">
                            <thead><tr>
                                <th>{{ t('partner.col.project') }}</th><th>{{ t('partner.col.status') }}</th>
                                <th class="text-right">{{ t('partner.col.revenue') }}</th><th class="text-right">{{ t('partner.col.netProfit') }}</th>
                                <th class="text-right">{{ t('partner.col.share') }}</th><th class="text-right">{{ t('partner.col.paid') }}</th>
                                <th class="text-right">{{ t('partner.col.remaining') }}</th><th></th>
                            </tr></thead>
                            <tbody>
                            @foreach ($rows as $p)
                                @php($r = $p->partner)
                                <tr>
                                    <td><a href="/projects/{{ $p->id }}" class="font-medium hover:underline">{{ \App\Support\Format::code('P', $p->number) }} · {{ $p->name }}</a>
                                        <span class="block text-xs text-muted-foreground">{{ $p->client?->name }}</span></td>
                                    <td>@include('projects.partials.project-status', ['status' => $p->status])</td>
                                    <td class="whitespace-nowrap text-right tabular-nums">{{ money($r['revenueUzs']) }}</td>
                                    <td @class(['whitespace-nowrap text-right tabular-nums', 'text-danger' => str_starts_with($r['netProfitUzs'], '-')])>{{ money($r['netProfitUzs']) }}</td>
                                    <td class="whitespace-nowrap text-right font-semibold tabular-nums">{{ money($r['shareUzs']) }}</td>
                                    <td class="whitespace-nowrap text-right tabular-nums">{{ money($r['paidUzs']) }}@if ($r['requestedUzs'] !== '0.00')<span class="block text-xs text-warning">{{ t('partner.onApproval') }}: {{ money($r['requestedUzs']) }}</span>@endif</td>
                                    <td class="whitespace-nowrap text-right tabular-nums">{{ money($r['remainingUzs']) }}</td>
                                    <td class="text-right">
                                        @if ($canRequest && $r['remainingUzs'] !== '0.00')
                                            <button type="button" class="btn btn-outline btn-sm whitespace-nowrap" @click="pid = @js($p->id); pname = @js(\App\Support\Format::code('P', $p->number).' · '.$p->name); left = @js(money($r['remainingUzs'])); $dispatch('open-modal', 'payout-new')">{{ t('partner.request') }}</button>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                            <tfoot><tr class="font-semibold">
                                <td colspan="2">{{ t('partner.total') }}</td>
                                <td class="whitespace-nowrap text-right tabular-nums">{{ money($totals['revenue']) }}</td>
                                <td class="whitespace-nowrap text-right tabular-nums">{{ money($totals['net']) }}</td>
                                <td class="whitespace-nowrap text-right tabular-nums">{{ money($totals['share']) }}</td>
                                <td class="whitespace-nowrap text-right tabular-nums">{{ money($totals['paid']) }}</td>
                                <td class="whitespace-nowrap text-right tabular-nums">{{ money($totals['remaining']) }}</td>
                                <td></td>
                            </tr></tfoot>
                        </table>
                    </div>
                @endif
            </x-card>

            <x-card :title="t('partner.historyTitle')" :padding="false">
                @if ($payouts->isEmpty())
                    <x-empty icon="wallet" :title="t('partner.noPayouts')" />
                @else
                    <div class="overflow-x-auto">
                        <table class="table">
                            <thead><tr><th>{{ t('partner.col.date') }}</th><th>{{ t('partner.col.project') }}</th><th class="text-right">{{ t('partner.col.amount') }}</th><th>{{ t('partner.col.status') }}</th><th>{{ t('partner.col.decidedBy') }}</th></tr></thead>
                            <tbody>
                            @foreach ($payouts as $po)
                                <tr>
                                    <td class="whitespace-nowrap">{{ dt($po->created_at) }}<span class="block text-xs text-muted-foreground">{{ $po->requestedBy?->full_name }}</span></td>
                                    <td>{{ \App\Support\Format::code('P', $po->project?->number) }} · {{ $po->project?->name }}@if ($po->comment)<span class="block whitespace-pre-wrap text-xs text-muted-foreground">{{ $po->comment }}</span>@endif</td>
                                    <td class="whitespace-nowrap text-right font-medium tabular-nums">{{ money($po->amount_uzs) }}</td>
                                    <td><x-badge :color="$statusColor[$po->status]">{{ t('partner.status.'.$po->status) }}</x-badge>
                                        @if ($po->reject_reason)<span class="block whitespace-pre-wrap text-xs text-muted-foreground">{{ $po->reject_reason }}</span>@endif</td>
                                    <td>{{ $po->decidedBy?->full_name ?? '—' }}@if ($po->decided_at)<span class="block text-xs text-muted-foreground">{{ dt($po->decided_at) }}</span>@endif</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-card>

            @if ($canRequest)
                <x-modal name="payout-new" :title="t('partner.requestTitle')" :open="$errors->hasAny(['amount', 'comment', 'project_id'])">
                    <form method="POST" action="/finance/partner/payouts" class="space-y-4">
                        @csrf
                        <input type="hidden" name="project_id" :value="pid">
                        <p class="text-sm"><span class="font-medium" x-text="pname"></span><span class="block text-xs text-muted-foreground" x-show="left">{{ t('partner.remainingHint') }}: <span x-text="left"></span></span></p>
                        <x-field name="amount" id="payout-amount" inputmode="decimal" data-money :label="t('partner.col.amount').', UZS'" required />
                        <x-field name="comment" id="payout-comment" type="textarea" :label="t('partner.col.comment')" />
                        <div class="flex justify-end gap-2">
                            <button type="button" class="btn btn-outline" @click="$dispatch('close-modal', 'payout-new')">{{ t('partner.cancel') }}</button>
                            <button class="btn btn-primary">{{ t('partner.send') }}</button>
                        </div>
                    </form>
                </x-modal>
            @endif
            @if ($canDecide)
                <x-modal name="payout-reject" :title="t('partner.rejectTitle')" :open="$errors->has('reject_reason')">
                    <form method="POST" :action="'/finance/partner/payouts/' + rid + '/reject'" class="space-y-4">
                        @csrf
                        <x-field name="reject_reason" id="payout-reason" type="textarea" :label="t('partner.rejectReason')" required />
                        <div class="flex justify-end gap-2">
                            <button type="button" class="btn btn-outline" @click="$dispatch('close-modal', 'payout-reject')">{{ t('partner.cancel') }}</button>
                            <button class="btn btn-danger">{{ t('partner.reject') }}</button>
                        </div>
                    </form>
                </x-modal>
            @endif
        @endif
    </div>
@endsection
