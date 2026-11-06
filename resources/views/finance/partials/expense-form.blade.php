{{-- Форма расхода. $expense — null для нового; $fixedProject — проект зафиксирован (карточка проекта) --}}
@php($fixedProject = $fixedProject ?? null)
<form method="POST" action="{{ $expense ? '/finance/expenses/'.$expense->id : '/finance/expenses' }}" class="space-y-4"
      x-data="{ scope: @js(old('scope', $expense?->scope ?? 'PROJECT')) }">
    @csrf
    @if ($expense) @method('PATCH') <input type="hidden" name="return_to" value="{{ $returnTo ?? '/finance/expenses' }}"> @endif
    @if (! $expense)
        @if ($fixedProject)
            <input type="hidden" name="scope" value="PROJECT"><input type="hidden" name="project_id" value="{{ $fixedProject }}">
        @else
            <div class="grid gap-4 sm:grid-cols-[10rem,1fr]">
                <div>
                    <label class="label" for="ex-scope">{{ t('finance.expenses.scope') }}</label>
                    <select id="ex-scope" name="scope" class="input" x-model="scope">
                        <option value="PROJECT">{{ t('finance.scope.PROJECT') }}</option>
                        @if ($company)<option value="COMPANY">{{ t('finance.scope.COMPANY') }}</option>@endif
                    </select>
                </div>
                <div x-show="scope === 'PROJECT'">
                    <x-select name="project_id" :label="t('finance.expenses.project')" placeholder="—" :options="$projectOptions" ::required="scope === 'PROJECT'" ::disabled="scope !== 'PROJECT'" />
                </div>
            </div>
        @endif
    @endif
    <div class="grid gap-4 sm:grid-cols-[1fr,6rem]">
        <x-field name="amount" inputmode="decimal" data-money :label="t('finance.expenses.amount')" :value="$expense ? rtrim(rtrim((string) $expense->amount, '0'), '.') : null" required />
        <x-select name="currency" :label="t('incomes.currency')" :value="$expense?->currency ?? 'UZS'" :options="['UZS' => 'UZS', 'USD' => 'USD']" />
    </div>
    <div class="grid gap-4 sm:grid-cols-2">
        <x-select name="category" :label="t('finance.expenses.category')" :value="$expense?->category ?? 'EXECUTOR'" :options="$expenseCategories" required />
        <x-field name="expense_date" type="date" :label="t('finance.expenses.date')" :value="$expense?->expense_date?->format('Y-m-d') ?? \App\Support\Format::today()->toDateString()" required />
        <x-select name="payee_user_id" class="sm:col-span-2" :label="t('finance.expenses.payee')" :value="$expense?->payee_user_id" :placeholder="t('finance.expenses.noPayee')" :options="$payees" />
    </div>
    <x-field name="description" type="textarea" rows="2" :label="t('finance.expenses.description')" :value="$expense?->description" />
    <div class="flex justify-end gap-2">
        <button type="button" class="btn btn-outline" @click="$dispatch('close-modal', '{{ $expense ? 'expense-edit' : 'expense-new' }}')">{{ t('common.cancel') }}</button>
        <button class="btn btn-primary">{{ $expense ? t('common.save') : t('finance.expenses.new') }}</button>
    </div>
</form>
