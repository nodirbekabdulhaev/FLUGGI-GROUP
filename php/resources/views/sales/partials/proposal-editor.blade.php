{{--
  Редактор КП (одно окно на страницу): позиции со скидками и тарифами, итог считается на лету
  (сервер пересчитывает сам). Открыть: $dispatch('open-proposal', null | {…КП…}).
  Переменные: $deal, $editor (Panels::editor()).
--}}
@php
    $reopen = old('_form') === 'proposal-editor' && $errors->any();
    $init = [
        'dealId' => $deal->id,
        'services' => $editor['services'],
        'tariffs' => $editor['tariffs'],
        'usd' => (float) $editor['usd'],
        'canPrice' => $editor['canPrice'],
        'storeUrl' => route('sales.proposals.store'),
        'updateUrl' => url('/sales/proposals/__ID__'),
        'old' => $reopen ? [
            'id' => old('_proposal_id'), 'status' => old('_proposal_status'), 'number' => old('_proposal_number'),
            'title' => old('title'), 'currency' => old('currency', 'UZS'), 'description' => old('description'),
            'implementation_term' => old('implementation_term'), 'payment_terms' => old('payment_terms'),
            'valid_until' => old('valid_until'), 'version_comment' => old('version_comment'),
            'items' => array_values(old('items', [])),
        ] : null,
        'errors' => $reopen ? collect($errors->getMessages())->map(fn ($m) => $m[0])->all() : [],
        'texts' => [
            'new' => t('sales.proposals.new'), 'edit' => t('sales.proposals.edit'),
        ],
    ];
@endphp
<div x-data="proposalEditor(@js($init))" x-on:open-proposal.window="load($event.detail)" x-on:keydown.escape.window="show = false"
     x-show="show" x-cloak class="fixed inset-0 z-50 flex items-end justify-center sm:items-center sm:p-4" role="dialog" aria-modal="true">
    <div class="absolute inset-0 bg-black/40" x-on:click="show = false"></div>
    <div class="relative max-h-[94dvh] w-full max-w-5xl overflow-y-auto rounded-t-xl bg-surface shadow-xl sm:rounded-xl">
        <header class="sticky top-0 z-10 flex items-center justify-between border-b bg-surface px-4 py-3 sm:px-5">
            <h2 class="font-semibold" x-text="f.id ? texts.edit + ' ' + (f.number || '') : texts.new"></h2>
            <button type="button" class="btn btn-ghost btn-icon" x-on:click="show = false" aria-label="{{ t('common.close') }}"><x-icon name="x" /></button>
        </header>
        <form method="POST" :action="f.id ? updateUrl.replace('__ID__', f.id) : storeUrl" class="grid gap-4 p-4 sm:p-5" novalidate>
            @csrf
            <input type="hidden" name="_method" :value="f.id ? 'PUT' : 'POST'">
            <input type="hidden" name="_form" value="proposal-editor">
            <input type="hidden" name="_idem" :value="idem">
            <input type="hidden" name="_proposal_id" :value="f.id || ''">
            <input type="hidden" name="_proposal_status" :value="f.status || ''">
            <input type="hidden" name="_proposal_number" :value="f.number || ''">
            <template x-if="!f.id"><input type="hidden" name="deal_id" :value="dealId"></template>
            <p x-show="f.id && f.status !== 'DRAFT'" class="rounded-md bg-warning-soft px-3 py-2 text-sm text-warning">{{ t('sales.proposals.editResets') }}</p>

            <div class="grid gap-4 sm:grid-cols-[1fr_7rem]">
                <div>
                    <label class="label" for="kp-title">{{ t('sales.proposals.name') }}<span class="text-danger"> *</span></label>
                    <input id="kp-title" name="title" class="input" x-model="f.title" maxlength="200" required>
                    <p class="error" x-show="errors.title" x-text="errors.title"></p>
                </div>
                <div>
                    <label class="label" for="kp-cur">{{ t('sales.currency') }}</label>
                    <select id="kp-cur" name="currency" class="input" x-model="f.currency">
                        <option value="UZS">UZS</option><option value="USD">USD</option>
                    </select>
                </div>
            </div>

            <div>
                <p class="mb-2 text-sm font-medium">{{ t('sales.proposals.items') }}</p>
                <div class="grid gap-2">
                    <template x-for="(l, i) in f.items" :key="l.key">
                        <div class="grid grid-cols-2 gap-2 rounded-md border p-2 lg:grid-cols-[9rem_8rem_1fr_4.5rem_8rem_4.5rem_8rem_2.25rem] lg:items-start lg:border-0 lg:p-0">
                            <select class="input" :name="`items[${i}][service_id]`" x-model="l.service_id" x-on:change="onService(l)" aria-label="{{ t('sales.proposals.service') }}">
                                <option value="">{{ t('sales.proposals.service') }}</option>
                                <template x-for="s in services.filter((s) => s.isActive || s.id === l.service_id)" :key="s.id">
                                    <option :value="s.id" x-text="s.name" :selected="s.id === l.service_id"></option>
                                </template>
                            </select>
                            <select class="input" :name="`items[${i}][tariff_id]`" x-model="l.tariff_id" :disabled="!l.service_id" x-on:change="onTariff(l)" aria-label="{{ t('sales.proposals.tariff') }}">
                                <option value="">{{ t('sales.proposals.noTariff') }}</option>
                                <template x-for="tf in tariffs.filter((tf) => tf.serviceId === l.service_id)" :key="tf.id">
                                    <option :value="tf.id" x-text="`${tf.name} · ${fmt(tf.price)} ${tf.currency}`" :selected="tf.id === l.tariff_id"></option>
                                </template>
                            </select>
                            <div class="col-span-2 lg:col-span-1">
                                <input class="input" :name="`items[${i}][description]`" x-model="l.description" placeholder="{{ t('sales.proposals.description') }}" aria-label="{{ t('sales.proposals.description') }}" maxlength="500">
                                <p class="error" x-show="err(i, 'description')" x-text="err(i, 'description')"></p>
                            </div>
                            <div>
                                <input class="input" :name="`items[${i}][quantity]`" x-model="l.quantity" inputmode="decimal" aria-label="{{ t('sales.proposals.qty') }}" title="{{ t('sales.proposals.qty') }}">
                                <p class="error" x-show="err(i, 'quantity')" x-text="err(i, 'quantity')"></p>
                            </div>
                            <div>
                                <input class="input" :name="`items[${i}][unit_price]`" x-model="l.unit_price" inputmode="decimal" data-money placeholder="{{ t('sales.proposals.price') }}" aria-label="{{ t('sales.proposals.price') }}"
                                       :readonly="l.tariff_id && !canPrice" :title="l.tariff_id && !canPrice ? @js(t('sales.proposals.tariffPriceLocked')) : ''">
                                <p class="error" x-show="err(i, 'unit_price')" x-text="err(i, 'unit_price')"></p>
                            </div>
                            <input class="input" :name="`items[${i}][discount_pct]`" x-model="l.discount_pct" inputmode="decimal" aria-label="{{ t('sales.proposals.discount') }}" title="{{ t('sales.proposals.discount') }}" :readonly="l.tariff_id && !canPrice">
                            <span class="self-center text-right text-sm font-medium tabular-nums" x-text="`${fmt(lineTotal(l))} ${f.currency}`"></span>
                            <button type="button" class="btn btn-ghost btn-icon justify-self-end" :disabled="f.items.length === 1" x-on:click="f.items.splice(i, 1)" aria-label="{{ t('sales.proposals.removeItem') }}"><x-icon name="trash" /></button>
                        </div>
                    </template>
                </div>
                <p class="error" x-show="errors.items" x-text="errors.items"></p>
                <button type="button" class="btn btn-outline btn-sm mt-2" x-on:click="f.items.push(line())"><x-icon name="plus" /> {{ t('sales.proposals.addItem') }}</button>
            </div>

            <div class="ml-auto grid w-full max-w-xs gap-1 text-sm">
                <div class="flex justify-between"><span class="muted">{{ t('sales.proposals.subtotal') }}</span><span class="tabular-nums" x-text="`${fmt(totals().sub)} ${f.currency}`"></span></div>
                <div class="flex justify-between" x-show="totals().disc"><span class="muted">{{ t('sales.proposals.discountTotal') }}</span><span class="tabular-nums" x-text="`− ${fmt(totals().disc)} ${f.currency}`"></span></div>
                <div class="flex justify-between border-t pt-1 text-base font-semibold"><span>{{ t('sales.proposals.total') }}</span><span class="tabular-nums" x-text="`${fmt(totals().sub - totals().disc)} ${f.currency}`"></span></div>
            </div>

            <div class="grid gap-4 sm:grid-cols-3">
                <div>
                    <label class="label" for="kp-term">{{ t('sales.proposals.implementationTerm') }}</label>
                    <input id="kp-term" name="implementation_term" class="input" x-model="f.implementation_term" maxlength="200" placeholder="{{ t('sales.proposals.termPlaceholder') }}">
                </div>
                <div>
                    <label class="label" for="kp-pay">{{ t('sales.proposals.paymentTerms') }}</label>
                    <input id="kp-pay" name="payment_terms" class="input" x-model="f.payment_terms" maxlength="1000" placeholder="{{ t('sales.proposals.paymentPlaceholder') }}">
                </div>
                <div>
                    <label class="label" for="kp-valid">{{ t('sales.proposals.validUntil') }}</label>
                    <input id="kp-valid" type="date" name="valid_until" class="input" x-model="f.valid_until">
                    <p class="error" x-show="errors.valid_until" x-text="errors.valid_until"></p>
                </div>
            </div>
            <div>
                <label class="label" for="kp-desc">{{ t('sales.proposals.description') }}</label>
                <textarea id="kp-desc" name="description" rows="3" class="input" x-model="f.description" maxlength="5000"></textarea>
            </div>
            <div x-show="f.id">
                <label class="label" for="kp-vc">{{ t('sales.proposals.versionComment') }}</label>
                <input id="kp-vc" name="version_comment" class="input" x-model="f.version_comment" maxlength="500">
            </div>
            <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <button type="button" class="btn btn-outline" x-on:click="show = false">{{ t('common.cancel') }}</button>
                <button type="submit" class="btn btn-primary">{{ t('common.save') }}</button>
            </div>
        </form>
    </div>
</div>
@once
@push('scripts')
<script>
    function proposalEditor(cfg) {
        let seq = 0;
        const num = (v) => { const n = Number(String(v ?? '').replace(/\s/g, '').replace(',', '.')); return Number.isFinite(n) ? n : 0; };
        const line = (l = {}) => ({
            key: ++seq, service_id: l.service_id ?? l.serviceId ?? '', tariff_id: l.tariff_id ?? l.tariffId ?? '',
            description: l.description ?? '', quantity: String(l.quantity ?? '1'), unit_price: l.unit_price ?? l.unitPrice ?? '', discount_pct: String(l.discount_pct ?? l.discountPct ?? '0'),
        });
        const blank = () => ({ id: null, status: null, number: null, title: '', currency: 'UZS', description: '', implementation_term: '', payment_terms: '', valid_until: '', version_comment: '', items: [line()] });
        return {
            ...cfg, show: false, idem: '', f: blank(), line,
            init() {
                if (cfg.old) {
                    this.f = { ...blank(), ...cfg.old, items: (cfg.old.items.length ? cfg.old.items : [{}]).map(line) };
                    this.idem = this.newKey();
                    this.show = true;
                }
            },
            newKey() { return (crypto.randomUUID ? crypto.randomUUID() : String(Date.now()) + Math.random()).replace(/[^A-Za-z0-9]/g, '').slice(0, 40); },
            load(p) {
                this.errors = {};
                this.idem = this.newKey();
                this.f = p ? { ...blank(), ...p, version_comment: '', items: (p.items.length ? p.items : [{}]).map(line) } : blank();
                this.show = true;
            },
            err(i, k) { return this.errors[`items.${i}.${k}`] || ''; },
            fmt(v) { return Number(v || 0).toLocaleString('ru-RU', { maximumFractionDigits: 2 }); },
            lineTotal(l) { const g = num(l.quantity) * num(l.unit_price); return g - (g * Math.min(100, Math.max(0, num(l.discount_pct)))) / 100; },
            totals() {
                return this.f.items.reduce((a, l) => {
                    const g = num(l.quantity) * num(l.unit_price);
                    return { sub: a.sub + g, disc: a.disc + (g * Math.min(100, Math.max(0, num(l.discount_pct)))) / 100 };
                }, { sub: 0, disc: 0 });
            },
            onService(l) {
                const s = this.services.find((x) => x.id === l.service_id);
                l.tariff_id = '';
                if (s && !l.description) l.description = s.name;
                if (s && !l.unit_price && s.basePrice) l.unit_price = String(Number(s.basePrice));
            },
            /** Цена тарифа в валюте КП (по текущему курсу USD). */
            priceIn(price, from) {
                const v = Number(price);
                if (from === this.f.currency || !this.usd) return String(v);
                return String(from === 'USD' ? Math.round(v * this.usd) : Math.round((v / this.usd) * 100) / 100);
            },
            onTariff(l) {
                const tf = this.tariffs.find((x) => x.id === l.tariff_id);
                if (!tf) return;
                l.description = `${tf.serviceName} — ${tf.name}${tf.description ? `: ${tf.description}` : ''}`;
                l.unit_price = this.priceIn(tf.price, tf.currency);
                if (!this.canPrice) l.discount_pct = '0';
            },
        };
    }
</script>
@endpush
@endonce
