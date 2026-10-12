{{-- Форма клиента: $client (null — новый), $sources, $owners --}}
@php $client ??= null; $isNew = $client === null; @endphp
<form method="POST" action="{{ $isNew ? route('crm.clients.store') : route('crm.clients.update', $client->id) }}" class="grid gap-4" novalidate>
    @csrf
    @unless ($isNew) @method('PUT') @endunless
    <input type="hidden" name="_form" value="{{ $isNew ? 'client-create' : 'client-edit' }}">
    <div class="grid gap-4 sm:grid-cols-[1fr_10rem]">
        <x-field name="name" :label="t('crm.fields.title')" :value="$client?->name" maxlength="160" required />
        <x-select name="type" :label="t('crm.fields.type')" :options="\App\Services\Crm\Crm::enum(\App\Services\Crm\Crm::CLIENT_TYPES, 'crm.clientType')" :value="$client?->type ?? 'COMPANY'" />
    </div>
    <div class="grid gap-4 sm:grid-cols-2">
        <x-field name="phone" type="tel" :label="t('crm.fields.phone')" :value="$client?->phone" maxlength="30" />
        <x-field name="email" type="email" :label="t('crm.fields.email')" :value="$client?->email" maxlength="254" />
        <x-field name="telegram" :label="t('crm.fields.telegram')" :value="$client?->telegram" maxlength="64" />
        <x-field name="website" :label="t('crm.fields.website')" :value="$client?->website" maxlength="200" />
        <x-field name="industry" :label="t('crm.fields.industry')" :value="$client?->industry" maxlength="120" />
        <x-field name="city" :label="t('crm.fields.city')" :value="$client?->city" maxlength="80" />
        <x-field name="country" :label="t('crm.fields.country')" :value="$client?->country" maxlength="80" />
        <x-select name="source_id" :label="t('crm.fields.source')" :options="$sources" :value="$client?->source_id" :placeholder="t('crm.common.none')" />
        @if ($isNew && ($owners ?? []))
            <x-select name="owner_id" :label="t('crm.fields.owner')" :options="$owners" :value="in_array(access()->roleCode, \App\Services\Crm\CrmAccess::OWNER_ROLES, true) ? access()->id() : ''" :placeholder="t('crm.fields.ownerAuto')" />
        @endif
    </div>
    <x-field name="comment" type="textarea" :label="t('crm.fields.comment')" :value="$client?->comment" maxlength="4000" />
    @if ($isNew)
        <div class="grid gap-4 rounded-md border border-dashed p-3 sm:grid-cols-2">
            <x-field name="contact[full_name]" :label="t('crm.fields.contact').': '.mb_strtolower(t('crm.fields.contactName'))" maxlength="120" />
            <x-field name="contact[phone]" type="tel" :label="t('crm.fields.contact').': '.mb_strtolower(t('crm.fields.phone'))" maxlength="30" />
        </div>
    @endif
    <div class="flex justify-end gap-2">
        <button type="button" class="btn btn-outline" x-on:click="$dispatch('close-modal', @js($isNew ? 'client-create' : 'client-edit'))">{{ t('common.cancel') }}</button>
        <button type="submit" class="btn btn-primary">{{ $isNew ? t('clients.createSubmit') : t('common.save') }}</button>
    </div>
</form>
