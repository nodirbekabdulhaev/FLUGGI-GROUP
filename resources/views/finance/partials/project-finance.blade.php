{{-- Финансовая карточка проекта (ТЗ §25): стоимость, расходы, прибыль, маржа, комиссии, накладные, оклады, доля партнёра, план по тарифу --}}
@php
    $fin = $finance;
    $neg = fn ($v) => str_starts_with((string) $v, '-');
    $tiles = [
        [t('finance.project.revenue'), money($fin['revenueUzs']), null],
        [t('finance.project.collected'), money($fin['collectedUzs']), null],
        [t('finance.project.receivable'), money($fin['receivableUzs']), null],
        [t('finance.project.expenses'), money($fin['expensesUzs']), null],
        [t('finance.project.grossProfit'), money($fin['grossProfitUzs']), $neg($fin['grossProfitUzs']) ? 'text-danger' : 'text-success'],
        [t('finance.project.margin'), $fin['marginPct'] === null ? '—' : $fin['marginPct'].'%', null],
        [t('finance.project.commissions'), money($fin['commissionsUzs']), null],
        [t('finance.project.overhead'), money($fin['overheadUzs']), null],
        [t('finance.project.salaries'), money($fin['salaryUzs']), null],
        [t('finance.project.netProfit'), money($fin['netProfitUzs']), $neg($fin['netProfitUzs']) ? 'text-danger' : 'text-success'],
        [t('finance.project.partnerShare', ['pct' => $fin['partner']['pct']]), money($fin['partner']['shareUzs']), null],
    ];
    if ($fin['partner']['configured']) {
        $tiles[] = [t('finance.project.partnerPaid'), money($fin['partner']['paidUzs']), null];
        $tiles[] = [t('finance.project.partnerRemaining'), money($fin['partner']['remainingUzs']), null];
    }
    if ((float) $fin['plannedCostUzs'] > 0) {
        $tiles[] = [t('finance.project.plannedCost'), money($fin['plannedCostUzs']), null];
    }
    $canEditLines = access()->can('expense.update');
    $members = $p->team->where('status', 'ACTIVE');
    $editing = request('edit') ? $expenses['page']->getCollection()->firstWhere('id', request('edit')) : null;
    $editing = $editing?->can_edit ? $editing : null;
    $opts = \App\Http\Controllers\Finance\ExpensesController::formOptions($editing, $p->id);
@endphp
<div class="grid gap-6">
    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ($tiles as [$label, $value, $tone])
            <div class="rounded-lg border p-3"><p class="text-xs text-muted-foreground">{{ $label }}</p><p class="text-lg font-semibold tabular-nums {{ $tone }}">{{ $value }}</p></div>
        @endforeach
    </div>
    <p class="-mt-3 text-xs text-muted-foreground">{{ t('finance.project.netProfitHint') }}
        @if (\App\Services\Finance\PartnerService::canView())· <a class="link" href="/finance/partner">{{ t('partner.title') }}</a>@endif</p>

    <section class="grid content-start gap-3">
        <div>
            <h3 class="text-sm font-semibold">{{ t('costLines.title') }}</h3>
            @if ($costLines->isNotEmpty())<p class="text-xs text-muted-foreground">{{ t('costLines.text') }}</p>@endif
        </div>
        @if ($costLines->isEmpty())
            <p class="text-sm text-muted-foreground">{{ t('costLines.empty') }}</p>
        @else
            <div class="overflow-x-auto">
                <table class="table">
                    <thead><tr><th>{{ t('costLines.work') }}</th><th>{{ t('costLines.assignee') }}</th><th class="text-right">{{ t('costLines.qtyRate') }}</th><th class="text-right">{{ t('costLines.amount') }}</th><th>{{ t('costLines.status') }}</th><th></th></tr></thead>
                    <tbody>
                    @foreach ($costLines as $l)
                        <tr>
                            <td><span class="font-medium">{{ $l->label }}</span>@if ($l->specialty)<span class="block text-xs text-muted-foreground">{{ t('specialties.'.$l->specialty) }}</span>@endif</td>
                            <td>{{ $l->assignee?->full_name ?? t('costLines.notAssigned') }}@if ($l->personal_rate)<span class="block text-xs text-muted-foreground">{{ t('costLines.personalRate') }}</span>@endif</td>
                            <td class="whitespace-nowrap text-right tabular-nums">{{ $l->kind === 'PIECE' ? rtrim(rtrim((string) $l->quantity, '0'), '.').' × '.money($l->rate, $l->currency) : t('costLines.fixed') }}</td>
                            <td class="whitespace-nowrap text-right font-medium tabular-nums">{{ money($l->amount, $l->currency) }}</td>
                            <td><x-badge :color="$l->view_status === 'ACCRUED' ? 'green' : 'gray'">{{ t('costLines.status'.$l->view_status) }}</x-badge>@if ($l->expense_code)<span class="block text-xs text-muted-foreground">{{ $l->expense_code }}</span>@endif</td>
                            <td class="whitespace-nowrap text-right">
                                @if ($canEditLines && $l->view_status === 'PLANNED')
                                    <button type="button" class="btn btn-ghost btn-icon" aria-label="{{ t('costLines.edit') }}: {{ $l->label }}" @click="$dispatch('open-modal', 'cl-{{ $l->id }}')"><x-icon name="edit" /></button>
                                    <form method="POST" action="/finance/cost-lines/{{ $l->id }}/accrue" class="inline" data-confirm="{{ t('costLines.accrueConfirm', ['label' => $l->label]) }}">@csrf
                                        <button class="btn btn-ghost btn-icon" title="{{ t('costLines.accrue') }}" aria-label="{{ t('costLines.accrue') }}: {{ $l->label }}"><x-icon name="dollar" /></button></form>
                                    <form method="POST" action="/finance/cost-lines/{{ $l->id }}/cancel" class="inline" data-confirm="{{ t('costLines.cancelConfirm', ['label' => $l->label]) }}">@csrf
                                        <button class="btn btn-ghost btn-icon" title="{{ t('costLines.cancelLine') }}" aria-label="{{ t('costLines.cancelLine') }}: {{ $l->label }}"><x-icon name="x" /></button></form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            @if ($canEditLines)
                @foreach ($costLines->where('view_status', 'PLANNED') as $l)
                    <x-modal :name="'cl-'.$l->id" :title="t('costLines.edit').': '.$l->label">
                        <form method="POST" action="/finance/cost-lines/{{ $l->id }}" class="space-y-4">
                            @csrf @method('PATCH')
                            <x-select name="assignee_id" :id="'cl-user-'.$l->id" :label="t('costLines.assignee')" :hint="t('costLines.assigneeHint')" :value="$l->assignee_id" :placeholder="t('costLines.notAssigned')" :options="$members->pluck('user.full_name', 'user_id')->all()" />
                            <div class="grid gap-4 sm:grid-cols-2">
                                @if ($l->kind === 'PIECE')
                                    <x-field name="quantity" :id="'cl-qty-'.$l->id" type="number" step="any" min="0.01" :label="t('costLines.quantity')" :value="rtrim(rtrim((string) $l->quantity, '0'), '.')" required />
                                @endif
                                <x-field name="rate" :id="'cl-rate-'.$l->id" inputmode="decimal" data-money :label="t($l->kind === 'PIECE' ? 'costLines.rate' : 'costLines.amount').', '.$l->currency" :value="rtrim(rtrim((string) $l->rate, '0'), '.')" required />
                            </div>
                            <div class="flex justify-end gap-2">
                                <button type="button" class="btn btn-outline" @click="$dispatch('close-modal', 'cl-{{ $l->id }}')">{{ t('costLines.cancel') }}</button>
                                <button class="btn btn-primary">{{ t('costLines.save') }}</button>
                            </div>
                        </form>
                    </x-modal>
                @endforeach
            @endif
        @endif
    </section>

    <div class="grid gap-6 lg:grid-cols-[1fr,2fr]">
        <section class="grid content-start gap-3">
            <h3 class="text-sm font-semibold">{{ t('finance.byCategory') }}</h3>
            @include('finance.partials.category-bars', ['items' => $fin['byCategory']])
        </section>
        <section class="grid content-start gap-3">
            <div class="flex items-center justify-between gap-3">
                <h3 class="text-sm font-semibold">{{ t('finance.project.expensesList') }}</h3>
                @if (access()->can('expense.create'))
                    <button type="button" class="btn btn-primary btn-sm" @click="$dispatch('open-modal', 'expense-new')"><x-icon name="plus" /> {{ t('finance.expenses.new') }}</button>
                @endif
            </div>
            @if ($expenses['page']->isEmpty())
                <p class="text-sm text-muted-foreground">{{ t('finance.noExpenses') }}</p>
            @else
                @include('finance.partials.expenses-table', ['items' => $expenses['page'], 'showProject' => false, 'editBase' => '/projects/'.$p->id.'?tab=finance'])
            @endif
        </section>
    </div>
</div>
@if (access()->can('expense.create'))
    <x-modal name="expense-new" :title="t('finance.expenses.new')" :open="$errors->any() && ! $editing">
        @include('finance.partials.expense-form', ['expense' => null, 'fixedProject' => $p->id] + $opts)
    </x-modal>
@endif
@if ($editing)
    <x-modal name="expense-edit" :title="t('finance.expenses.edit').' '.\App\Support\Format::code('EXP', $editing->number)" :open="true">
        @include('finance.partials.expense-form', ['expense' => $editing, 'returnTo' => '/projects/'.$p->id.'?tab=finance'] + $opts)
    </x-modal>
@endif
