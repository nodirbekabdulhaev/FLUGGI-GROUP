{{-- Форма счёта: создание или правка ($account) --}}
@php
    use App\Services\Accounting\Accounting;
    $num = fn ($v) => $v === null ? null : rtrim(rtrim((string) $v, '0'), '.');
    $methods = old('crm_methods', $account?->crm_methods ?? []);
@endphp
<form method="POST" action="{{ $account ? '/accounting/accounts/'.$account->id : '/accounting/accounts' }}" class="space-y-4" x-data="{ kind: @js(old('kind', $account?->kind ?? 'BANK')) }">
    @csrf
    @if ($account) @method('PUT') @endif
    <x-field name="name" :label="t('accounting.accounts.name')" :value="$account?->name" :hint="t('accounting.accounts.nameHint')" required />
    <div class="grid gap-4 sm:grid-cols-2">
        <div>
            <label class="label" for="f-kind-{{ $account?->id ?? 'new' }}">{{ t('accounting.accounts.kind') }}</label>
            <select id="f-kind-{{ $account?->id ?? 'new' }}" name="kind" class="input" x-model="kind">
                @foreach (Accounting::KINDS as $k)<option value="{{ $k }}">{{ t('accounting.kind.'.$k) }}</option>@endforeach
            </select>
        </div>
        <x-select name="currency" :label="t('accounting.fields.currency')" :options="array_combine(Accounting::CURRENCIES, Accounting::CURRENCIES)" :value="$account?->currency ?? 'UZS'" />
    </div>
    <div class="grid gap-4 sm:grid-cols-2" x-show="kind === 'BANK'" x-cloak>
        <x-field name="bank_name" :label="t('accounting.accounts.bankName')" :value="$account?->bank_name" />
        <x-field name="account_number" inputmode="numeric" :label="t('accounting.accounts.accountNumber')" :value="$account?->account_number" />
    </div>
    <div class="grid gap-4 sm:grid-cols-2">
        <x-field name="opening_balance" inputmode="decimal" :label="t('accounting.accounts.openingBalance')" :value="$num($account?->opening_balance) ?? '0'" />
        <x-field name="opening_date" type="date" :label="t('accounting.accounts.openingDate')" :hint="t('accounting.accounts.openingDateHint')" :value="$account?->opening_date?->toDateString()" />
    </div>
    <fieldset>
        <legend class="label">{{ t('accounting.accounts.crmMethods') }}</legend>
        <div class="flex flex-wrap gap-x-4 gap-y-2">
            @foreach (Accounting::CRM_METHODS as $m)
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="crm_methods[]" value="{{ $m }}" @checked(in_array($m, $methods, true))> {{ t('sales.paymentMethod.'.$m) }}</label>
            @endforeach
        </div>
        <p class="hint">{{ t('accounting.accounts.crmMethodsHint') }}</p>
    </fieldset>
    <x-field name="sort" type="number" min="0" max="1000" :label="t('accounting.accounts.sort')" :value="$account?->sort ?? 0" />
    <div class="flex justify-end gap-2">
        @if ($account)<a href="/accounting/accounts" class="btn btn-outline">{{ t('common.cancel') }}</a>
        @else<button type="button" class="btn btn-outline" @click="$dispatch('close-modal', 'acc-account')">{{ t('common.cancel') }}</button>@endif
        <button class="btn btn-primary">{{ t('common.save') }}</button>
    </div>
</form>
