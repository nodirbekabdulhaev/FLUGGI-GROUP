@extends('layouts.app')
@section('title', t('commissionRules.title'))
@section('content')
    @php
        $reopen = in_array(old('_form'), ['rule-editor'], true) && $errors->any();
        $json = $rules->map(fn ($r) => [
            'id' => $r->id, 'name' => $r->name, 'applies_to' => $r->applies_to, 'user_id' => $r->user_id ?? '', 'calc_type' => $r->calc_type,
            'value' => \App\Services\Sales\Numbers::rate($r->value), 'priority' => (string) $r->priority, 'is_active' => (bool) $r->is_active,
        ] + \App\Services\Sales\CommissionsService::readConditions($r->conditions))->keyBy('id');
        $init = [
            'rules' => $json,
            'old' => $reopen ? [
                'id' => old('_rule_id') ?: null, 'name' => old('name'), 'applies_to' => old('applies_to', 'MANAGER'), 'user_id' => old('user_id', ''),
                'calc_type' => old('calc_type', 'PERCENT_OF_PAYMENT'), 'value' => old('value'), 'priority' => old('priority', '0'),
                'is_active' => (bool) old('is_active'), 'mode' => old('mode', 'all'), 'leaves' => array_values(old('leaves', [])),
            ] : null,
            'hints' => collect(\App\Services\Sales\CommissionsService::CALC_TYPES)->mapWithKeys(fn ($c) => [$c => t('commissionRules.calcHint.'.$c)]),
        ];
    @endphp
    <x-page-header :title="t('commissionRules.title')" :subtitle="t('commissionRules.subtitle')" :back="url('/settings')">
        <button type="button" class="btn btn-primary btn-sm" x-data x-on:click="$dispatch('open-rule', null)"><x-icon name="plus" /> {{ t('commissionRules.new') }}</button>
    </x-page-header>

    <div class="card">
        @if ($rules->isEmpty())
            <x-empty icon="dollar" :title="t('commissionRules.empty')" />
        @else
            <ul class="divide-y">
                @foreach ($rules as $r)
                    <li class="flex flex-wrap items-center gap-3 px-4 py-3 sm:px-5">
                        <div class="min-w-0 flex-1">
                            <p class="font-medium">{{ $r->name }}</p>
                            <p class="text-sm text-muted-foreground">
                                {{ t('commissionRules.role.'.$r->applies_to) }}@if ($r->user_name) · {{ $r->user_name }}@endif · {{ t('commissionRules.calc.'.$r->calc_type) }}
                                {{ $r->calc_type === 'FIXED_PER_DEAL' ? money($r->value) : \App\Services\Sales\Numbers::rate($r->value).'%' }} · {{ t('commissionRules.priority') }} {{ $r->priority }}
                            </p>
                            <p class="text-xs text-muted-foreground">{{ \App\Services\Sales\CommissionsService::describe($r->conditions) }}</p>
                        </div>
                        <x-badge :color="$r->is_active ? 'green' : 'gray'">{{ $r->is_active ? t('commissionRules.active') : t('commissionRules.inactive') }}</x-badge>
                        <button type="button" class="btn btn-ghost btn-icon" x-data x-on:click="$dispatch('open-rule', @js($r->id))" aria-label="{{ t('commissionRules.edit') }}"><x-icon name="edit" /></button>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    <div x-data="ruleEditor(@js($init))" x-on:open-rule.window="load($event.detail)" x-on:keydown.escape.window="show = false"
         x-show="show" x-cloak class="fixed inset-0 z-50 flex items-end justify-center sm:items-center sm:p-4" role="dialog" aria-modal="true">
        <div class="absolute inset-0 bg-black/40" x-on:click="show = false"></div>
        <div class="relative max-h-[94dvh] w-full max-w-2xl overflow-y-auto rounded-t-xl bg-surface shadow-xl sm:rounded-xl">
            <header class="sticky top-0 z-10 flex items-center justify-between border-b bg-surface px-4 py-3 sm:px-5">
                <h2 class="font-semibold" x-text="f.id ? @js(t('commissionRules.edit')) : @js(t('commissionRules.new'))"></h2>
                <button type="button" class="btn btn-ghost btn-icon" x-on:click="show = false" aria-label="{{ t('common.close') }}"><x-icon name="x" /></button>
            </header>
            <form method="POST" :action="f.id ? @js(url('/settings/commission-rules')) + '/' + f.id : @js(route('sales.commission-rules.store'))" class="grid gap-4 p-4 sm:p-5">
                @csrf
                <input type="hidden" name="_method" :value="f.id ? 'PUT' : 'POST'">
                <input type="hidden" name="_form" value="rule-editor">
                <input type="hidden" name="_rule_id" :value="f.id || ''">
                @if ($reopen && session('error'))<p class="rounded-md bg-danger-soft px-3 py-2 text-sm text-danger">{{ session('error') }}</p>@endif
                <div>
                    <label class="label" for="cr-name">{{ t('commissionRules.name') }}<span class="text-danger"> *</span></label>
                    <input id="cr-name" name="name" class="input" x-model="f.name" required maxlength="120">
                    @if ($reopen)@error('name')<p class="error">{{ $message }}</p>@enderror @endif
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="label" for="cr-role">{{ t('commissionRules.appliesTo') }}</label>
                        <select id="cr-role" name="applies_to" class="input" x-model="f.applies_to">
                            @foreach (\App\Services\Sales\CommissionsService::ROLES as $role)<option value="{{ $role }}">{{ t('commissionRules.role.'.$role) }}</option>@endforeach
                        </select>
                    </div>
                    <div>
                        <label class="label" for="cr-user">{{ t('commissionRules.user') }}</label>
                        <select id="cr-user" name="user_id" class="input" x-model="f.user_id">
                            <option value="">{{ t('commissionRules.userAll') }}</option>
                            @foreach ($users as $u)<option value="{{ $u->id }}">{{ $u->full_name }}</option>@endforeach
                        </select>
                        <p class="hint">{{ t('commissionRules.userHint') }}</p>
                    </div>
                    <div>
                        <label class="label" for="cr-calc">{{ t('commissionRules.calcType') }}</label>
                        <select id="cr-calc" name="calc_type" class="input" x-model="f.calc_type">
                            @foreach (\App\Services\Sales\CommissionsService::CALC_TYPES as $c)<option value="{{ $c }}">{{ t('commissionRules.calc.'.$c) }}</option>@endforeach
                        </select>
                        <p class="hint" x-text="hints[f.calc_type]"></p>
                    </div>
                    <div>
                        <label class="label" for="cr-value" x-text="f.calc_type === 'FIXED_PER_DEAL' ? @js(t('commissionRules.valueFixed')) : @js(t('commissionRules.valuePct'))"></label>
                        <input id="cr-value" name="value" class="input" inputmode="decimal" data-money x-model="f.value" required>
                        @if ($reopen)@error('value')<p class="error">{{ $message }}</p>@enderror @endif
                    </div>
                    <div>
                        <label class="label" for="cr-prio">{{ t('commissionRules.priority') }}</label>
                        <input id="cr-prio" name="priority" type="number" min="0" max="1000" class="input" x-model="f.priority">
                    </div>
                </div>
                <fieldset class="grid gap-2 rounded-md border p-3">
                    <legend class="px-1 text-sm font-medium">{{ t('commissionRules.conditions') }}</legend>
                    <p class="text-sm text-muted-foreground" x-show="f.leaves.length === 0">{{ t('commissionRules.noConditions') }}</p>
                    <label class="flex flex-wrap items-center gap-2 text-sm" x-show="f.leaves.length > 0">
                        {{ t('commissionRules.match') }}
                        <select name="mode" class="input w-auto" x-model="f.mode">
                            <option value="all">{{ t('commissionRules.all') }}</option>
                            <option value="any">{{ t('commissionRules.any') }}</option>
                        </select>
                    </label>
                    <template x-for="(l, i) in f.leaves" :key="l.key">
                        <div class="grid gap-2 sm:grid-cols-[1fr_5rem_9rem_auto]">
                            <select class="input" :name="`leaves[${i}][metric]`" x-model="l.metric" aria-label="{{ t('commissionRules.metricLabel') }}">
                                @foreach (\App\Services\Sales\CommissionsService::METRICS as $m)<option value="{{ $m }}">{{ t('commissionRules.metric.'.$m) }}</option>@endforeach
                            </select>
                            <select class="input" :name="`leaves[${i}][op]`" x-model="l.op" aria-label="{{ t('commissionRules.opLabel') }}">
                                @foreach (\App\Services\Sales\CommissionsService::OPS as $o)<option value="{{ $o }}">{{ $o }}</option>@endforeach
                            </select>
                            <input class="input" :name="`leaves[${i}][value]`" x-model="l.value" inputmode="decimal" data-money data-allow-negative required aria-label="{{ t('commissionRules.valueLabel') }}">
                            <button type="button" class="btn btn-ghost btn-icon" x-on:click="f.leaves.splice(i, 1)" aria-label="{{ t('commissionRules.removeCondition') }}"><x-icon name="trash" /></button>
                        </div>
                    </template>
                    <button type="button" class="btn btn-outline btn-sm justify-self-start" x-on:click="f.leaves.push(leaf())"><x-icon name="plus" /> {{ t('commissionRules.addCondition') }}</button>
                </fieldset>
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_active" value="1" x-model="f.is_active"> {{ t('commissionRules.active') }}</label>
                <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                    <button type="button" class="btn btn-outline" x-on:click="show = false">{{ t('common.cancel') }}</button>
                    <button class="btn btn-primary">{{ t('common.save') }}</button>
                </div>
            </form>
        </div>
    </div>
@endsection
@push('scripts')
<script>
    function ruleEditor(cfg) {
        let seq = 0;
        const leaf = (l = {}) => ({ key: ++seq, metric: l.metric ?? 'avg_check_usd', op: l.op ?? '>', value: l.value !== undefined ? String(l.value) : '' });
        const blank = () => ({ id: null, name: '', applies_to: 'MANAGER', user_id: '', calc_type: 'PERCENT_OF_PAYMENT', value: '', priority: '0', is_active: true, mode: 'all', leaves: [] });
        return {
            ...cfg, show: false, f: blank(), leaf,
            init() {
                if (cfg.old) { this.f = { ...blank(), ...cfg.old, leaves: cfg.old.leaves.map(leaf) }; this.show = true; }
            },
            load(id) {
                const r = id ? this.rules[id] : null;
                this.f = r ? { ...blank(), ...r, leaves: r.leaves.map(leaf) } : blank();
                this.show = true;
            },
        };
    }
</script>
@endpush
