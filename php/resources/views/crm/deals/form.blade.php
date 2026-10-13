{{-- Форма сделки: $deal (null — новая), $clients (для новой без $fixedClient), $fixedClient, $serviceOptions --}}
@php
    $deal ??= null; $isNew = $deal === null; $fixedClient ??= null;
    $clientsData = $isNew && ! $fixedClient
        ? ($clients ?? collect())->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'contacts' => $c->contacts->map(fn ($ct) => ['id' => $ct->id, 'name' => $ct->full_name])->values()])->values()
        : collect([['id' => ($deal?->client ?? $fixedClient)->id, 'name' => ($deal?->client ?? $fixedClient)->name,
            'contacts' => ($deal?->client ?? $fixedClient)->contacts->map(fn ($ct) => ['id' => $ct->id, 'name' => $ct->full_name])->values()]]);
@endphp
<form method="POST" action="{{ $isNew ? route('crm.deals.store') : route('crm.deals.update', $deal->id) }}" class="grid gap-4" novalidate
      x-data="{ clients: @js($clientsData), clientId: @js(old('client_id', $deal?->client_id ?? $fixedClient?->id ?? '')), contactId: @js(old('contact_id', $deal?->contact_id ?? '')),
                get contacts() { return this.clients.find((c) => c.id === this.clientId)?.contacts ?? []; } }">
    @csrf
    @unless ($isNew) @method('PUT') @endunless
    <input type="hidden" name="_form" value="{{ $isNew ? 'deal-create' : 'deal-edit' }}">
    @if ($isNew)<p class="text-sm text-muted-foreground">{{ t('deals.createHint') }}</p>@endif
    @if ($isNew && ! $fixedClient)
        <div>
            <label class="label" for="d-client">{{ t('crm.fields.client') }} <span class="text-danger">*</span></label>
            <select id="d-client" name="client_id" class="input" x-model="clientId" required>
                <option value="">{{ t('crm.common.select') }}</option>
                <template x-for="c in clients" :key="c.id"><option :value="c.id" x-text="c.name" :selected="c.id === clientId"></option></template>
            </select>
            @error('client_id')<p class="error">{{ $message }}</p>@enderror
        </div>
    @else
        <input type="hidden" name="client_id" value="{{ $deal?->client_id ?? $fixedClient->id }}">
    @endif
    <x-field name="title" :label="t('crm.fields.title')" :value="$deal?->title" maxlength="200" required />
    <div class="grid gap-4 sm:grid-cols-2">
        <x-select name="service_id" :label="t('crm.fields.service')" :options="$serviceOptions" :value="$deal?->service_id" :placeholder="t('crm.common.none')" />
        <div>
            <label class="label" for="d-contact">{{ t('crm.fields.contact') }}</label>
            <select id="d-contact" name="contact_id" class="input" x-model="contactId">
                <option value="">{{ t('crm.common.none') }}</option>
                <template x-for="c in contacts" :key="c.id"><option :value="c.id" x-text="c.name" :selected="c.id === contactId"></option></template>
            </select>
            @error('contact_id')<p class="error">{{ $message }}</p>@enderror
        </div>
        <div class="grid grid-cols-[1fr_6rem] gap-2">
            <x-field name="amount" :label="t('crm.fields.amount')" :value="$deal ? rtrim(rtrim((string) $deal->amount, '0'), '.') : null" inputmode="decimal" required />
            <x-select name="currency" :label="t('crm.fields.currency')" :options="array_combine(\App\Services\Crm\Crm::CURRENCIES, \App\Services\Crm\Crm::CURRENCIES)" :value="$deal?->currency ?? 'UZS'" />
        </div>
        <x-field name="expected_close_date" type="date" :label="t('crm.fields.expectedCloseDate')" :value="$deal?->expected_close_date?->format('Y-m-d')" />
        @unless ($isNew)
            {{-- Ролик SMM, из которого пришёл клиент: бонус SMM-специалисту с каждой оплаты (правила комиссий) --}}
            <x-select name="smm_idea_id" id="f-deal-smm-idea" :label="t('crm.fields.smmIdea')" :options="$smmVideos ?? []" :value="$deal->smm_idea_id" :placeholder="t('crm.common.none')" :hint="t('smm.dealVideoHint')" />
            <x-field name="probability_override" type="number" min="0" max="100" :label="t('crm.fields.probability').', %'" :value="$deal->probability_override" :hint="t('deals.probabilityHint')" />
        @endunless
    </div>
    <div class="flex justify-end gap-2">
        <button type="button" class="btn btn-outline" x-on:click="$dispatch('close-modal', @js($isNew ? 'deal-create' : 'deal-edit'))">{{ t('common.cancel') }}</button>
        <button type="submit" class="btn btn-primary">{{ $isNew ? t('deals.createSubmit') : t('common.save') }}</button>
    </div>
</form>
