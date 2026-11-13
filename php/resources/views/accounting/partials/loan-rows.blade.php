{{-- Ручной ввод графика: строки «дата · основной долг · проценты · итого (пусто = сумма)». --}}
@props(['currency' => 'UZS'])
<div x-data="{ rows: @js(old('rows') ? array_values(old('rows')) : [['due_date' => '', 'principal' => '', 'interest' => '', 'total' => '']]) }" class="space-y-2">
    <div class="hidden gap-2 text-xs text-muted-foreground sm:grid sm:grid-cols-[9rem_1fr_1fr_1fr_2rem]">
        <span>{{ t('accounting.loans.dueDate') }}</span><span>{{ t('accounting.loans.principalPart') }}</span><span>{{ t('accounting.loans.interestPart') }}</span><span>{{ t('accounting.loans.total') }}</span><span></span>
    </div>
    <template x-for="(r, i) in rows" :key="i">
        <div class="grid grid-cols-2 gap-2 sm:grid-cols-[9rem_1fr_1fr_1fr_2rem]">
            <input type="date" class="input col-span-2 sm:col-span-1" :name="'rows['+i+'][due_date]'" x-model="r.due_date" aria-label="{{ t('accounting.loans.dueDate') }}">
            <input class="input" inputmode="decimal" :name="'rows['+i+'][principal]'" x-model="r.principal" placeholder="{{ t('accounting.loans.principalPart') }}" aria-label="{{ t('accounting.loans.principalPart') }}">
            <input class="input" inputmode="decimal" :name="'rows['+i+'][interest]'" x-model="r.interest" placeholder="{{ t('accounting.loans.interestPart') }}" aria-label="{{ t('accounting.loans.interestPart') }}">
            <input class="input" inputmode="decimal" :name="'rows['+i+'][total]'" x-model="r.total" placeholder="{{ t('accounting.loans.totalAuto') }}" aria-label="{{ t('accounting.loans.total') }}">
            <button type="button" class="btn btn-ghost btn-icon" @click="rows.splice(i, 1); if (!rows.length) rows.push({due_date: '', principal: '', interest: '', total: ''})" aria-label="{{ t('common.delete') }}"><x-icon name="trash" /></button>
        </div>
    </template>
    @error('rows')<p class="error">{{ $message }}</p>@enderror
    <div class="flex flex-wrap items-center gap-2">
        <button type="button" class="btn btn-outline btn-sm" @click="rows.push({due_date: '', principal: '', interest: '', total: ''})"><x-icon name="plus" /> {{ t('accounting.loans.addRow') }}</button>
        <button type="button" class="btn btn-ghost btn-sm" @click="const l = rows[rows.length - 1]; if (l && l.due_date) { const d = new Date(l.due_date + 'T00:00:00'); d.setMonth(d.getMonth() + 1); rows.push({due_date: d.toISOString().slice(0, 10), principal: l.principal, interest: l.interest, total: l.total}) }">{{ t('accounting.loans.nextMonth') }}</button>
        <span class="text-xs text-muted-foreground">{{ t('accounting.loans.rowsHint') }}</span>
    </div>
</div>
