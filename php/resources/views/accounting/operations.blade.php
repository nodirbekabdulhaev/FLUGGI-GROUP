@extends('layouts.app')
@section('title', t('accounting.operations.title'))
@section('content')
    @php
        use App\Services\Accounting\Accounting;
        $active = $accounts->where('is_active', true);
        $accOptions = $accounts->mapWithKeys(fn ($a) => [$a->id => $a->name.' · '.$a->currency.($a->is_active ? '' : ' ('.t('accounting.accounts.inactive').')')])->all();
        $activeOptions = $active->mapWithKeys(fn ($a) => [$a->id => $a->name.' · '.$a->currency])->all();
        $catLabel = fn (array $cats) => collect($cats)->mapWithKeys(fn ($c) => [$c => t('accounting.category.'.$c)])->all();
        $filtered = collect($filters)->filter()->isNotEmpty();
        $form = old('_form');
        $openOp = ($errors->any() && $form === 'op') || in_array($prefill['new'] ?? null, ['IN', 'OUT'], true);
        $openTransfer = ($errors->any() && $form === 'transfer') || ($prefill['new'] ?? null) === 'TRANSFER';
        $dir = old('direction', $prefill['new'] ?? 'OUT');
        $dir = in_array($dir, ['IN', 'OUT'], true) ? $dir : 'OUT';
        $cat = old('category', $prefill['p_category'] ?? ($dir === 'IN' ? 'OTHER_INCOME' : 'SUPPLIER'));
        $exportQs = http_build_query(array_filter($filters));
        // Своя статья → базовая категория (для полей формы, зависящих от категории)
        $articleBases = collect($articles)->flatMap(fn ($groups) => collect($groups)->flatten(1)->mapWithKeys(fn ($a) => [$a->id => $a->base]))->all();
    @endphp
    <div x-data="{ delId: null, delText: '' }">
    <x-page-header :title="t('accounting.operations.title')" :subtitle="t('accounting.operations.subtitle')">
        @if ($canExport)
            <a href="/accounting/operations/export{{ $exportQs ? '?'.$exportQs : '' }}" class="btn btn-outline"><x-icon name="download" /> {{ t('accounting.export') }}</a>
        @endif
        @if ($canManage)
            <a href="/accounting/bank-import" class="btn btn-outline"><x-icon name="upload" /> {{ t('accounting.bank.button') }}</a>
            <button type="button" class="btn btn-outline" @click="$dispatch('open-modal', 'acc-import')"><x-icon name="refresh" /> {{ t('accounting.import.button') }}</button>
            <button type="button" class="btn btn-outline" @click="$dispatch('open-modal', 'acc-transfer')"><x-icon name="refresh" /> {{ t('accounting.operations.newTransfer') }}</button>
            <button type="button" class="btn btn-primary" @click="$dispatch('open-modal', 'acc-op')"><x-icon name="plus" /> {{ t('accounting.operations.new') }}</button>
        @endif
    </x-page-header>
    @include('accounting.partials.tabs', ['active' => '/accounting/operations'])

    <div class="card overflow-hidden">
        <form method="GET" class="grid gap-3 border-b p-4 sm:grid-cols-2 lg:grid-cols-4">
            <x-select name="account" :label="t('accounting.fields.account')" :placeholder="t('accounting.operations.allAccounts')" :options="$accOptions" :value="$filters['account'] ?? ''" />
            <x-select name="direction" :label="t('accounting.fields.direction')" :placeholder="t('common.all')" :options="['IN' => t('accounting.direction.IN'), 'OUT' => t('accounting.direction.OUT')]" :value="$filters['direction'] ?? ''" />
            <div>
                <label class="label" for="of-category">{{ t('accounting.fields.category') }}</label>
                <select id="of-category" name="category" class="input">
                    <option value="">{{ t('accounting.operations.allCategories') }}</option>
                    <optgroup label="{{ t('accounting.operations.baseCategories') }}">
                        @foreach ($catLabel(Accounting::categories()) as $c => $label)<option value="{{ $c }}" @selected(($filters['category'] ?? '') === $c)>{{ $label }}</option>@endforeach
                    </optgroup>
                    @foreach (['IN', 'OUT'] as $d)
                        @php $list = $filterArticles->where('direction', $d); @endphp
                        @if ($list->isNotEmpty())
                            <optgroup label="{{ t('accounting.operations.articles'.$d) }}">
                                @foreach ($list as $a)<option value="{{ $a->id }}" @selected(($filters['category'] ?? '') === $a->id)>{{ $a->label() }}{{ $a->is_active ? '' : ' ('.t('accounting.categories.inactive').')' }}</option>@endforeach
                            </optgroup>
                        @endif
                    @endforeach
                </select>
            </div>
            <div><label class="label" for="of-q">{{ t('common.search') }}</label><input id="of-q" name="q" class="input" value="{{ $filters['q'] ?? '' }}" placeholder="{{ t('accounting.operations.searchHint') }}"></div>
            <div><label class="label" for="of-period">{{ t('accounting.fields.month') }}</label><input id="of-period" type="month" name="period" class="input" value="{{ $filters['period'] ?? '' }}"></div>
            <div><label class="label" for="of-from">{{ t('accounting.fields.from') }}</label><input id="of-from" type="date" name="from" class="input" value="{{ $filters['from'] ?? '' }}"></div>
            <div><label class="label" for="of-to">{{ t('accounting.fields.to') }}</label><input id="of-to" type="date" name="to" class="input" value="{{ $filters['to'] ?? '' }}"></div>
            <div class="flex items-end gap-2">
                <button class="btn btn-outline">{{ t('common.filter') }}</button>
                @if ($filtered)<a href="/accounting/operations" class="btn btn-ghost">{{ t('common.reset') }}</a>@endif
            </div>
        </form>
        @if ($totals)
            <div class="flex flex-wrap gap-x-6 gap-y-1 border-b px-4 py-3 text-sm">
                @foreach (['IN', 'OUT'] as $d)
                    @foreach ($totals[$d] ?? [] as $cur => $sum)
                        <span>{{ t('accounting.operations.total'.$d) }}: <span @class(['font-semibold tabular-nums', 'text-success' => $d === 'IN', 'text-danger' => $d === 'OUT'])>{{ money($sum, $cur) }}</span></span>
                    @endforeach
                @endforeach
                <span class="text-xs text-muted-foreground sm:self-center">{{ t('accounting.operations.totalsHint') }}</span>
            </div>
        @endif
        @if ($items->isEmpty())
            <x-empty icon="list" :title="t('accounting.operations.empty')">{{ t('accounting.operations.emptyText') }}</x-empty>
        @else
            <div class="hidden overflow-x-auto md:block">
                <table class="table">
                    <thead><tr>
                        <th>{{ t('accounting.fields.date') }}</th><th>{{ t('accounting.fields.category') }}</th><th>{{ t('accounting.fields.counterparty') }}</th>
                        <th>{{ t('accounting.fields.account') }}</th><th class="text-right">{{ t('accounting.fields.amount') }}</th>@if ($canManage)<th></th>@endif
                    </tr></thead>
                    <tbody>
                    @foreach ($items as $op)
                        <tr>
                            <td class="whitespace-nowrap">{{ d($op->date) }}</td>
                            <td>@if ($op->article){{ $op->article->name }}<span class="block text-xs text-muted-foreground">{{ t('accounting.category.'.$op->category) }}</span>@else{{ t('accounting.category.'.$op->category) }}@endif
                                @if ($op->period)<span class="block text-xs text-muted-foreground">{{ t('accounting.fields.period') }}: {{ $op->period }}</span>@endif
                                @if ($op->payment_id)<span class="block"><x-badge color="blue">{{ t('accounting.operations.fromCrm') }}</x-badge></span>@endif
                                @if ($op->source === 'BANK_IMPORT')<span class="block"><x-badge>{{ t('accounting.operations.fromBank') }}</x-badge></span>@endif
                            </td>
                            <td class="max-w-xs">{{ $op->person?->full_name ?? $op->counterparty ?? '—' }}
                                @if ($op->description)<span class="block truncate text-xs text-muted-foreground" title="{{ $op->description }}">{{ $op->description }}</span>@endif
                            </td>
                            <td class="whitespace-nowrap">{{ $op->account?->name }}</td>
                            <td class="text-right">@include('accounting.partials.op-row', ['op' => $op])</td>
                            @if ($canManage)
                                <td class="text-right">
                                    @unless ($op->payment_id)
                                        <button type="button" class="btn btn-ghost btn-icon" aria-label="{{ t('common.delete') }}"
                                                @click="delId = @js($op->id); delText = @js(d($op->date).' · '.$op->categoryLabel().' · '.money($op->amount, $op->account?->currency ?? 'UZS')); $dispatch('open-modal', 'acc-op-delete')"><x-icon name="trash" /></button>
                                    @endunless
                                </td>
                            @endif
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <ul class="divide-y md:hidden">
                @foreach ($items as $op)
                    <li class="flex items-start justify-between gap-3 p-4 text-sm">
                        <div class="min-w-0">
                            <p class="font-medium">{{ $op->article?->name ?? t('accounting.category.'.$op->category) }}@if ($op->payment_id) <x-badge color="blue">{{ t('accounting.operations.fromCrm') }}</x-badge>@endif</p>
                            <p class="truncate text-muted-foreground">{{ $op->person?->full_name ?? $op->counterparty ?? '—' }}</p>
                            @if ($op->article)<p class="text-xs text-muted-foreground">{{ t('accounting.category.'.$op->category) }}</p>@endif
                            <p class="text-xs text-muted-foreground">{{ d($op->date) }} · {{ $op->account?->name }}@if ($op->period) · {{ $op->period }}@endif</p>
                        </div>
                        <div class="flex flex-col items-end gap-1">
                            @include('accounting.partials.op-row', ['op' => $op])
                            @if ($canManage && ! $op->payment_id)
                                <button type="button" class="btn btn-ghost btn-sm" @click="delId = @js($op->id); delText = @js(d($op->date).' · '.$op->categoryLabel()); $dispatch('open-modal', 'acc-op-delete')">{{ t('common.delete') }}</button>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
            <div class="border-t px-3 py-2">{{ $items->links('vendor.pagination.fluggi') }}</div>
        @endif
    </div>

    @if ($canManage)
        <x-modal name="acc-op" :title="t('accounting.operations.new')" :open="$openOp">
            @if ($active->isEmpty())
                <p class="text-sm text-muted-foreground">{{ t('accounting.accounts.emptyText') }}</p>
            @else
            <form method="POST" action="/accounting/operations" class="space-y-4" x-data="{ dir: @js($dir), cat: @js($cat), bases: @js((object) $articleBases), get base() { return this.bases[this.cat] ?? this.cat } }">
                @csrf
                <input type="hidden" name="_form" value="op">
                <input type="hidden" name="direction" :value="dir">
                <div class="grid grid-cols-2 gap-2" role="radiogroup">
                    <button type="button" class="btn" :class="dir === 'IN' ? 'btn-primary' : 'btn-outline'" @click="dir = 'IN'; cat = 'OTHER_INCOME'">{{ t('accounting.direction.IN') }}</button>
                    <button type="button" class="btn" :class="dir === 'OUT' ? 'btn-primary' : 'btn-outline'" @click="dir = 'OUT'; cat = 'SUPPLIER'">{{ t('accounting.direction.OUT') }}</button>
                </div>
                <x-select name="account_id" :label="t('accounting.fields.account')" :options="$activeOptions" :value="old('account_id', $prefill['p_account'] ?? array_key_first($activeOptions))" required />
                <div>
                    <label class="label" for="op-cat-in">{{ t('accounting.fields.category') }}</label>
                    @foreach (['IN', 'OUT'] as $d)
                        <select @if ($d === 'IN') id="op-cat-in" @else aria-label="{{ t('accounting.fields.category') }}" @endif name="category" class="input" x-show="dir === @js($d)" :disabled="dir !== @js($d)" x-model="cat">
                            @if ($articles[$d])
                                <optgroup label="{{ t('accounting.operations.baseCategories') }}">
                                    @foreach (Accounting::manual($d) as $c)<option value="{{ $c }}">{{ t('accounting.category.'.$c) }}</option>@endforeach
                                </optgroup>
                                @foreach ($articles[$d] as $base => $list)
                                    <optgroup label="{{ t('accounting.operations.articlesOf', ['base' => t('accounting.category.'.$base)]) }}">
                                        @foreach ($list as $a)<option value="{{ $a->id }}">{{ $a->name }}</option>@endforeach
                                    </optgroup>
                                @endforeach
                            @else
                                @foreach (Accounting::manual($d) as $c)<option value="{{ $c }}">{{ t('accounting.category.'.$c) }}</option>@endforeach
                            @endif
                        </select>
                    @endforeach
                    @error('category')<p class="error">{{ $message }}</p>@enderror
                    <p class="hint" x-show="base !== cat" x-cloak>{{ t('accounting.operations.articleHint') }} <span x-text="@js($catLabel(Accounting::categories()))[base]"></span></p>
                    <p class="hint" x-show="base === 'CLIENT_PAYMENT'">{{ t('accounting.operations.manualClientHint') }}</p>
                    <p class="hint"><a class="link" href="/accounting/categories">{{ t('accounting.operations.manageArticles') }}</a></p>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-field name="amount" inputmode="decimal" data-money :label="t('accounting.fields.amount')" :value="old('amount', $prefill['p_amount'] ?? null)" required />
                    <x-field name="date" type="date" :label="t('accounting.fields.date')" :value="old('date', \App\Support\Format::today()->toDateString())" required />
                </div>
                <div x-show="base === 'SALARY'" x-cloak>
                    <x-select name="person_id" :label="t('accounting.fields.person')" :placeholder="'—'" :options="$people->mapWithKeys(fn ($p) => [$p->id => $p->full_name.($p->is_active ? '' : ' ('.t('accounting.people.inactive').')')])->all()" :value="old('person_id', $prefill['p_person'] ?? null)" />
                    @if ($people->isEmpty())<p class="hint">{{ t('accounting.people.emptyText') }}</p>@endif
                </div>
                <div x-show="base === 'SALARY' || base.startsWith('TAX_')" x-cloak>
                    <x-field name="period" type="month" :label="t('accounting.fields.period')" :hint="t('accounting.operations.periodHint')" :value="old('period', $prefill['p_period'] ?? null)" />
                </div>
                <div>
                    <label class="label" for="f-counterparty">{{ t('accounting.fields.counterparty') }}<span class="text-danger" x-show="base === 'SUPPLIER'"> *</span></label>
                    <input id="f-counterparty" name="counterparty" class="input" value="{{ old('counterparty') }}" maxlength="191" placeholder="{{ t('accounting.operations.counterpartyHint') }}">
                    @error('counterparty')<p class="error">{{ $message }}</p>@enderror
                </div>
                <x-field name="description" type="textarea" rows="2" :label="t('accounting.fields.description')" :value="old('description')" />
                <div class="flex justify-end gap-2">
                    <button type="button" class="btn btn-outline" @click="$dispatch('close-modal', 'acc-op')">{{ t('common.cancel') }}</button>
                    <button class="btn btn-primary">{{ t('common.save') }}</button>
                </div>
            </form>
            @endif
        </x-modal>

        <x-modal name="acc-transfer" :title="t('accounting.operations.newTransfer')" :open="$openTransfer">
            @if ($active->count() < 2)
                <p class="text-sm text-muted-foreground">{{ t('accounting.operations.transferNeedsTwo') }}</p>
            @else
            <form method="POST" action="/accounting/operations/transfer" class="space-y-4">
                @csrf
                <input type="hidden" name="_form" value="transfer">
                <p class="text-sm text-muted-foreground">{{ t('accounting.operations.transferHint') }}</p>
                <x-select name="from_id" :label="t('accounting.fields.fromAccount')" :options="$activeOptions" :value="old('from_id')" required />
                <x-select name="to_id" :label="t('accounting.fields.toAccount')" :options="$activeOptions" :value="old('to_id', array_keys($activeOptions)[1] ?? null)" required />
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-field name="amount" inputmode="decimal" data-money :label="t('accounting.fields.amount')" :value="old('amount')" required />
                    <x-field name="amount_to" inputmode="decimal" data-money :label="t('accounting.fields.amountTo')" :hint="t('accounting.operations.amountToHint')" :value="old('amount_to')" />
                    <x-field name="date" type="date" :label="t('accounting.fields.date')" :value="old('date', \App\Support\Format::today()->toDateString())" required />
                </div>
                <x-field name="description" type="textarea" rows="2" :label="t('accounting.fields.description')" :value="old('description')" />
                <div class="flex justify-end gap-2">
                    <button type="button" class="btn btn-outline" @click="$dispatch('close-modal', 'acc-transfer')">{{ t('common.cancel') }}</button>
                    <button class="btn btn-primary">{{ t('common.save') }}</button>
                </div>
            </form>
            @endif
        </x-modal>

        <x-modal name="acc-op-delete" :title="t('accounting.operations.deleteTitle')" :open="$errors->has('reason')">
            <form method="POST" :action="'/accounting/operations/' + delId" class="space-y-4">
                @csrf @method('DELETE')
                <p class="text-sm" x-text="delText"></p>
                <p class="text-sm text-muted-foreground">{{ t('accounting.operations.deleteHint') }}</p>
                <x-field name="reason" type="textarea" rows="2" :label="t('accounting.fields.reason')" required minlength="3" />
                <div class="flex justify-end gap-2">
                    <button type="button" class="btn btn-outline" @click="$dispatch('close-modal', 'acc-op-delete')">{{ t('common.cancel') }}</button>
                    <button class="btn btn-danger">{{ t('common.delete') }}</button>
                </div>
            </form>
        </x-modal>

        <x-modal name="acc-import" :title="t('accounting.import.title')" :open="$errors->any() && $form === 'import'">
            <form method="POST" action="/accounting/import-crm" class="space-y-4">
                @csrf
                <input type="hidden" name="_form" value="import">
                <p class="text-sm text-muted-foreground">{{ t('accounting.import.text') }}</p>
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-field name="from" type="date" :label="t('accounting.fields.from')" :value="old('from', \App\Support\Format::today()->startOfMonth()->toDateString())" required />
                    <x-field name="to" type="date" :label="t('accounting.fields.to')" :value="old('to', \App\Support\Format::today()->toDateString())" required />
                </div>
                <div class="flex justify-end gap-2">
                    <button type="button" class="btn btn-outline" @click="$dispatch('close-modal', 'acc-import')">{{ t('common.cancel') }}</button>
                    <button class="btn btn-primary">{{ t('accounting.import.run') }}</button>
                </div>
            </form>
        </x-modal>
    @endif
    </div>
@endsection
