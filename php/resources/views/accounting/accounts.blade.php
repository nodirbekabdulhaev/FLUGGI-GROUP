@extends('layouts.app')
@section('title', t('accounting.accounts.title'))
@section('content')
    <x-page-header :title="t('accounting.accounts.title')" :subtitle="t('accounting.accounts.subtitle')">
        @if ($canManage)
            <button type="button" class="btn btn-primary" @click="$dispatch('open-modal', 'acc-account')"><x-icon name="plus" /> {{ t('accounting.accounts.add') }}</button>
        @endif
    </x-page-header>
    @include('accounting.partials.tabs', ['active' => '/accounting/accounts'])

    @if ($totals)
        <div class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($totals as $cur => $sum)
                <x-stat :label="t('accounting.accounts.totalBalance', ['currency' => $cur])" :value="money($sum, $cur)" />
            @endforeach
        </div>
    @endif

    <div class="card overflow-hidden">
        @if ($accounts->isEmpty())
            <x-empty icon="wallet" :title="t('accounting.accounts.empty')">{{ t('accounting.accounts.emptyText') }}</x-empty>
        @else
            <ul class="divide-y">
                @foreach ($accounts as $a)
                    <li class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between">
                        <div class="min-w-0">
                            <p class="flex flex-wrap items-center gap-2 font-medium">{{ $a->name }}
                                <x-badge>{{ t('accounting.kind.'.$a->kind) }}</x-badge>
                                @unless ($a->is_active)<x-badge color="yellow">{{ t('accounting.accounts.inactive') }}</x-badge>@endunless
                            </p>
                            <p class="text-sm text-muted-foreground">
                                @if ($a->bank_name){{ $a->bank_name }}@endif
                                @if ($a->account_number) · {{ trim(chunk_split($a->account_number, 4, ' ')) }}@endif
                                @if ($a->opening_date) · {{ t('accounting.accounts.openingShort', ['amount' => money($a->opening_balance, $a->currency), 'date' => d($a->opening_date)]) }}@endif
                            </p>
                            <p class="text-xs text-muted-foreground">{{ t('accounting.accounts.crmMethods') }}:
                                {{ $a->crm_methods ? collect($a->crm_methods)->map(fn ($m) => t('sales.paymentMethod.'.$m))->implode(', ') : '—' }}
                                · {{ t('accounting.accounts.operationsCount', ['count' => $a->operations_count]) }}</p>
                        </div>
                        <div class="flex flex-wrap items-center gap-2 sm:justify-end">
                            <a href="/accounting/operations?account={{ $a->id }}" @class(['mr-2 text-lg font-semibold tabular-nums', 'text-danger' => str_starts_with($a->balance, '-')])>{{ money($a->balance, $a->currency) }}</a>
                            @if ($canManage)
                                <a href="/accounting/accounts?edit={{ $a->id }}" class="btn btn-ghost btn-icon" aria-label="{{ t('common.edit') }}"><x-icon name="edit" /></a>
                                <form method="POST" action="/accounting/accounts/{{ $a->id }}/active">@csrf<input type="hidden" name="active" value="{{ $a->is_active ? 0 : 1 }}">
                                    <button class="btn btn-outline btn-sm">{{ $a->is_active ? t('accounting.accounts.deactivate') : t('accounting.accounts.activate') }}</button></form>
                                @if ($a->operations_count === 0)
                                    <form method="POST" action="/accounting/accounts/{{ $a->id }}" onsubmit="return confirm(@js(t('accounting.accounts.deleteConfirm')))">@csrf @method('DELETE')
                                        <button class="btn btn-ghost btn-icon" aria-label="{{ t('common.delete') }}"><x-icon name="trash" /></button></form>
                                @endif
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
    <p class="mt-3 text-xs text-muted-foreground">{{ t('accounting.accounts.balanceHint') }}</p>

    @if ($canManage)
        <x-modal name="acc-account" :title="t('accounting.accounts.add')" :open="$errors->any() && ! $editing">
            @include('accounting.partials.account-form', ['account' => null])
        </x-modal>
        @if ($editing)
            <x-modal name="acc-account-edit" :title="t('accounting.accounts.edit')" :open="true">
                @include('accounting.partials.account-form', ['account' => $editing])
            </x-modal>
        @endif
    @endif
@endsection
