@extends('layouts.app')
@section('title', t('settings.tabFinance'))
@section('content')
    <x-page-header :title="t('settings.title')" />
    @include('settings.partials.tabs')
    @php($canEdit = access()->can('reference.manage', 'ALL'))
    {{-- Работы исполнителей сохраняет модуль тарифов (маршруты sales.work-items.*) --}}
    @php($canWork = $canEdit && \Illuminate\Support\Facades\Route::has('sales.work-items.store'))
    <div class="grid gap-6 lg:grid-cols-2">
        @include('settings.partials.category-card', ['kind' => 'EXPENSE', 'items' => $expenseCategories])
        <div class="grid content-start gap-6">
            @include('settings.partials.category-card', ['kind' => 'INCOME', 'items' => $incomeCategories])
            @if (access()->can('finance.company.read', 'ALL'))
                <x-card :title="t('financeSettings.overheadTitle')" :subtitle="t('financeSettings.overheadText')">
                    <form method="POST" action="/settings/finance/overhead" class="grid gap-3 text-sm" x-data="{ mode: @js($overhead['overheadDivisor'] ? 'fixed' : 'auto') }">
                        @csrf @method('PUT')
                        <label class="flex items-center gap-2"><input type="radio" name="mode" value="auto" x-model="mode"> {{ t('financeSettings.overheadAuto') }}</label>
                        <label class="flex flex-wrap items-center gap-2"><input type="radio" name="mode" value="fixed" x-model="mode"> {{ t('financeSettings.overheadFixed') }}
                            <input type="number" name="divisor" min="1" max="1000" class="input h-8 w-20" aria-label="{{ t('financeSettings.divisor') }}" value="{{ $overhead['overheadDivisor'] ?? 5 }}" :disabled="mode !== 'fixed'"> {{ t('financeSettings.projects') }}</label>
                        <div><button class="btn btn-primary btn-sm">{{ t('financeSettings.save') }}</button></div>
                    </form>
                </x-card>
            @endif
        </div>
        <div class="lg:col-span-2">
            <x-card :title="t('financeSettings.workItems')" :subtitle="t('financeSettings.workItemsText')" :padding="false">
                @if ($canWork)
                    <x-slot:actions><button type="button" class="btn btn-primary btn-sm" @click="$dispatch('open-modal', 'wi-new')"><x-icon name="plus" /> {{ t('financeSettings.add') }}</button></x-slot:actions>
                @endif
                <div class="overflow-x-auto">
                    <table class="table">
                        <thead><tr><th>{{ t('financeSettings.name') }}</th><th>{{ t('financeSettings.specialty') }}</th><th class="text-right">{{ t('financeSettings.defaultRate') }}</th><th></th></tr></thead>
                        <tbody>
                        @foreach ($workItems as $w)
                            <tr @class(['opacity-60' => ! $w->is_active])>
                                <td class="font-medium">{{ $w->name }}</td>
                                <td class="text-muted-foreground">{{ $w->specialty ? t('specialties.'.$w->specialty) : '—' }}</td>
                                <td class="whitespace-nowrap text-right tabular-nums">{{ money($w->default_rate, $w->currency) }} / {{ $w->unit }}</td>
                                <td class="text-right">@if ($canWork)<button type="button" class="btn btn-ghost btn-icon" aria-label="{{ t('financeSettings.workItemEdit') }}: {{ $w->name }}" @click="$dispatch('open-modal', 'wi-{{ $w->id }}')"><x-icon name="edit" /></button>@endif</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </x-card>
        </div>
    </div>
    @if ($canWork)
        @foreach ([...$workItems->all(), null] as $w)
            @php($mid = $w ? 'wi-'.$w->id : 'wi-new')
            <x-modal :name="$mid" :title="$w ? t('financeSettings.workItemEdit') : t('financeSettings.workItemNew')">
                <form method="POST" action="{{ $w ? route('sales.work-items.update', $w->id) : route('sales.work-items.store') }}" class="space-y-4">
                    @csrf @if ($w) @method('PUT') @endif
                    <x-field name="name" :id="$mid.'-name'" :label="t('financeSettings.name')" :value="$w?->name" required />
                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-select name="specialty" :id="$mid.'-spec'" :label="t('financeSettings.specialty')" :value="$w?->specialty" placeholder="—" :options="collect(\App\Services\Projects\ProjectAccess::SPECIALTIES)->mapWithKeys(fn ($s) => [$s => t('specialties.'.$s)])->all()" />
                        <x-field name="unit" :id="$mid.'-unit'" :label="t('financeSettings.unit')" :value="$w?->unit ?? 'шт'" required />
                        <x-field name="default_rate" :id="$mid.'-rate'" inputmode="decimal" :label="t('financeSettings.defaultRate')" :value="$w ? rtrim(rtrim((string) $w->default_rate, '0'), '.') : null" required />
                        <x-select name="currency" :id="$mid.'-cur'" :label="t('financeSettings.currency')" :value="$w?->currency ?? 'UZS'" :options="['UZS' => 'UZS', 'USD' => 'USD']" />
                    </div>
                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_active" value="1" @checked($w?->is_active ?? true)> {{ t('financeSettings.active') }}</label>
                    <div class="flex justify-end gap-2">
                        <button type="button" class="btn btn-outline" @click="$dispatch('close-modal', '{{ $mid }}')">{{ t('financeSettings.cancel') }}</button>
                        <button class="btn btn-primary">{{ t('financeSettings.save') }}</button>
                    </div>
                </form>
            </x-modal>
        @endforeach
    @endif
@endsection
