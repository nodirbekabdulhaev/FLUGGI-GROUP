{{-- Форма человека для налоговой: создание или правка ($person) --}}
@php $num = fn ($v) => $v === null ? null : rtrim(rtrim((string) $v, '0'), '.'); @endphp
<form method="POST" action="{{ $person ? '/accounting/people/'.$person->id : '/accounting/people' }}" class="space-y-4">
    @csrf
    @if ($person) @method('PUT') @endif
    <x-field name="full_name" :label="t('accounting.people.fullName')" :value="$person?->full_name" required />
    <div class="grid gap-4 sm:grid-cols-2">
        <x-field name="pinfl" inputmode="numeric" maxlength="14" :label="t('accounting.people.pinfl')" :hint="t('accounting.people.pinflHint')" :value="$person?->pinfl" />
        <x-field name="position" :label="t('accounting.people.position')" :value="$person?->position" />
        <x-field name="monthly_salary" inputmode="decimal" :label="t('accounting.people.salary')" :hint="t('accounting.people.salaryHint')" :value="$num($person?->monthly_salary)" required />
        <x-select name="user_id" :label="t('accounting.people.user')" :placeholder="t('accounting.people.noUser')" :options="$users" :value="$person?->user_id" />
        <x-field name="hired_at" type="date" :label="t('accounting.people.hiredAt')" :value="$person?->hired_at?->toDateString()" />
        <x-field name="fired_at" type="date" :label="t('accounting.people.firedAt')" :value="$person?->fired_at?->toDateString()" />
    </div>
    <x-field name="comment" type="textarea" rows="2" :label="t('accounting.fields.description')" :value="$person?->comment" />
    <input type="hidden" name="is_active" value="0">
    <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $person?->is_active ?? true))> {{ t('accounting.people.active') }}</label>
    <div class="flex justify-end gap-2">
        @if ($person)<a href="/accounting/people" class="btn btn-outline">{{ t('common.cancel') }}</a>
        @else<button type="button" class="btn btn-outline" @click="$dispatch('close-modal', 'acc-person')">{{ t('common.cancel') }}</button>@endif
        <button class="btn btn-primary">{{ t('common.save') }}</button>
    </div>
</form>
