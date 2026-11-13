@extends('layouts.app')
@section('title', t('finance.expenses.title'))
@section('content')
    <x-page-header :title="t('finance.expenses.title')" :subtitle="t('finance.expenses.subtitle')">
        @includeIf('analytics.partials.export-menu', ['entity' => 'expenses'])
        @if (access()->can('expense.create'))
            <button type="button" class="btn btn-primary" @click="$dispatch('open-modal', 'expense-new')"><x-icon name="plus" /> {{ t('finance.expenses.new') }}</button>
        @endif
    </x-page-header>
    <div class="card">
        <form method="GET" class="flex flex-col gap-3 border-b p-4 sm:flex-row sm:items-end sm:justify-between">
            <div class="flex flex-col gap-3 sm:flex-row">
                @if ($company)
                    <select name="scope" class="input sm:w-48" aria-label="{{ t('finance.expenses.scope') }}" onchange="this.form.submit()">
                        <option value="">{{ t('finance.expenses.allScopes') }}</option>
                        @foreach (['PROJECT', 'COMPANY'] as $sc)<option value="{{ $sc }}" @selected(($filters['scope'] ?? '') === $sc)>{{ t('finance.scope.'.$sc) }}</option>@endforeach
                    </select>
                @endif
                <select name="category" class="input sm:w-56" aria-label="{{ t('finance.expenses.category') }}" onchange="this.form.submit()">
                    <option value="">{{ t('finance.expenses.allCategories') }}</option>
                    @foreach ($categories as $code => $name)<option value="{{ $code }}" @selected(($filters['category'] ?? '') === $code)>{{ $name }}</option>@endforeach
                </select>
            </div>
            <p class="sm:text-right"><span class="block text-xs text-muted-foreground">{{ t('finance.expenses.total') }}</span><span class="text-xl font-semibold tabular-nums">{{ money($totalUzs) }}</span></p>
        </form>
        @if ($expenses->isEmpty())
            <x-empty icon="wallet" :title="t('finance.expenses.empty')">{{ t('finance.expenses.emptyText') }}</x-empty>
        @else
            @include('finance.partials.expenses-table', ['items' => $expenses, 'editBase' => '/finance/expenses?'.http_build_query(array_filter(['scope' => $filters['scope'] ?? null, 'category' => $filters['category'] ?? null]))])
            <div class="border-t px-3 py-2">{{ $expenses->links('vendor.pagination.fluggi') }}</div>
        @endif
    </div>
    @if (access()->can('expense.create'))
        <x-modal name="expense-new" :title="t('finance.expenses.new')" :open="$errors->any() && ! $editing">
            @include('finance.partials.expense-form', ['expense' => null])
        </x-modal>
    @endif
    @if ($editing)
        <x-modal name="expense-edit" :title="t('finance.expenses.edit').' '.\App\Support\Format::code('EXP', $editing->number)" :open="true">
            @include('finance.partials.expense-form', ['expense' => $editing, 'returnTo' => '/finance/expenses'])
        </x-modal>
    @endif
@endsection
