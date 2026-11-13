{{-- Поля договора кредита (создание и правка). --}}
@props(['loan' => null, 'owners' => []])
<div class="grid gap-4 sm:grid-cols-2">
    <x-field name="name" :label="t('accounting.loans.name')" :value="old('name', $loan?->name)" required />
    <x-field name="lender" :label="t('accounting.loans.lender')" :value="old('lender', $loan?->lender)" />
    <x-field name="principal" inputmode="decimal" data-money :label="t('accounting.loans.principal')" :value="old('principal', $loan ? (string) $loan->principal : null)" required />
    <x-select name="currency" :label="t('accounting.fields.currency')" :options="['UZS' => 'UZS', 'USD' => 'USD']" :value="old('currency', $loan?->currency ?? 'UZS')" required />
    <x-field name="rate" inputmode="decimal" :label="t('accounting.loans.rate')" :value="old('rate', $loan?->rate)" />
    <x-field name="remind_days" type="number" min="0" max="60" :label="t('accounting.loans.remindDays')" :hint="t('accounting.loans.remindHint')" :value="old('remind_days', $loan?->remind_days ?? 7)" />
    <x-field name="start_date" type="date" :label="t('accounting.loans.startDate')" :value="old('start_date', $loan?->start_date?->toDateString())" />
    <x-field name="end_date" type="date" :label="t('accounting.loans.endDate')" :value="old('end_date', $loan?->end_date?->toDateString())" />
    <x-select name="owner_id" :label="t('accounting.loans.owner')" :options="$owners" :value="old('owner_id', $loan?->owner_id ?? access()->id())" :hint="t('accounting.loans.ownerHint')" />
</div>
<x-field name="note" type="textarea" rows="2" :label="t('accounting.fields.description')" :value="old('note', $loan?->note)" />
@if ($loan)
    <input type="hidden" name="is_active" value="0">
    <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_active" value="1" @checked((bool) old('is_active', $loan->is_active))> {{ t('accounting.loans.active') }}</label>
@endif
