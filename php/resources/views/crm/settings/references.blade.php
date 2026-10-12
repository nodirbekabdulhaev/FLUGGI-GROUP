@extends('layouts.app')
@section('title', t('references.title'))
@section('content')
    @include('crm.partials.errors')
@php
    $plain = fn ($v) => $v === null ? '' : rtrim(rtrim((string) $v, '0'), '.');
    $itemJs = fn ($kind, $r) => [
        'url' => $r ? route('crm.references.items.update', [$kind, $r->id]) : route('crm.references.items.store', $kind), 'method' => $r ? 'PUT' : 'POST',
        'kind' => $kind, 'code' => $r?->code ?? '', 'name' => $r?->name_ru ?? '', 'name_uz' => $r?->name_uz ?? '', 'name_en' => $r?->name_en ?? '',
        'sort' => $r?->sort ?? 0, 'is_active' => $r?->is_active ?? true, 'requires_comment' => (bool) ($r?->requires_comment ?? false),
    ];
    $serviceJs = fn ($s) => [
        'url' => $s ? route('crm.references.services.update', $s->id) : route('crm.references.services.store'), 'method' => $s ? 'PUT' : 'POST',
        'code' => $s?->code ?? '', 'name' => $s?->name_ru ?? '', 'name_uz' => $s?->name_uz ?? '', 'name_en' => $s?->name_en ?? '',
        'description' => $s?->description ?? '', 'base_price' => $plain($s?->base_price), 'min_price' => $plain($s?->min_price),
        'currency' => $s?->currency ?? 'UZS', 'pricing_type' => $s?->pricing_type ?? 'FIXED', 'direction_id' => $s?->direction_id ?? '',
        'sort' => $s?->sort ?? 0, 'is_active' => $s?->is_active ?? true,
    ];
@endphp
    <x-page-header :title="t('references.title')" back="/settings" />
    <div class="grid gap-6">
        {{-- Курс USD: новые суммы в USD пересчитываются по последнему курсу --}}
        <x-card :title="t('references.usdRate')" :subtitle="t('references.rateHint')">
            <div class="flex flex-wrap items-end gap-4">
                <div class="min-w-48">
                    @if ($rate)
                        <p class="text-2xl font-semibold tabular-nums">{{ money($rate->rate_to_uzs) }}</p>
                        <p class="text-xs text-muted-foreground">{{ t('references.lastSetBy', ['date' => d($rate->date), 'by' => $rate->setBy?->full_name ?? 'none']) }}</p>
                    @else
                        <p class="text-sm text-danger">{{ t('references.rateMissing') }}</p>
                    @endif
                </div>
                @if (can('finance.company.read', 'ALL'))
                    <form method="POST" action="{{ route('crm.references.rate') }}" class="flex flex-wrap items-end gap-2">
                        @csrf @method('PUT')
                        <x-field name="rate_to_uzs" :label="t('references.rate')" inputmode="decimal" class="w-40" required />
                        <x-field name="date" type="date" :label="t('references.rateDate')" :value="\App\Support\Format::today()->toDateString()" />
                        <button type="submit" class="btn btn-primary">{{ t('common.save') }}</button>
                    </form>
                @endif
            </div>
        </x-card>

        <div class="grid gap-6 lg:grid-cols-2">
            <x-card :title="t('references.services')" :padding="false">
                <x-slot:actions>@if ($canEdit)<button type="button" class="btn btn-outline btn-sm" x-data x-on:click="$dispatch('ref-service', @js($serviceJs(null))); $dispatch('open-modal', 'ref-service')"><x-icon name="plus" /> {{ t('references.add') }}</button>@endif</x-slot:actions>
                <ul class="divide-y">
                    @foreach ($services as $s)
                        <li class="flex flex-wrap items-center gap-2 px-4 py-2.5 text-sm sm:px-5">
                            <span class="min-w-0 flex-1">{{ $s->label() }} <span class="text-xs text-muted-foreground">{{ $s->code }}</span></span>
                            @if ($s->direction)<x-badge color="blue">{{ $s->direction->name }}</x-badge>@endif
                            @if ($s->base_price !== null)<span class="text-muted-foreground">{{ money($s->base_price, $s->currency) }}</span>@endif
                            @unless ($s->is_active)<x-badge color="yellow">{{ t('references.inactive') }}</x-badge>@endunless
                            @if ($canEdit)<button type="button" class="btn btn-ghost btn-icon h-8 w-8" aria-label="{{ t('common.edit') }}" x-data x-on:click="$dispatch('ref-service', @js($serviceJs($s))); $dispatch('open-modal', 'ref-service')"><x-icon name="edit" /></button>@endif
                        </li>
                    @endforeach
                </ul>
            </x-card>
            <div class="grid content-start gap-6">
                <x-card :title="t('references.directions')" :subtitle="t('references.directionsText')" :padding="false">
                    <x-slot:actions>@if ($canEdit)<button type="button" class="btn btn-outline btn-sm" x-data x-on:click="$dispatch('ref-direction', { url: @js(route('crm.references.directions.store')), method: 'POST', name: '', sort: 100, is_active: true }); $dispatch('open-modal', 'ref-direction')"><x-icon name="plus" /> {{ t('references.add') }}</button>@endif</x-slot:actions>
                    <ul class="divide-y">
                        @foreach ($directions as $d)
                            <li class="flex items-center gap-2 px-4 py-2.5 text-sm sm:px-5">
                                <span class="flex-1">{{ $d->name }}</span>
                                @unless ($d->is_active)<x-badge color="yellow">{{ t('references.inactive') }}</x-badge>@endunless
                                @if ($canEdit)<button type="button" class="btn btn-ghost btn-icon h-8 w-8" aria-label="{{ t('common.edit') }}" x-data x-on:click="$dispatch('ref-direction', @js(['url' => route('crm.references.directions.update', $d->id), 'method' => 'PUT', 'name' => $d->name, 'sort' => $d->sort, 'is_active' => $d->is_active])); $dispatch('open-modal', 'ref-direction')"><x-icon name="edit" /></button>@endif
                            </li>
                        @endforeach
                    </ul>
                </x-card>
                @foreach (['sources' => [t('references.sources'), $sources], 'loss-reasons' => [t('references.lossReasons'), $lossReasons]] as $kind => [$title, $items])
                    <x-card :title="$title" :padding="false">
                        <x-slot:actions>@if ($canEdit)<button type="button" class="btn btn-outline btn-sm" x-data x-on:click="$dispatch('ref-item', @js($itemJs($kind, null))); $dispatch('open-modal', 'ref-item')"><x-icon name="plus" /> {{ t('references.add') }}</button>@endif</x-slot:actions>
                        <ul class="divide-y">
                            @foreach ($items as $r)
                                <li class="flex flex-wrap items-center gap-2 px-4 py-2.5 text-sm sm:px-5">
                                    <span class="min-w-0 flex-1">{{ $r->label() }} <span class="text-xs text-muted-foreground">{{ $r->code }}</span></span>
                                    @if ($r->requires_comment ?? false)<x-badge>{{ t('references.requiresComment') }}</x-badge>@endif
                                    @unless ($r->is_active)<x-badge color="yellow">{{ t('references.inactive') }}</x-badge>@endunless
                                    @if ($canEdit)<button type="button" class="btn btn-ghost btn-icon h-8 w-8" aria-label="{{ t('common.edit') }}" x-data x-on:click="$dispatch('ref-item', @js($itemJs($kind, $r))); $dispatch('open-modal', 'ref-item')"><x-icon name="edit" /></button>@endif
                                </li>
                            @endforeach
                        </ul>
                    </x-card>
                @endforeach
            </div>
        </div>

        <x-card :title="t('references.stages')" :subtitle="t('references.stagesHint')" :padding="false">
            <ul class="divide-y">
                @foreach ($stages as $s)
                    <li class="px-4 py-2.5 sm:px-5">
                        <form method="POST" action="{{ route('crm.references.stages.update', $s->id) }}" class="flex flex-wrap items-center gap-2 text-sm">
                            @csrf @method('PUT')
                            <span class="size-2.5 shrink-0 rounded-full" style="background-color: {{ $s->color }}" aria-hidden="true"></span>
                            <x-badge>{{ $s->entity === 'LEAD' ? t('references.stageLead') : t('references.stageDeal') }}</x-badge>
                            <input name="name" value="{{ $s->name_ru }}" class="input h-8 min-w-40 flex-1" @disabled(! $canEdit) aria-label="{{ t('references.stageName') }}" required maxlength="80">
                            <input name="name_uz" value="{{ $s->name_uz }}" class="input h-8 min-w-40 flex-1" @disabled(! $canEdit) aria-label="{{ t('references.nameUz') }}" placeholder="{{ t('references.nameUz') }}" maxlength="80">
                            <input name="name_en" value="{{ $s->name_en }}" class="input h-8 min-w-40 flex-1" @disabled(! $canEdit) aria-label="{{ t('references.nameEn') }}" placeholder="{{ t('references.nameEn') }}" maxlength="80">
                            <span class="flex items-center gap-1"><input name="probability" type="number" min="0" max="100" value="{{ $s->probability }}" class="input h-8 w-20" @disabled(! $canEdit) aria-label="{{ t('references.probability') }}"><span class="text-muted-foreground">%</span></span>
                            <input name="color" type="color" value="{{ $s->color }}" class="h-8 w-10 rounded border" @disabled(! $canEdit) aria-label="{{ t('references.color') }}">
                            @if ($canEdit)<button type="submit" class="btn btn-outline btn-sm">{{ t('common.save') }}</button>@endif
                        </form>
                    </li>
                @endforeach
            </ul>
        </x-card>
    </div>

    @if ($canEdit)
        <x-modal name="ref-service" :title="t('references.service')" size="max-w-2xl">
            <form method="POST" class="grid gap-4" x-data="{ s: @js($serviceJs(null)) }" x-on:ref-service.window="s = $event.detail" :action="s.url">
                @csrf <input type="hidden" name="_method" :value="s.method">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div><label class="label" for="rs-name">{{ t('references.name') }} *</label><input id="rs-name" name="name" class="input" required maxlength="120" x-model="s.name"></div>
                    <div><label class="label" for="rs-code">{{ t('references.code') }} *</label><input id="rs-code" name="code" class="input" required maxlength="40" x-model="s.code" x-on:input="s.code = s.code.toUpperCase()"><p class="hint">A–Z, 0–9, _</p></div>
                    <div><label class="label" for="rs-uz">{{ t('references.nameUz') }}</label><input id="rs-uz" name="name_uz" class="input" maxlength="120" x-model="s.name_uz"></div>
                    <div><label class="label" for="rs-en">{{ t('references.nameEn') }}</label><input id="rs-en" name="name_en" class="input" maxlength="120" x-model="s.name_en"></div>
                    <div><label class="label" for="rs-bp">{{ t('references.basePrice') }}</label><input id="rs-bp" name="base_price" inputmode="decimal" class="input" x-model="s.base_price"></div>
                    <div><label class="label" for="rs-mp">{{ t('references.minPrice') }}</label><input id="rs-mp" name="min_price" inputmode="decimal" class="input" x-model="s.min_price"></div>
                    <div><label class="label" for="rs-cur">{{ t('crm.fields.currency') }}</label><select id="rs-cur" name="currency" class="input" x-model="s.currency">@foreach (\App\Services\Crm\Crm::CURRENCIES as $c)<option>{{ $c }}</option>@endforeach</select></div>
                    <div><label class="label" for="rs-pt">{{ t('references.pricingType') }}</label><select id="rs-pt" name="pricing_type" class="input" x-model="s.pricing_type">@foreach (\App\Services\Crm\Crm::PRICING_TYPES as $p)<option value="{{ $p }}">{{ t('references.pricing.'.$p) }}</option>@endforeach</select></div>
                    <div><label class="label" for="rs-dir">{{ t('references.direction') }}</label><select id="rs-dir" name="direction_id" class="input" x-model="s.direction_id"><option value="">{{ t('references.noDirection') }}</option>@foreach ($directions as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach</select><p class="hint">{{ t('references.directionHint') }}</p></div>
                    <div><label class="label" for="rs-sort">{{ t('references.sort') }}</label><input id="rs-sort" name="sort" type="number" min="0" max="10000" class="input" x-model="s.sort"></div>
                </div>
                <div><label class="label" for="rs-desc">{{ t('references.description') }}</label><textarea id="rs-desc" name="description" rows="2" maxlength="2000" class="input h-auto py-2" x-model="s.description"></textarea></div>
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_active" value="1" class="size-4" x-model="s.is_active"> {{ t('references.active') }}</label>
                <div class="flex justify-end gap-2">
                    <button type="button" class="btn btn-outline" x-on:click="$dispatch('close-modal', 'ref-service')">{{ t('common.cancel') }}</button>
                    <button type="submit" class="btn btn-primary">{{ t('common.save') }}</button>
                </div>
            </form>
        </x-modal>
        <x-modal name="ref-item" :title="t('references.item')">
            <form method="POST" class="grid gap-4" x-data="{ r: @js($itemJs('sources', null)) }" x-on:ref-item.window="r = $event.detail" :action="r.url">
                @csrf <input type="hidden" name="_method" :value="r.method">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div><label class="label" for="ri-name">{{ t('references.name') }} *</label><input id="ri-name" name="name" class="input" required maxlength="120" x-model="r.name"></div>
                    <div><label class="label" for="ri-code">{{ t('references.code') }} *</label><input id="ri-code" name="code" class="input" required maxlength="40" x-model="r.code" x-on:input="r.code = r.code.toUpperCase()"><p class="hint">A–Z, 0–9, _</p></div>
                    <div><label class="label" for="ri-uz">{{ t('references.nameUz') }}</label><input id="ri-uz" name="name_uz" class="input" maxlength="120" x-model="r.name_uz"></div>
                    <div><label class="label" for="ri-en">{{ t('references.nameEn') }}</label><input id="ri-en" name="name_en" class="input" maxlength="120" x-model="r.name_en"></div>
                    <div><label class="label" for="ri-sort">{{ t('references.sort') }}</label><input id="ri-sort" name="sort" type="number" min="0" max="10000" class="input" x-model="r.sort"></div>
                </div>
                <div class="flex flex-wrap gap-4 text-sm">
                    <label class="flex items-center gap-2"><input type="checkbox" name="is_active" value="1" class="size-4" x-model="r.is_active"> {{ t('references.active') }}</label>
                    <label class="flex items-center gap-2" x-show="r.kind === 'loss-reasons'"><input type="checkbox" name="requires_comment" value="1" class="size-4" x-model="r.requires_comment"> {{ t('references.requiresComment') }}</label>
                </div>
                <div class="flex justify-end gap-2">
                    <button type="button" class="btn btn-outline" x-on:click="$dispatch('close-modal', 'ref-item')">{{ t('common.cancel') }}</button>
                    <button type="submit" class="btn btn-primary">{{ t('common.save') }}</button>
                </div>
            </form>
        </x-modal>
        <x-modal name="ref-direction" :title="t('references.newDirection')">
            <form method="POST" class="grid gap-4" x-data="{ d: { url: @js(route('crm.references.directions.store')), method: 'POST', name: '', sort: 100, is_active: true } }" x-on:ref-direction.window="d = $event.detail" :action="d.url">
                @csrf <input type="hidden" name="_method" :value="d.method">
                <div><label class="label" for="rd-name">{{ t('references.name') }} *</label><input id="rd-name" name="name" class="input" required maxlength="80" x-model="d.name"></div>
                <div><label class="label" for="rd-sort">{{ t('references.sort') }}</label><input id="rd-sort" name="sort" type="number" min="0" max="1000" class="input" x-model="d.sort"></div>
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_active" value="1" class="size-4" x-model="d.is_active"> {{ t('references.active') }}</label>
                <div class="flex justify-end gap-2">
                    <button type="button" class="btn btn-outline" x-on:click="$dispatch('close-modal', 'ref-direction')">{{ t('common.cancel') }}</button>
                    <button type="submit" class="btn btn-primary">{{ t('common.save') }}</button>
                </div>
            </form>
        </x-modal>
    @endif
@endsection
