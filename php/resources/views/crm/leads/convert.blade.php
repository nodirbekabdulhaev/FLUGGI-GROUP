{{-- Квалификация: лид → клиент (новый или существующий) + сделка --}}
<x-modal name="lead-convert" :title="t('leads.convertTitle')" :open="$errors->any() && old('_form') === 'convert'">
    <form method="POST" action="{{ route('crm.leads.convert', $lead->id) }}" class="grid gap-4"
          x-data="{ mode: @js(old('mode', 'new')) }">
        @csrf <input type="hidden" name="_form" value="convert">
        <p class="text-sm text-muted-foreground">{{ t('leads.convertHint') }}</p>
        <input type="hidden" name="mode" :value="mode">
        <div class="flex gap-2">
            <button type="button" class="btn btn-sm" :class="mode === 'new' ? 'btn-primary' : 'btn-outline'" x-on:click="mode = 'new'">{{ t('leads.newClient') }}</button>
            @if (can('client.read'))
                <button type="button" class="btn btn-sm" :class="mode === 'existing' ? 'btn-primary' : 'btn-outline'" x-on:click="mode = 'existing'">{{ t('leads.existingClient') }}</button>
            @endif
        </div>
        <div x-show="mode === 'new'" class="grid gap-4 sm:grid-cols-[1fr_10rem]">
            <x-field name="client_name" :label="t('leads.clientName')" :value="$lead->company_name ?? $lead->contact_name" maxlength="160" />
            <x-select name="client_type" :label="t('crm.fields.type')" :options="\App\Services\Crm\Crm::enum(\App\Services\Crm\Crm::CLIENT_TYPES, 'crm.clientType')" :value="$lead->company_name ? 'COMPANY' : 'PERSON'" />
        </div>
        <div x-show="mode === 'existing'" x-cloak>
            <x-select name="client_id" :label="t('crm.fields.client')" :options="$clients->mapWithKeys(fn ($c) => [$c->id => $c->name.' ('.\App\Support\Format::code('C', $c->number).')'])->all()" :placeholder="t('crm.common.select')" />
            @if ($clients->isEmpty())<p class="hint">{{ t('leads.noClientsFound') }}</p>@endif
        </div>
        <div class="grid grid-cols-[1fr_6rem] gap-2">
            <x-field name="amount" :label="t('crm.fields.amount')" :value="$lead->budget !== null ? rtrim(rtrim((string) $lead->budget, '0'), '.') : null" inputmode="decimal" required />
            <x-select name="currency" :label="t('crm.fields.currency')" :options="array_combine(\App\Services\Crm\Crm::CURRENCIES, \App\Services\Crm\Crm::CURRENCIES)" :value="$lead->currency" />
        </div>
        <div class="grid gap-4 sm:grid-cols-2">
            <x-field name="title" :label="t('deals.dealTitle')" :hint="t('deals.dealTitleHint')" maxlength="200" />
            <x-field name="expected_close_date" type="date" :label="t('crm.fields.expectedCloseDate')" />
        </div>
        <div class="flex justify-end gap-2">
            <button type="button" class="btn btn-outline" x-on:click="$dispatch('close-modal', 'lead-convert')">{{ t('common.cancel') }}</button>
            <button type="submit" class="btn btn-accent">{{ t('leads.convert') }}</button>
        </div>
    </form>
</x-modal>
