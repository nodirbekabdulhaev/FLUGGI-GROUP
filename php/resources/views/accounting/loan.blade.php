@extends('layouts.app')
@section('title', $loan->name)
@section('content')
    @php
        $cur = $loan->currency;
        $today = \App\Support\Format::today()->toDateString();
    @endphp
    <x-page-header :title="$loan->name" :subtitle="trim(($loan->lender ?: '').($loan->rate !== null ? ' · '.$loan->rate.'%' : ''), ' ·')" back="/accounting/loans">
        @if ($canManage)
            <button type="button" class="btn btn-outline" x-data @click="$dispatch('open-modal', 'loan-edit')"><x-icon name="edit" /> {{ t('common.edit') }}</button>
            <form method="POST" action="/accounting/loans/{{ $loan->id }}" onsubmit="return confirm(@js(t('accounting.loans.deleteConfirm')))">@csrf @method('DELETE')
                <button class="btn btn-outline" aria-label="{{ t('common.delete') }}"><x-icon name="trash" /></button></form>
        @endif
    </x-page-header>
    @include('accounting.partials.tabs', ['active' => '/accounting/loans'])

    <div class="mb-4 grid gap-3 sm:grid-cols-4">
        <x-stat :label="t('accounting.loans.principal')" :value="money((string) $loan->principal, $cur)" />
        <x-stat :label="t('accounting.loans.balance')" :value="money((string) $stats['balance'], $cur)" :hint="t('accounting.loans.balanceHint')" />
        <x-stat :label="t('accounting.loans.paidTotal')" :value="money((string) $stats['paidTotal'], $cur)" :hint="$stats['paidCount'].' / '.$stats['count']" />
        <x-stat :label="t('accounting.loans.leftTotal')" :value="money((string) $stats['leftTotal'], $cur)" :hint="t('accounting.loans.leftInterest', ['sum' => money((string) $stats['leftInterest'], $cur)])" />
    </div>
    @if ($loan->note)<p class="mb-4 text-sm text-muted-foreground">{{ $loan->note }}</p>@endif
    <p class="mb-3 text-sm text-muted-foreground">{{ t('accounting.loans.remindInfo', ['days' => $loan->remind_days, 'user' => $loan->owner?->full_name ?? '—']) }}</p>

    @if ($canManage)
        <div class="mb-4 grid gap-4 lg:grid-cols-2">
            <x-card :title="t('accounting.loans.manualTitle')">
                <form method="POST" action="/accounting/loans/{{ $loan->id }}/schedule" class="space-y-3">
                    @csrf
                    @include('accounting.partials.loan-rows')
                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="replace" value="1"> {{ t('accounting.loans.replace') }}</label>
                    <button class="btn btn-primary">{{ t('common.save') }}</button>
                </form>
            </x-card>
            <x-card :title="t('accounting.loans.importTitle')">
                <form method="POST" action="/accounting/loans/{{ $loan->id }}/import" enctype="multipart/form-data" class="space-y-3">
                    @csrf
                    <div>
                        <label class="label" for="loan-file">{{ t('accounting.loans.file') }}<span class="text-danger"> *</span></label>
                        <input id="loan-file" type="file" name="file" class="input" accept=".xlsx,.xls,.csv" required>
                        @error('file')<p class="error">{{ $message }}</p>@else<p class="hint">{{ t('accounting.loans.fileHint') }}</p>@enderror
                    </div>
                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="replace" value="1" checked> {{ t('accounting.loans.replace') }}</label>
                    <button class="btn btn-primary"><x-icon name="upload" /> {{ t('accounting.loans.import') }}</button>
                </form>
            </x-card>
        </div>
    @endif

    @if ($loan->payments->isEmpty())
        <div class="card"><x-empty icon="calendar" :title="t('accounting.loans.noSchedule')">{{ t('accounting.loans.noScheduleText') }}</x-empty></div>
    @else
        <div x-data="{ payId: null, payText: '', payAmount: '', editId: null, ed: {} }">
        <x-table>
            <caption class="px-3 pb-2 pt-3 text-left text-sm font-semibold">{{ t('accounting.loans.schedule') }}</caption>
            <thead><tr>
                <th>{{ t('accounting.fields.month') }}</th><th>{{ t('accounting.loans.dueDate') }}</th><th class="text-right">{{ t('accounting.loans.principalPart') }}</th><th class="text-right">{{ t('accounting.loans.interestPart') }}</th>
                <th class="text-right">{{ t('accounting.loans.total') }}</th><th>{{ t('accounting.loans.status') }}</th>@if ($canManage)<th></th>@endif
            </tr></thead>
            <tbody>
            @foreach ($loan->payments as $p)
                @php $late = ! $p->paid_at && $p->due_date->toDateString() < $today; @endphp
                <tr @class(['bg-success/5' => $p->paid_at, 'bg-danger/5' => $late])>
                    <td class="whitespace-nowrap font-medium">{{ \App\Services\Accounting\Accounting::monthLabel($p->due_date->format('Y-m')) }}</td>
                    <td class="whitespace-nowrap">{{ d($p->due_date) }}</td>
                    <td class="whitespace-nowrap text-right tabular-nums">{{ money((string) $p->principal, $cur) }}</td>
                    <td class="whitespace-nowrap text-right tabular-nums">{{ money((string) $p->interest, $cur) }}</td>
                    <td class="whitespace-nowrap text-right font-medium tabular-nums">{{ money((string) $p->total, $cur) }}</td>
                    <td class="whitespace-nowrap">
                        @if ($p->paid_at)<x-badge color="green">{{ t('accounting.loans.paid', ['date' => d($p->paid_at)]) }}</x-badge>
                            @if ($p->paid_amount !== null && (string) $p->paid_amount !== (string) $p->total)<span class="block text-xs text-muted-foreground">{{ money((string) $p->paid_amount, $cur) }}</span>@endif
                        @elseif ($late)<x-badge color="red">{{ t('accounting.loans.late') }}</x-badge>
                        @else<x-badge color="gray">{{ t('accounting.loans.waiting') }}</x-badge>@endif</td>
                    @if ($canManage)
                        <td class="whitespace-nowrap text-right">
                            @if ($p->paid_at)
                                <form method="POST" action="/accounting/loan-payments/{{ $p->id }}/paid" class="inline">@csrf @method('DELETE')<button class="btn btn-ghost btn-sm">{{ t('accounting.loans.undo') }}</button></form>
                            @else
                                <button type="button" class="btn btn-outline btn-sm"
                                    @click="payId = @js($p->id); payAmount = @js((string) $p->total); payText = @js(d($p->due_date).' · '.money((string) $p->total, $cur)); $dispatch('open-modal', 'loan-pay')">{{ t('accounting.loans.markPaid') }}</button>
                            @endif
                            <button type="button" class="btn btn-ghost btn-icon" aria-label="{{ t('common.edit') }}"
                                @click="editId = @js($p->id); ed = @js(['due_date' => $p->due_date->toDateString(), 'principal' => (string) $p->principal, 'interest' => (string) $p->interest, 'total' => (string) $p->total]); $dispatch('open-modal', 'loan-row')"><x-icon name="edit" /></button>
                            <form method="POST" action="/accounting/loan-payments/{{ $p->id }}" class="inline" onsubmit="return confirm(@js(t('accounting.loans.rowDeleteConfirm')))">@csrf @method('DELETE')
                                <button class="btn btn-ghost btn-icon" aria-label="{{ t('common.delete') }}"><x-icon name="trash" /></button></form>
                        </td>
                    @endif
                </tr>
            @endforeach
            </tbody>
        </x-table>

        @if ($canManage)
            <x-modal name="loan-pay" :title="t('accounting.loans.markPaid')">
                <form method="POST" :action="'/accounting/loan-payments/' + payId + '/paid'" class="space-y-4">
                    @csrf
                    <p class="text-sm" x-text="payText"></p>
                    <x-field name="paid_at" type="date" :label="t('accounting.loans.paidAt')" :value="$today" />
                    <div><label class="label" for="loan-paid-amount">{{ t('accounting.loans.paidAmount') }}</label><input id="loan-paid-amount" name="paid_amount" class="input" inputmode="decimal" data-money x-model="payAmount"><p class="hint">{{ t('accounting.loans.paidAmountHint') }}</p></div>
                    <div class="flex justify-end gap-2">
                        <button type="button" class="btn btn-outline" @click="$dispatch('close-modal', 'loan-pay')">{{ t('common.cancel') }}</button>
                        <button class="btn btn-primary">{{ t('accounting.loans.markPaid') }}</button>
                    </div>
                </form>
            </x-modal>
            <x-modal name="loan-row" :title="t('accounting.loans.editRow')">
                <form method="POST" :action="'/accounting/loan-payments/' + editId" class="space-y-4">
                    @csrf @method('PUT')
                    <div><label class="label" for="lr-date">{{ t('accounting.loans.dueDate') }}</label><input id="lr-date" type="date" name="due_date" class="input" x-model="ed.due_date" required></div>
                    <div class="grid gap-3 sm:grid-cols-3">
                        <div><label class="label" for="lr-p">{{ t('accounting.loans.principalPart') }}</label><input id="lr-p" name="principal" class="input" inputmode="decimal" x-model="ed.principal"></div>
                        <div><label class="label" for="lr-i">{{ t('accounting.loans.interestPart') }}</label><input id="lr-i" name="interest" class="input" inputmode="decimal" x-model="ed.interest"></div>
                        <div><label class="label" for="lr-t">{{ t('accounting.loans.total') }}</label><input id="lr-t" name="total" class="input" inputmode="decimal" x-model="ed.total"></div>
                    </div>
                    <div class="flex justify-end gap-2">
                        <button type="button" class="btn btn-outline" @click="$dispatch('close-modal', 'loan-row')">{{ t('common.cancel') }}</button>
                        <button class="btn btn-primary">{{ t('common.save') }}</button>
                    </div>
                </form>
            </x-modal>
        @endif
        </div>
    @endif

    @if ($canManage)
        <x-modal name="loan-edit" :title="t('accounting.loans.edit')" size="max-w-3xl" :open="$errors->any() && old('_form') === 'loan-edit'">
            <form method="POST" action="/accounting/loans/{{ $loan->id }}" class="space-y-4">
                @csrf @method('PUT')
                <input type="hidden" name="_form" value="loan-edit">
                @include('accounting.partials.loan-form', ['loan' => $loan, 'owners' => $owners])
                <div class="flex justify-end gap-2">
                    <button type="button" class="btn btn-outline" @click="$dispatch('close-modal', 'loan-edit')">{{ t('common.cancel') }}</button>
                    <button class="btn btn-primary">{{ t('common.save') }}</button>
                </div>
            </form>
        </x-modal>
    @endif
@endsection
