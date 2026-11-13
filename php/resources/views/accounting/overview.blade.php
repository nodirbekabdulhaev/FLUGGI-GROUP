@extends('layouts.app')
@section('title', t('accounting.title'))
@section('content')
    <x-page-header :title="t('accounting.title')" :subtitle="t('accounting.subtitle')">
        <form method="GET"><input type="month" name="period" value="{{ $period }}" class="input w-44" aria-label="{{ t('accounting.fields.month') }}" onchange="this.form.submit()"></form>
        @if (access()->can('export.run'))
            <form method="GET" action="/accounting/annual-report" class="flex items-center gap-1">
                <select name="year" class="input h-9 w-auto" aria-label="{{ t('accounting.annual.year') }}">
                    @foreach (range((int) now()->format('Y'), (int) now()->format('Y') - 5) as $y)<option value="{{ $y }}" @selected($y === (int) now()->format('Y') - 1)>{{ $y }}</option>@endforeach
                </select>
                <button class="btn btn-outline" title="{{ t('accounting.annual.hint') }}"><x-icon name="download" /> {{ t('accounting.annual.button') }}</button>
            </form>
        @endif
        @if (access()->can('accounting.manage'))
            <a href="/accounting/operations?new=IN" class="btn btn-outline"><x-icon name="plus" /> {{ t('accounting.operations.newIncome') }}</a>
            <a href="/accounting/operations?new=OUT" class="btn btn-primary"><x-icon name="plus" /> {{ t('accounting.operations.newExpense') }}</a>
        @endif
    </x-page-header>
    @include('accounting.partials.tabs', ['active' => '/accounting'])

    @if ($accounts->isEmpty())
        <div class="card mb-6">
            <x-empty icon="wallet" :title="t('accounting.accounts.empty')">{{ t('accounting.accounts.emptyText') }}
                @if (access()->can('accounting.manage'))<a href="/accounting/accounts" class="link mt-2 block">{{ t('accounting.accounts.add') }}</a>@endif
            </x-empty>
        </div>
    @else
        <div class="mb-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($accounts as $a)
                <a href="/accounting/operations?account={{ $a->id }}" class="card card-body block hover:border-foreground/30">
                    <p class="flex items-center gap-2 text-xs font-medium uppercase tracking-wide text-muted-foreground"><x-icon :name="$a->kind === 'CASH' ? 'wallet' : ($a->kind === 'CARD' ? 'copy' : 'briefcase')" class="size-3.5" />{{ t('accounting.kind.'.$a->kind) }}</p>
                    <p class="mt-1 truncate font-medium">{{ $a->name }}</p>
                    <p @class(['mt-1 text-xl font-semibold tabular-nums', 'text-danger' => str_starts_with($a->balance, '-')])>{{ money($a->balance, $a->currency) }}</p>
                    @if ($a->bank_name)<p class="truncate text-xs text-muted-foreground">{{ $a->bank_name }}</p>@endif
                </a>
            @endforeach
        </div>
    @endif

    <div class="mb-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
        <x-stat :label="t('accounting.overview.ytdIncome')" :value="money($ytd['income'])" :hint="d($ytd['from']).' — '.d($ytd['to'])" />
        <x-stat :label="t('accounting.overview.bankIncome')" :value="money($bankIncome)" :hint="t('accounting.overview.bankIncomeHint')" />
        <x-stat :label="t('accounting.overview.income')" :value="money($incomeTotal)" :hint="t('accounting.overview.inUzs')" />
        <x-stat :label="t('accounting.overview.expenses')" :value="money($expensesTotal)" :hint="t('accounting.overview.inUzs')" />
        <x-stat :label="t('accounting.overview.taxesBalance')" :value="money(\Brick\Math\BigDecimal::of($taxes['totalDue'])->minus($taxes['totalPaid']))"
                :hint="t('accounting.overview.taxesHint', ['due' => money($taxes['totalDue']), 'paid' => money($taxes['totalPaid'])])"
                :tone="\Brick\Math\BigDecimal::of($taxes['totalDue'])->minus($taxes['totalPaid'])->isPositive() ? 'warn' : null" />
    </div>
    @if ($missingRate || $ytd['missingRate'])<p class="mb-4 flex items-center gap-2 text-sm text-warning"><x-icon name="alert" />{{ t('accounting.errors.noUsdRate') }}</p>@endif

    <div class="mb-6 grid gap-4 lg:grid-cols-3">
        <x-card :title="t('accounting.overview.incomeByCategory')" :padding="false">
            @if (! $income)
                <x-empty icon="chart" :title="t('accounting.overview.noIncome')" />
            @else
                <ul class="divide-y text-sm">
                    @foreach ($income as $cat => $sum)
                        <li class="px-4 py-2.5">
                            <p class="flex items-center justify-between gap-3"><a class="link" href="/accounting/operations?period={{ $period }}&direction=IN&category={{ $cat }}">{{ t('accounting.category.'.$cat) }}</a><span class="whitespace-nowrap tabular-nums">{{ money($sum) }}</span></p>
                            @foreach ($incomeArticles[$cat] ?? [] as $art)
                                <p class="mt-1 flex items-center justify-between gap-3 pl-3 text-xs text-muted-foreground"><a class="min-w-0 truncate hover:underline" href="/accounting/operations?period={{ $period }}&category={{ $art['id'] }}">{{ $art['name'] }}</a><span class="whitespace-nowrap tabular-nums">{{ money($art['sum']) }}</span></p>
                            @endforeach
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-card>
        <x-card :title="t('accounting.overview.expensesByCategory')" :padding="false">
            @if (! $expenses)
                <x-empty icon="chart" :title="t('accounting.overview.noExpenses')" />
            @else
                <ul class="divide-y text-sm">
                    @foreach ($expenses as $cat => $sum)
                        <li class="px-4 py-2.5">
                            <p class="flex items-center justify-between gap-3"><a class="link" href="/accounting/operations?period={{ $period }}&direction=OUT&category={{ $cat }}">{{ t('accounting.category.'.$cat) }}</a><span class="whitespace-nowrap tabular-nums">{{ money($sum) }}</span></p>
                            @foreach ($expensesArticles[$cat] ?? [] as $art)
                                <p class="mt-1 flex items-center justify-between gap-3 pl-3 text-xs text-muted-foreground"><a class="min-w-0 truncate hover:underline" href="/accounting/operations?period={{ $period }}&category={{ $art['id'] }}">{{ $art['name'] }}</a><span class="whitespace-nowrap tabular-nums">{{ money($art['sum']) }}</span></p>
                            @endforeach
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-card>
        <x-card :title="t('accounting.taxes.title')" :subtitle="\App\Services\Accounting\Accounting::monthLabel($period)" :padding="false">
            <x-slot:actions><a href="/accounting/taxes?period={{ $period }}" class="btn btn-ghost btn-sm">{{ t('common.details') }}</a></x-slot:actions>
            <ul class="divide-y text-sm">
                @foreach ($taxes['taxes'] as $cat => $tx)
                    @continue($tx['due'] === null && $tx['paid'] === '0.00')
                    <li class="px-4 py-2.5">
                        <p class="flex justify-between gap-3"><span>{{ t('accounting.category.'.$cat) }}</span><span class="whitespace-nowrap font-medium tabular-nums">{{ $tx['due'] !== null ? money($tx['due']) : '—' }}</span></p>
                        <p class="text-xs text-muted-foreground">{{ t('accounting.taxes.paid') }}: {{ money($tx['paid']) }}</p>
                    </li>
                @endforeach
            </ul>
        </x-card>
    </div>

    <x-card :title="t('accounting.overview.recent')" :padding="false">
        <x-slot:actions><a href="/accounting/operations" class="btn btn-ghost btn-sm">{{ t('accounting.overview.allOperations') }}</a></x-slot:actions>
        @if ($recent->isEmpty())
            <x-empty icon="list" :title="t('accounting.operations.empty')" />
        @else
            <ul class="divide-y">
                @foreach ($recent as $op)
                    <li class="flex items-start justify-between gap-3 px-4 py-3 text-sm">
                        <div class="min-w-0">
                            <p class="truncate font-medium">{{ $op->categoryLabel() }}@if ($op->counterparty) · {{ $op->counterparty }}@endif</p>
                            <p class="truncate text-xs text-muted-foreground">{{ d($op->date) }} · {{ $op->account?->name }}@if ($op->period) · {{ $op->period }}@endif</p>
                        </div>
                        @include('accounting.partials.op-row', ['op' => $op])
                    </li>
                @endforeach
            </ul>
        @endif
    </x-card>
@endsection
