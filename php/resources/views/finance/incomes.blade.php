@extends('layouts.app')
@section('title', t('incomes.title'))
@section('content')
    <x-page-header :title="t('incomes.title')" :subtitle="t('incomes.subtitle')">
        <button type="button" class="btn btn-primary" @click="$dispatch('open-modal', 'income-new')"><x-icon name="plus" /> {{ t('incomes.new') }}</button>
    </x-page-header>
    <div class="card">
        <form method="GET" class="flex flex-col gap-3 border-b p-4 sm:flex-row">
            <select name="category" class="input sm:w-64" aria-label="{{ t('incomes.category') }}" onchange="this.form.submit()">
                <option value="">{{ t('incomes.allCategories') }}</option>
                @foreach ($allCategories as $code => $name)<option value="{{ $code }}" @selected(($filters['category'] ?? '') === $code)>{{ $name }}</option>@endforeach
            </select>
        </form>
        @if ($incomes->isEmpty())
            <x-empty icon="dollar" :title="t('incomes.empty')">{{ t('incomes.emptyText') }}</x-empty>
        @else
            <div class="overflow-x-auto">
                <table class="table">
                    <thead><tr><th>{{ t('incomes.date') }}</th><th>{{ t('incomes.category') }}</th><th>{{ t('incomes.project') }}</th><th class="text-right">{{ t('incomes.amount') }}</th><th></th></tr></thead>
                    <tbody>
                    @foreach ($incomes as $i)
                        <tr>
                            <td class="whitespace-nowrap">{{ d($i->income_date) }}<span class="block text-xs text-muted-foreground">{{ \App\Support\Format::code('INC', $i->number) }}</span></td>
                            <td>{{ $i->categoryRef?->name ?? $i->category }}@if ($i->description)<span class="block max-w-72 truncate text-xs text-muted-foreground">{{ $i->description }}</span>@endif</td>
                            <td>@if ($i->project)<a href="/projects/{{ $i->project->id }}" class="hover:underline">{{ \App\Support\Format::code('P', $i->project->number) }} · {{ $i->project->name }}</a>@else<span class="text-muted-foreground">—</span>@endif</td>
                            <td class="whitespace-nowrap text-right font-medium tabular-nums">{{ money($i->amount, $i->currency) }}@if ($i->currency !== 'UZS')<span class="block text-xs text-muted-foreground">{{ money($i->amount_uzs) }}</span>@endif</td>
                            <td class="whitespace-nowrap text-right">
                                <a href="/finance/incomes?edit={{ $i->id }}" class="btn btn-ghost btn-icon" aria-label="{{ t('incomes.edit') }} {{ \App\Support\Format::code('INC', $i->number) }}"><x-icon name="edit" /></a>
                                <form method="POST" action="/finance/incomes/{{ $i->id }}" class="inline" data-confirm="{{ t('incomes.deleteConfirm', ['number' => \App\Support\Format::code('INC', $i->number)]) }}">@csrf @method('DELETE')
                                    <button class="btn btn-ghost btn-icon" aria-label="{{ t('incomes.delete') }} {{ \App\Support\Format::code('INC', $i->number) }}"><x-icon name="trash" /></button></form>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <div class="border-t px-3 py-2">{{ $incomes->links('vendor.pagination.fluggi') }}</div>
        @endif
    </div>
    @foreach (array_filter(['income-new' => null, 'income-edit' => $editing], fn ($v, $k) => $k === 'income-new' || $v, ARRAY_FILTER_USE_BOTH) as $mid => $inc)
        <x-modal :name="$mid" :title="$inc ? t('incomes.edit') : t('incomes.new')" :open="$inc ? true : ($errors->any() && ! $editing)">
            <form method="POST" action="{{ $inc ? '/finance/incomes/'.$inc->id : '/finance/incomes' }}" class="space-y-4">
                @csrf @if ($inc) @method('PUT') @endif
                <x-select name="category" :id="$mid.'-cat'" :label="t('incomes.category')" :value="$inc?->category" placeholder="—" :options="$categories" required />
                <div class="grid gap-4 sm:grid-cols-[1fr,7rem,10rem]">
                    <x-field name="amount" :id="$mid.'-amount'" inputmode="decimal" :label="t('incomes.amount')" :value="$inc ? rtrim(rtrim((string) $inc->amount, '0'), '.') : null" required />
                    <x-select name="currency" :id="$mid.'-cur'" :label="t('incomes.currency')" :value="$inc?->currency ?? 'UZS'" :options="['UZS' => 'UZS', 'USD' => 'USD']" />
                    <x-field name="income_date" type="date" :id="$mid.'-date'" :label="t('incomes.date')" :value="$inc?->income_date?->format('Y-m-d') ?? \App\Support\Format::today()->toDateString()" required />
                </div>
                <x-select name="project_id" :id="$mid.'-project'" :label="t('incomes.project')" :hint="t('incomes.projectHint')" :value="$inc?->project_id" :placeholder="t('incomes.noProject')" :options="$projects" />
                <x-field name="description" type="textarea" rows="2" :id="$mid.'-desc'" :label="t('incomes.description')" :value="$inc?->description" />
                <div class="flex justify-end gap-2">
                    <button type="button" class="btn btn-outline" @click="$dispatch('close-modal', '{{ $mid }}')">{{ t('incomes.cancel') }}</button>
                    <button class="btn btn-primary">{{ t('incomes.save') }}</button>
                </div>
            </form>
        </x-modal>
    @endforeach
@endsection
