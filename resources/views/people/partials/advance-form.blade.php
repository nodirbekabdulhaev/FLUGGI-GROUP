{{-- Окно «Аванс»: $advanceUser — сотрудник (карточка) или null (зарплата: сотрудник выбирается кнопкой в строке через событие advance-for) --}}
@php
    $today = \App\Support\Format::today()->format('Y-m-d');
    $defaultDate = str_starts_with($today, $period) ? $today : $period.'-01';
    $isOld = old('_form') === 'advance';
@endphp
<x-modal name="advance" :title="t('payroll.advance.title')" :open="$errors->any() && $isOld">
    <form method="POST" action="{{ route('payroll.advances.store') }}" class="grid gap-4"
          x-data="{ uid: @js($isOld ? old('user_id') : $advanceUser?->id), uname: @js($advanceUser?->full_name), acc: @js($isOld && (bool) old('accounting')) }"
          x-on:advance-for.window="uid = $event.detail.id; uname = $event.detail.name">
        @csrf
        <input type="hidden" name="_form" value="advance">
        <input type="hidden" name="user_id" :value="uid">
        <p class="text-sm"><span class="text-muted-foreground">{{ t('payroll.employee') }}:</span> <span class="font-medium" x-text="uname"></span></p>
        @error('user_id')<p class="error">{{ $message }}</p>@enderror
        <div class="grid gap-4 sm:grid-cols-2">
            <x-field name="amount" id="adv-amount" inputmode="decimal" data-money :label="t('payroll.advance.amount')" :value="$isOld ? old('amount') : null" required />
            <x-field name="paid_at" id="adv-date" type="date" :label="t('payroll.advance.paidAt')" :value="$isOld ? old('paid_at') : $defaultDate" required />
        </div>
        <x-field name="period" id="adv-period" type="month" :label="t('payroll.advance.period')" :hint="t('payroll.advance.periodHint')" :value="$isOld ? old('period') : $period" />
        <x-field name="comment" id="adv-comment" type="textarea" rows="2" :label="t('payroll.advance.comment')" maxlength="1000" :value="$isOld ? old('comment') : null" />
        @if ($canAccounting)
            <input type="hidden" name="accounting" value="0">
            <label class="flex items-start gap-2 text-sm">
                <input type="checkbox" name="accounting" value="1" class="mt-0.5" x-model="acc">
                <span><span class="font-medium">{{ t('payroll.advance.toAccounting') }}</span><span class="block text-xs text-muted-foreground">{{ t('payroll.advance.toAccountingHint') }}</span></span>
            </label>
            @error('accounting')<p class="error">{{ $message }}</p>@enderror
            <div x-show="acc" x-cloak>
                <x-select name="account_id" id="adv-account" :label="t('payroll.advance.account')" :options="$accounts->pluck('name', 'id')->all()" :placeholder="t('crm.common.select')"
                          :hint="$accounts->isEmpty() ? t('payroll.advance.noAccounts') : null" />
            </div>
        @endif
        <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <button type="button" class="btn btn-outline" x-on:click="$dispatch('close-modal', 'advance')">{{ t('common.cancel') }}</button>
            <button class="btn btn-primary">{{ t('payroll.advance.submit') }}</button>
        </div>
    </form>
</x-modal>
