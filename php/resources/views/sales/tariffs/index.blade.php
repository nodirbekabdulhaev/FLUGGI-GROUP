@extends('layouts.app')
@section('title', t('tariffs.title'))
@section('content')
    @php
        $svcName = fn ($s) => \App\Support\Lang::name($s);
        $all = $tariffs->flatten(1);
        $reopenTariff = old('_form') === 'tariff-editor' && $errors->any();
        $reopenWork = old('_form') === 'work-editor' && $errors->any();
        $init = [
            'tariffs' => $all->mapWithKeys(fn ($t) => [$t->id => [
                'id' => $t->id, 'service_id' => $t->service_id, 'name' => $t->name, 'description' => $t->description ?? '',
                'price' => \App\Services\Sales\Numbers::rate($t->price), 'currency' => $t->currency, 'is_active' => (bool) $t->is_active, 'sort' => (string) $t->sort,
                'items' => $t->items->map(fn ($i) => [
                    'kind' => $i->kind, 'work_item_id' => $i->work_item_id ?? '', 'quantity' => \App\Services\Sales\Numbers::rate($i->quantity),
                    'specialty' => $i->specialty ?? '', 'amount' => $i->amount !== null ? \App\Services\Sales\Numbers::rate($i->amount) : '', 'currency' => $i->currency, 'label' => $i->label ?? '',
                ])->values()->all(),
            ]]),
            'old' => $reopenTariff ? [
                'id' => old('_tariff_id') ?: null, 'service_id' => old('service_id', ''), 'name' => old('name'), 'description' => old('description'),
                'price' => old('price'), 'currency' => old('currency', 'USD'), 'is_active' => (bool) old('is_active'), 'sort' => old('sort', '0'),
                'items' => array_values(old('items', [])),
            ] : null,
        ];
        $works = $workItems->mapWithKeys(fn ($w) => [$w->id => [
            'id' => $w->id, 'name' => $w->name, 'unit' => $w->unit, 'specialty' => $w->specialty ?? '',
            'default_rate' => \App\Services\Sales\Numbers::rate($w->default_rate), 'currency' => $w->currency, 'is_active' => (bool) $w->is_active,
        ]]);
        $workInit = ['items' => $works, 'old' => $reopenWork ? [
            'id' => old('_work_id') ?: null, 'name' => old('name'), 'unit' => old('unit', 'шт'), 'specialty' => old('specialty', ''),
            'default_rate' => old('default_rate'), 'currency' => old('currency', 'UZS'), 'is_active' => (bool) old('is_active'),
        ] : null];
        $company = can('finance.company.read', 'ALL');
    @endphp
    <x-page-header :title="t('tariffs.title')" :subtitle="t('tariffs.intro')" :back="url('/settings')" />

    <div class="grid gap-6">
        @if ($services->isEmpty())
            <x-empty :title="t('tariffs.noServices')" />
        @endif
        @foreach ($services as $s)
            @php $list = $tariffs[$s->id] ?? collect(); @endphp
            <x-card :title="$svcName($s)" :subtitle="$list->count() ? t('tariffs.count', ['count' => $list->count()]) : t('tariffs.noTariffs')">
                <x-slot:actions>
                    <button type="button" class="btn btn-outline btn-sm" x-data x-on:click="$dispatch('open-tariff', { service: @js($s->id) })"><x-icon name="plus" /> {{ t('tariffs.add') }}</button>
                </x-slot:actions>
                @if ($list->isNotEmpty())
                    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                        @foreach ($list as $tf)
                            @php $e = $tf->economics; @endphp
                            <div @class(['grid content-start gap-3 rounded-lg border p-4', 'opacity-60' => ! $tf->is_active])>
                                <div class="flex items-start justify-between gap-2">
                                    <div class="min-w-0">
                                        <div class="font-semibold">{{ $tf->name }} @if (! $tf->is_active)<x-badge>{{ t('tariffs.off') }}</x-badge>@endif</div>
                                        <div class="text-lg font-semibold tabular-nums">{{ money($tf->price, $tf->currency) }}</div>
                                    </div>
                                    <button type="button" class="btn btn-ghost btn-icon" x-data x-on:click="$dispatch('open-tariff', { id: @js($tf->id) })" aria-label="{{ t('tariffs.edit') }}: {{ $svcName($s) }} {{ $tf->name }}"><x-icon name="edit" /></button>
                                </div>
                                @if ($tf->description)<p class="whitespace-pre-line text-sm text-muted-foreground">{{ $tf->description }}</p>@endif
                                <ul class="grid gap-1 text-sm">
                                    @forelse ($tf->items as $i)
                                        <li class="flex justify-between gap-2">
                                            <span class="min-w-0">{{ $i->kind === 'PIECE' ? ($i->workItem?->name ?? '—').' × '.\App\Services\Sales\Numbers::rate($i->quantity) : ($i->label ?: ($i->specialty ? t('specialties.'.$i->specialty) : '—')).' ('.mb_strtolower(t('tariffs.kindFixed')).')' }}</span>
                                            @if ($company)<span class="whitespace-nowrap tabular-nums text-muted-foreground">{{ money($i->cost_uzs) }}</span>@endif
                                        </li>
                                    @empty
                                        <li class="text-muted-foreground">{{ t('tariffs.noItems') }}</li>
                                    @endforelse
                                </ul>
                                @if ($e)
                                    <dl class="grid grid-cols-2 gap-x-3 gap-y-1 border-t pt-2 text-xs">
                                        <dt class="muted">{{ t('tariffs.priceUzs') }}</dt><dd class="text-right tabular-nums">{{ money($e['priceUzs']) }}</dd>
                                        <dt class="muted">{{ t('tariffs.executorsUzs') }}</dt><dd class="text-right tabular-nums">−{{ money($e['executorsUzs']) }}</dd>
                                        <dt class="muted">{{ t('tariffs.overheadUzs') }}</dt><dd class="text-right tabular-nums">−{{ money($e['overheadUzs']) }}</dd>
                                        <dt class="font-medium">{{ t('tariffs.marginUzs') }}</dt>
                                        <dd @class(['text-right font-medium tabular-nums', 'text-danger' => (float) $e['marginUzs'] < 0])>{{ money($e['marginUzs']) }}@if ($e['marginPct'] !== null) · {{ number_format((float) $e['marginPct'], 1, ',', '') }}%@endif</dd>
                                    </dl>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-card>
        @endforeach

        {{-- Единицы работ: базовые сдельные ставки (личные — в карточке сотрудника) --}}
        <x-card :title="t('tariffs.workItems')" :subtitle="t('tariffs.workItemsText')" :padding="false">
            <x-slot:actions>
                <button type="button" class="btn btn-outline btn-sm" x-data x-on:click="$dispatch('open-work', null)"><x-icon name="plus" /> {{ t('tariffs.workItemNew') }}</button>
            </x-slot:actions>
            @if ($workItems->isEmpty())
                <x-empty :title="t('tariffs.noWorkItems')" />
            @else
                <ul class="divide-y">
                    @foreach ($workItems as $w)
                        <li @class(['flex flex-wrap items-center gap-3 px-4 py-3 sm:px-5', 'opacity-60' => ! $w->is_active])>
                            <div class="min-w-0 flex-1">
                                <p class="font-medium">{{ $w->name }} @if (! $w->is_active)<x-badge>{{ t('tariffs.off') }}</x-badge>@endif</p>
                                <p class="text-xs text-muted-foreground">{{ $w->specialty ? t('specialties.'.$w->specialty) : '—' }} · {{ $w->unit }} · {{ $w->code }}</p>
                            </div>
                            <span class="tabular-nums">{{ money($w->default_rate, $w->currency) }}</span>
                            <button type="button" class="btn btn-ghost btn-icon" x-data x-on:click="$dispatch('open-work', @js($w->id))" aria-label="{{ t('tariffs.workItemEdit') }}: {{ $w->name }}"><x-icon name="edit" /></button>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-card>
    </div>

    {{-- Окно тарифа --}}
    <div x-data="tariffEditor(@js($init))" x-on:open-tariff.window="load($event.detail)" x-on:keydown.escape.window="show = false"
         x-show="show" x-cloak class="fixed inset-0 z-50 flex items-end justify-center sm:items-center sm:p-4" role="dialog" aria-modal="true">
        <div class="absolute inset-0 bg-black/40" x-on:click="show = false"></div>
        <div class="relative max-h-[94dvh] w-full max-w-3xl overflow-y-auto rounded-t-xl bg-surface shadow-xl sm:rounded-xl">
            <header class="sticky top-0 z-10 flex items-center justify-between border-b bg-surface px-4 py-3 sm:px-5">
                <h2 class="font-semibold" x-text="f.id ? @js(t('tariffs.edit')) : @js(t('tariffs.new'))"></h2>
                <button type="button" class="btn btn-ghost btn-icon" x-on:click="show = false" aria-label="{{ t('common.close') }}"><x-icon name="x" /></button>
            </header>
            <form method="POST" :action="f.id ? @js(url('/settings/tariffs')) + '/' + f.id : @js(route('sales.tariffs.store'))" class="grid gap-4 p-4 sm:p-5">
                @csrf
                <input type="hidden" name="_method" :value="f.id ? 'PUT' : 'POST'">
                <input type="hidden" name="_form" value="tariff-editor">
                <input type="hidden" name="_tariff_id" :value="f.id || ''">
                @if ($reopenTariff && $errors->any())<p class="rounded-md bg-danger-soft px-3 py-2 text-sm text-danger">{{ session('error') ?? $errors->first() }}</p>@endif
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="label" for="tf-svc">{{ t('tariffs.service') }}<span class="text-danger"> *</span></label>
                        <select id="tf-svc" name="service_id" class="input" x-model="f.service_id" required>
                            <option value="">—</option>
                            @foreach ($services as $s)<option value="{{ $s->id }}">{{ $svcName($s) }}</option>@endforeach
                        </select>
                    </div>
                    <div>
                        <label class="label" for="tf-name">{{ t('tariffs.name') }}<span class="text-danger"> *</span></label>
                        <input id="tf-name" name="name" class="input" x-model="f.name" required maxlength="60">
                        <p class="hint">{{ t('tariffs.nameHint') }}</p>
                    </div>
                    <div>
                        <label class="label" for="tf-price">{{ t('tariffs.price') }}<span class="text-danger"> *</span></label>
                        <input id="tf-price" name="price" class="input" inputmode="decimal" x-model="f.price" required>
                    </div>
                    <div>
                        <label class="label" for="tf-cur">{{ t('tariffs.currency') }}</label>
                        <select id="tf-cur" name="currency" class="input" x-model="f.currency"><option value="UZS">UZS</option><option value="USD">USD</option></select>
                    </div>
                </div>
                <div>
                    <label class="label" for="tf-desc">{{ t('tariffs.description') }}</label>
                    <textarea id="tf-desc" name="description" rows="2" class="input" x-model="f.description" maxlength="2000"></textarea>
                    <p class="hint">{{ t('tariffs.descriptionHint') }}</p>
                </div>
                <input type="hidden" name="sort" :value="f.sort">
                <div class="grid gap-2">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <span class="text-sm font-medium">{{ t('tariffs.items') }}</span>
                        <div class="flex gap-2">
                            <button type="button" class="btn btn-outline btn-sm" x-on:click="f.items.push(item('PIECE'))"><x-icon name="plus" /> {{ t('tariffs.addPiece') }}</button>
                            <button type="button" class="btn btn-outline btn-sm" x-on:click="f.items.push(item('FIXED'))"><x-icon name="plus" /> {{ t('tariffs.addFixed') }}</button>
                        </div>
                    </div>
                    <p class="text-xs text-muted-foreground">{{ t('tariffs.itemsText') }}</p>
                    <p class="text-sm text-muted-foreground" x-show="f.items.length === 0">{{ t('tariffs.noItems') }}</p>
                    <template x-for="(i, idx) in f.items" :key="i.key">
                        <div class="grid items-end gap-2 rounded-md border p-2 sm:grid-cols-[7rem_1fr_auto]">
                            <input type="hidden" :name="`items[${idx}][kind]`" :value="i.kind">
                            <span class="badge justify-self-start" :class="i.kind === 'PIECE' ? 'badge-blue' : 'badge-yellow'" x-text="i.kind === 'PIECE' ? @js(t('tariffs.kindPiece')) : @js(t('tariffs.kindFixed'))"></span>
                            <div class="grid gap-2" :class="i.kind === 'PIECE' ? 'sm:grid-cols-[1fr_6rem]' : 'sm:grid-cols-[1fr_8rem_5rem]'">
                                <template x-if="i.kind === 'PIECE'">
                                    <select class="input" :name="`items[${idx}][work_item_id]`" x-model="i.work_item_id" required aria-label="{{ t('tariffs.workItem') }}">
                                        <option value="">{{ t('tariffs.chooseWork') }}</option>
                                        @foreach ($workItems as $w)<option value="{{ $w->id }}" @if (! $w->is_active) x-show="i.work_item_id === @js($w->id)" @endif>{{ $w->name }} — {{ money($w->default_rate, $w->currency) }} / {{ $w->unit }}</option>@endforeach
                                    </select>
                                </template>
                                <template x-if="i.kind === 'PIECE'">
                                    <input class="input" :name="`items[${idx}][quantity]`" x-model="i.quantity" inputmode="decimal" required aria-label="{{ t('tariffs.quantity') }}">
                                </template>
                                <template x-if="i.kind === 'FIXED'">
                                    <select class="input" :name="`items[${idx}][specialty]`" x-model="i.specialty" required aria-label="{{ t('tariffs.specialty') }}">
                                        <option value="">{{ t('tariffs.chooseSpecialty') }}</option>
                                        @foreach (\App\Services\Sales\CatalogService::SPECIALTIES as $sp)<option value="{{ $sp }}">{{ t('specialties.'.$sp) }}</option>@endforeach
                                    </select>
                                </template>
                                <template x-if="i.kind === 'FIXED'">
                                    <input class="input" :name="`items[${idx}][amount]`" x-model="i.amount" inputmode="decimal" required aria-label="{{ t('tariffs.amount') }}">
                                </template>
                                <template x-if="i.kind === 'FIXED'">
                                    <select class="input" :name="`items[${idx}][currency]`" x-model="i.currency" aria-label="{{ t('tariffs.currency') }}"><option value="UZS">UZS</option><option value="USD">USD</option></select>
                                </template>
                                <template x-if="i.kind === 'FIXED'">
                                    <input class="input sm:col-span-3" :name="`items[${idx}][label]`" x-model="i.label" placeholder="{{ t('tariffs.label') }}" aria-label="{{ t('tariffs.label') }}" maxlength="120">
                                </template>
                            </div>
                            <button type="button" class="btn btn-ghost btn-icon" x-on:click="f.items.splice(idx, 1)" aria-label="{{ t('tariffs.removeItem') }}"><x-icon name="trash" /></button>
                        </div>
                    </template>
                </div>
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_active" value="1" x-model="f.is_active"> {{ t('tariffs.active') }}</label>
                <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                    <button type="button" class="btn btn-outline" x-on:click="show = false">{{ t('tariffs.cancel') }}</button>
                    <button class="btn btn-primary">{{ t('tariffs.save') }}</button>
                </div>
            </form>
        </div>
    </div>

    {{-- Окно единицы работ --}}
    <div x-data="workEditor(@js($workInit))" x-on:open-work.window="load($event.detail)" x-on:keydown.escape.window="show = false"
         x-show="show" x-cloak class="fixed inset-0 z-50 flex items-end justify-center sm:items-center sm:p-4" role="dialog" aria-modal="true">
        <div class="absolute inset-0 bg-black/40" x-on:click="show = false"></div>
        <div class="relative max-h-[94dvh] w-full max-w-lg overflow-y-auto rounded-t-xl bg-surface shadow-xl sm:rounded-xl">
            <header class="sticky top-0 z-10 flex items-center justify-between border-b bg-surface px-4 py-3 sm:px-5">
                <h2 class="font-semibold" x-text="f.id ? @js(t('tariffs.workItemEdit')) : @js(t('tariffs.workItemNew'))"></h2>
                <button type="button" class="btn btn-ghost btn-icon" x-on:click="show = false" aria-label="{{ t('common.close') }}"><x-icon name="x" /></button>
            </header>
            <form method="POST" :action="f.id ? @js(url('/settings/work-items')) + '/' + f.id : @js(route('sales.work-items.store'))" class="grid gap-4 p-4 sm:p-5">
                @csrf
                <input type="hidden" name="_method" :value="f.id ? 'PUT' : 'POST'">
                <input type="hidden" name="_form" value="work-editor">
                <input type="hidden" name="_work_id" :value="f.id || ''">
                @if ($reopenWork)<p class="rounded-md bg-danger-soft px-3 py-2 text-sm text-danger">{{ $errors->first() }}</p>@endif
                <div><label class="label" for="wi-name">{{ t('tariffs.workName') }}<span class="text-danger"> *</span></label><input id="wi-name" name="name" class="input" x-model="f.name" required maxlength="120"></div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div><label class="label" for="wi-unit">{{ t('tariffs.unit') }}</label><input id="wi-unit" name="unit" class="input" x-model="f.unit" maxlength="20"></div>
                    <div>
                        <label class="label" for="wi-spec">{{ t('tariffs.specialty') }}</label>
                        <select id="wi-spec" name="specialty" class="input" x-model="f.specialty">
                            <option value="">—</option>
                            @foreach (\App\Services\Sales\CatalogService::SPECIALTIES as $sp)<option value="{{ $sp }}">{{ t('specialties.'.$sp) }}</option>@endforeach
                        </select>
                    </div>
                    <div><label class="label" for="wi-rate">{{ t('tariffs.defaultRate') }}<span class="text-danger"> *</span></label><input id="wi-rate" name="default_rate" class="input" inputmode="decimal" x-model="f.default_rate" required></div>
                    <div><label class="label" for="wi-cur">{{ t('tariffs.currency') }}</label><select id="wi-cur" name="currency" class="input" x-model="f.currency"><option value="UZS">UZS</option><option value="USD">USD</option></select></div>
                </div>
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_active" value="1" x-model="f.is_active"> {{ t('tariffs.workActive') }}</label>
                <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                    <button type="button" class="btn btn-outline" x-on:click="show = false">{{ t('tariffs.cancel') }}</button>
                    <button class="btn btn-primary">{{ t('tariffs.save') }}</button>
                </div>
            </form>
        </div>
    </div>
@endsection
@push('scripts')
<script>
    function tariffEditor(cfg) {
        let seq = 0;
        const item = (kind, i = {}) => ({ key: ++seq, kind: i.kind ?? kind, work_item_id: i.work_item_id ?? '', quantity: String(i.quantity ?? '1'), specialty: i.specialty ?? '', amount: i.amount ?? '', currency: i.currency ?? 'UZS', label: i.label ?? '' });
        const blank = (service = '') => ({ id: null, service_id: service, name: '', description: '', price: '', currency: 'USD', is_active: true, sort: '0', items: [] });
        return {
            ...cfg, show: false, f: blank(), item,
            init() { if (cfg.old) { this.f = { ...blank(), ...cfg.old, items: cfg.old.items.map((i) => item(i.kind, i)) }; this.show = true; } },
            load(d) {
                const t = d && d.id ? this.tariffs[d.id] : null;
                this.f = t ? { ...blank(), ...t, items: t.items.map((i) => item(i.kind, i)) } : blank(d?.service ?? '');
                this.show = true;
            },
        };
    }
    function workEditor(cfg) {
        const blank = () => ({ id: null, name: '', unit: 'шт', specialty: '', default_rate: '', currency: 'UZS', is_active: true });
        return {
            ...cfg, show: false, f: blank(),
            init() { if (cfg.old) { this.f = { ...blank(), ...cfg.old }; this.show = true; } },
            load(id) { this.f = id && this.items[id] ? { ...blank(), ...this.items[id] } : blank(); this.show = true; },
        };
    }
</script>
@endpush
