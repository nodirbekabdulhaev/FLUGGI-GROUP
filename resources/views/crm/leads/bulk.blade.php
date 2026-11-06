{{-- Массовая передача лидов (внутри x-data="leadBulk"): панель выбранных + окно передачи. $bulk: owners, from; $f — фильтр списка --}}
<div x-show="count > 0" x-cloak class="sticky bottom-24 z-30 mt-3 flex flex-wrap items-center gap-2 rounded-lg border bg-surface p-3 shadow-lg">
    <span class="text-sm font-medium" x-text="@js(t('leads.bulk.selected', ['count' => '__N__'])).replace('__N__', count)"></span>
    <button type="button" class="btn btn-ghost btn-sm" x-on:click="clear()">{{ t('leads.bulk.clear') }}</button>
    <button type="button" class="btn btn-primary btn-sm ml-auto" x-on:click="openSelected()"><x-icon name="user" /> <span x-text="@js(t('leads.bulk.transfer', ['count' => '__N__'])).replace('__N__', count)"></span></button>
</div>

<x-modal name="lead-bulk" :title="t('leads.bulk.title')" size="max-w-xl">
    <form method="POST" action="{{ route('crm.leads.bulkAssign') }}" class="grid gap-4" data-confirm="{{ t('leads.bulk.confirm') }}">
        @csrf
        <input type="hidden" name="mode" :value="mode">
        <template x-if="mode === 'ids'">
            <div><template x-for="id in selected" :key="id"><input type="hidden" name="ids[]" :value="id"></template></div>
        </template>
        @foreach (array_filter($f, fn ($v) => $v !== null && $v !== '') as $key => $value)
            <input type="hidden" name="filter[{{ $key }}]" value="{{ $value }}" :disabled="mode !== 'filter'">
        @endforeach

        <p class="font-medium" x-show="mode === 'owner'" x-cloak>{{ t('leads.bulk.managerTitle') }}</p>
        <div class="grid gap-4" x-show="mode === 'owner'" x-cloak>
            <div>
                <label class="label" for="bulk-from">{{ t('leads.bulk.from') }}<span class="text-danger"> *</span></label>
                <select id="bulk-from" name="from_owner_id" class="input" x-model="from" x-on:change="load()" :disabled="mode !== 'owner'" :required="mode === 'owner'">
                    <option value="">{{ t('crm.common.select') }}</option>
                    @foreach ($bulk['from'] as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                </select>
            </div>
            <fieldset>
                <legend class="label">{{ t('leads.bulk.leadStatus') }}</legend>
                <div class="flex flex-wrap gap-4 text-sm">
                    <label class="flex items-center gap-2"><input type="radio" name="lead_status" value="open" x-model="leadStatus" x-on:change="load()" :disabled="mode !== 'owner'"> {{ t('leads.bulk.statusOpen') }}</label>
                    <label class="flex items-center gap-2"><input type="radio" name="lead_status" value="all" x-model="leadStatus" x-on:change="load()" :disabled="mode !== 'owner'"> {{ t('leads.bulk.statusAll') }}</label>
                </div>
            </fieldset>
        </div>

        <div>
            <label class="label" for="bulk-to">{{ t('leads.bulk.to') }}<span class="text-danger"> *</span></label>
            <select id="bulk-to" name="owner_id" class="input" x-model="to" x-on:change="load()" required>
                <option value="">{{ t('crm.common.select') }}</option>
                @foreach ($bulk['owners'] as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
            </select>
        </div>
        <x-field name="comment" type="textarea" rows="2" :label="t('leads.bulk.comment')" :hint="t('leads.bulk.commentHint')" maxlength="1000" />

        <div class="grid gap-2 text-sm">
            @if (can('deal.update'))
                <label class="flex items-start gap-2"><input type="checkbox" name="with_deals" value="1" checked class="mt-0.5"> {{ t('leads.bulk.withDeals') }}</label>
            @endif
            @if (can('client.update'))
                <label class="flex items-start gap-2"><input type="checkbox" name="with_clients" value="1" class="mt-0.5"> {{ t('leads.bulk.withClients') }}</label>
            @endif
            <label class="flex items-start gap-2"><input type="checkbox" name="with_tasks" value="1" checked class="mt-0.5"> {{ t('leads.bulk.withTasks') }}</label>
            <p class="hint">{{ t('leads.bulk.optionsHint') }}</p>
        </div>

        <div class="rounded-md bg-muted/50 p-3 text-sm" aria-live="polite">
            <p x-show="loading" x-cloak class="text-muted-foreground">{{ t('leads.bulk.loading') }}</p>
            <template x-if="!loading && preview">
                <div>
                    <p class="font-medium" x-show="preview.transferable > 0" x-text="text(@js(t('leads.bulk.preview', ['transferable' => '__transferable__', 'total' => '__total__'])))"></p>
                    <p class="font-medium" x-show="preview.transferable === 0">{{ t('leads.bulk.previewEmpty') }}</p>
                    <p class="text-muted-foreground" x-show="preview.noAccess + preview.same > 0" x-text="text(@js(t('leads.bulk.previewSkipped', ['noAccess' => '__noAccess__', 'same' => '__same__'])))"></p>
                </div>
            </template>
            <p x-show="error" x-cloak class="text-danger" x-text="error"></p>
        </div>

        <div class="flex justify-end gap-2">
            <button type="button" class="btn btn-outline" x-on:click="$dispatch('close-modal', 'lead-bulk')">{{ t('common.cancel') }}</button>
            <button type="submit" class="btn btn-primary" :disabled="loading || !to || (preview && preview.transferable === 0)">{{ t('leads.bulk.submit') }}</button>
        </div>
    </form>
</x-modal>

@push('scripts')
<script>
    function leadBulk(cfg) {
        return {
            ...cfg, selected: [], all: false, mode: 'ids', from: '', to: '', leadStatus: 'open', preview: null, loading: false, error: '', seq: 0,
            get pageAll() { return this.pageIds.length > 0 && this.pageIds.every((id) => this.selected.includes(id)); },
            get count() { return this.all ? this.total : this.selected.length; },
            togglePage(on) { this.selected = on ? [...this.pageIds] : []; if (!on) this.all = false; },
            clear() { this.selected = []; this.all = false; },
            openSelected() { this.mode = this.all ? 'filter' : 'ids'; this.open(); },
            openManager() { this.mode = 'owner'; this.open(); },
            open() { this.preview = null; this.error = ''; this.$dispatch('open-modal', 'lead-bulk'); this.load(); },
            text(tpl) { return tpl.replace(/__(\w+)__/g, (_, k) => this.preview[k]); },
            async load() {
                if (this.mode === 'owner' && !this.from) { this.preview = null; return; }
                const q = new URLSearchParams({ mode: this.mode });
                if (this.to) q.append('owner_id', this.to);
                if (this.mode === 'ids') this.selected.forEach((id) => q.append('ids[]', id));
                if (this.mode === 'filter') Object.entries(this.filter).forEach(([k, v]) => q.append(`filter[${k}]`, v));
                if (this.mode === 'owner') { q.append('from_owner_id', this.from); q.append('lead_status', this.leadStatus); }
                const seq = ++this.seq;
                this.loading = true; this.error = '';
                try {
                    const data = await window.api(`${this.previewUrl}?${q}`);
                    if (seq === this.seq) this.preview = data;
                } catch (e) {
                    if (seq === this.seq) { this.preview = null; this.error = e.message; }
                } finally {
                    if (seq === this.seq) this.loading = false;
                }
            },
        };
    }
</script>
@endpush
