@php($canEdit = access()->can('reference.manage', 'ALL'))
<x-card :title="t($kind === 'EXPENSE' ? 'financeSettings.expenseCategories' : 'financeSettings.incomeCategories')" :subtitle="t($kind === 'EXPENSE' ? 'financeSettings.expenseCategoriesText' : 'financeSettings.incomeCategoriesText')" :padding="false">
    @if ($canEdit)
        <x-slot:actions><button type="button" class="btn btn-primary btn-sm" @click="$dispatch('open-modal', 'fc-new-{{ $kind }}')"><x-icon name="plus" /> {{ t('financeSettings.add') }}</button></x-slot:actions>
    @endif
    <div class="overflow-x-auto">
        <table class="table">
            <thead><tr><th>{{ t('financeSettings.name') }}</th><th>{{ t('financeSettings.accountHint') }}</th><th class="text-right">{{ t('financeSettings.usage') }}</th><th></th></tr></thead>
            <tbody>
            @foreach ($items as $c)
                <tr @class(['opacity-60' => ! $c->is_active])>
                    <td><span class="font-medium">{{ $c->name }}</span>
                        @if ($c->is_overhead) <x-badge color="yellow">{{ t('financeSettings.overheadBadge') }}</x-badge>@endif
                        @if (! $c->is_active) <x-badge>{{ t('financeSettings.off') }}</x-badge>@endif</td>
                    <td class="text-muted-foreground">{{ $c->account_hint ?? '—' }}</td>
                    <td class="text-right tabular-nums">{{ $c->usage }}</td>
                    <td class="whitespace-nowrap text-right">
                        @if ($canEdit)
                            <button type="button" class="btn btn-ghost btn-icon" aria-label="{{ t('financeSettings.categoryEdit') }}: {{ $c->name }}" @click="$dispatch('open-modal', 'fc-{{ $c->id }}')"><x-icon name="edit" /></button>
                            @if ($c->usage === 0)
                                <form method="POST" action="/settings/finance/categories/{{ $c->id }}" class="inline" data-confirm="{{ t('financeSettings.deleteConfirm', ['name' => $c->name]) }}">@csrf @method('DELETE')
                                    <button class="btn btn-ghost btn-icon" aria-label="{{ t('financeSettings.delete') }}: {{ $c->name }}"><x-icon name="trash" /></button></form>
                            @endif
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</x-card>
@if ($canEdit)
    @foreach ([...$items->all(), null] as $c)
        @php($mid = $c ? 'fc-'.$c->id : 'fc-new-'.$kind)
        <x-modal :name="$mid" :title="$c ? t('financeSettings.categoryEdit') : t($kind === 'EXPENSE' ? 'financeSettings.newExpenseCategory' : 'financeSettings.newIncomeCategory')">
            <form method="POST" action="{{ $c ? '/settings/finance/categories/'.$c->id : '/settings/finance/categories' }}" class="space-y-4">
                @csrf @if ($c) @method('PUT') @endif
                <input type="hidden" name="kind" value="{{ $kind }}"><input type="hidden" name="sort" value="{{ $c?->sort ?? 100 }}">
                <x-field name="name" :id="$mid.'-name'" :label="t('financeSettings.name')" :value="$c?->name" required />
                <x-field name="account_hint" :id="$mid.'-acc'" :label="t('financeSettings.accountHint')" :hint="t('financeSettings.accountHintText')" :value="$c?->account_hint ?? ($kind === 'EXPENSE' ? '9420' : '9390')" />
                @if ($kind === 'EXPENSE')
                    <label class="flex items-start gap-2 text-sm"><input type="checkbox" class="mt-0.5" name="is_overhead" value="1" @checked($c?->is_overhead)>
                        <span>{{ t('financeSettings.isOverhead') }}<span class="block text-xs text-muted-foreground">{{ t('financeSettings.isOverheadHint') }}</span></span></label>
                @endif
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_active" value="1" @checked($c?->is_active ?? true)> {{ t('financeSettings.active') }}</label>
                <div class="flex justify-end gap-2">
                    <button type="button" class="btn btn-outline" @click="$dispatch('close-modal', '{{ $mid }}')">{{ t('financeSettings.cancel') }}</button>
                    <button class="btn btn-primary">{{ t('financeSettings.save') }}</button>
                </div>
            </form>
        </x-modal>
    @endforeach
@endif
