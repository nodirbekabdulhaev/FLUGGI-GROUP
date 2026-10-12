{{--
  Форма лида: при создании — только основное (ТЗ §78, progressive disclosure), остальное — по «Ещё поля».
  $lead (null — новый), $form (справочники), $owners. Предупреждение о дублях — по телефону, Instagram, email.
--}}
@php
    $lead ??= null;
    $isNew = $lead === null;
    $selfOwner = in_array(access()->roleCode, \App\Services\Crm\CrmAccess::OWNER_ROLES, true);
    $more = ! $isNew || ($errors->any() && old('_more'));
@endphp
<form method="POST" action="{{ $isNew ? route('crm.leads.store') : route('crm.leads.update', $lead->id) }}" class="grid gap-4" novalidate
      x-data="leadDuplicates(@js(route('crm.leads.duplicates')), @js($lead?->id))">
    @csrf
    @unless ($isNew) @method('PUT') @endunless
    <input type="hidden" name="_form" value="{{ $isNew ? 'lead-create' : 'lead-edit' }}">
    @if ($isNew)<p class="text-sm text-muted-foreground">{{ t('leads.createHint') }}</p>@endif
    <div class="grid gap-4 sm:grid-cols-2">
        <x-field name="contact_name" :label="t('crm.fields.contactName')" :value="$lead?->contact_name" maxlength="120" />
        <x-field name="company_name" :label="t('crm.fields.companyName')" :value="$lead?->company_name" maxlength="160" />
        <x-field name="phone" type="tel" :label="t('crm.fields.phone')" :value="$lead?->phone" placeholder="+998" maxlength="30" x-on:change="check($el.form)" />
        <x-field name="telegram" :label="t('crm.fields.telegram')" :value="$lead?->telegram" placeholder="@username" maxlength="64" />
        <x-select name="source_id" :label="t('crm.fields.source')" :options="$form['sources']" :value="$lead?->source_id" :placeholder="t('crm.common.select')" required />
        <x-select name="service_id" :label="t('crm.fields.service')" :options="$form['services']" :value="$lead?->service_id" :placeholder="$isNew ? t('crm.common.select') : t('crm.common.none')" :required="$isNew" />
        @if ($isNew && access()->can('lead.create', 'TEAM') && ($formOwners ?? []))
            <x-select name="owner_id" :label="t('crm.fields.owner')" :options="$formOwners" :value="$selfOwner ? access()->id() : ''" :placeholder="$selfOwner ? null : t('crm.fields.ownerAuto')" />
        @endif
        <div class="grid grid-cols-[1fr_6rem] gap-2">
            <x-field name="budget" :label="t('crm.fields.budget')" :value="$lead?->budget !== null ? rtrim(rtrim((string) $lead->budget, '0'), '.') : null" inputmode="decimal" placeholder="0" />
            <x-select name="currency" :label="t('crm.fields.currency')" :options="array_combine(\App\Services\Crm\Crm::CURRENCIES, \App\Services\Crm\Crm::CURRENCIES)" :value="$lead?->currency ?? 'UZS'" />
        </div>
    </div>

    <div x-show="dups.leads.length || dups.clients.length" x-cloak class="rounded-md border border-warning/40 bg-warning-soft p-3 text-sm">
        <p class="font-medium text-warning">{{ t('leads.duplicates.title') }}</p>
        <p class="text-xs text-muted-foreground">{{ t('leads.duplicates.hint') }}</p>
        <ul class="mt-2 grid gap-1">
            <template x-for="d in dups.leads" :key="d.id">
                <li><a class="link" :href="d.url" target="_blank" x-text="d.number + ' ' + d.title"></a> <span class="text-xs text-muted-foreground" x-text="'· ' + d.status + ' · ' + (d.owner ?? '')"></span></li>
            </template>
            <template x-for="d in dups.clients" :key="d.id">
                <li>{{ t('crm.fields.client') }}: <a class="link" :href="d.url" target="_blank" x-text="d.number + ' ' + d.title"></a> <span class="text-xs text-muted-foreground" x-text="'· ' + (d.owner ?? '')"></span></li>
            </template>
        </ul>
    </div>

    <div x-data="{ more: @js((bool) $more) }">
        <input type="hidden" name="_more" :value="more ? 1 : ''">
        <button type="button" class="link inline-flex items-center gap-1 text-sm" x-on:click="more = !more" :aria-expanded="more">
            <x-icon name="chevron-down" /> <span x-text="more ? @js(t('crm.common.less')) : @js(t('crm.common.more'))"></span>
        </button>
        <div x-show="more" x-collapse class="mt-4 grid gap-4 sm:grid-cols-2">
            <x-field name="title" :label="t('crm.fields.title')" :value="$lead?->title" maxlength="200" />
            <x-select name="priority" :label="t('crm.fields.priority')" :options="\App\Services\Crm\Crm::enum(\App\Services\Crm\Crm::PRIORITIES, 'crm.priority')" :value="$lead?->priority ?? 'MEDIUM'" />
            <x-field name="whatsapp" type="tel" :label="t('crm.fields.whatsapp')" :value="$lead?->whatsapp" maxlength="30" />
            <x-field name="instagram" :label="t('crm.fields.instagram')" :value="$lead?->instagram" maxlength="64" x-on:change="check($el.form)" />
            <x-field name="email" type="email" :label="t('crm.fields.email')" :value="$lead?->email" maxlength="254" x-on:change="check($el.form)" />
            <x-field name="website" :label="t('crm.fields.website')" :value="$lead?->website" maxlength="200" />
            <x-field name="city" :label="t('crm.fields.city')" :value="$lead?->city" maxlength="80" />
            <x-field name="country" :label="t('crm.fields.country')" :value="$lead?->country" maxlength="80" />
            <x-field name="desired_date" type="date" :label="t('crm.fields.desiredDate')" :value="$lead?->desired_date?->format('Y-m-d')" />
            <x-field name="next_contact_at" type="datetime-local" :label="t('crm.fields.nextContactAt')" :value="\App\Support\Format::toLocalInput($lead?->next_contact_at)" />
            <x-select name="company_size" :label="t('crm.fields.companySize')" :options="\App\Services\Crm\Crm::enum(\App\Services\Crm\Crm::COMPANY_SIZES, 'crm.companySize')" :value="$lead?->company_size" :placeholder="t('crm.common.none')" />
            <x-select name="interest" :label="t('crm.fields.interest')" :options="[1 => 1, 2 => 2, 3 => 3, 4 => 4, 5 => 5]" :value="$lead?->interest" :placeholder="t('crm.common.none')" />
            <x-field name="comment" type="textarea" class="sm:col-span-2" :label="t('crm.fields.comment')" :value="$lead?->comment" maxlength="4000" />
        </div>
    </div>
    <div class="flex justify-end gap-2">
        <button type="button" class="btn btn-outline" x-on:click="$dispatch('close-modal', @js($isNew ? 'lead-create' : 'lead-edit'))">{{ t('common.cancel') }}</button>
        <button type="submit" class="btn btn-primary">{{ $isNew ? t('leads.createSubmit') : t('common.save') }}</button>
    </div>
</form>

@once
    @push('scripts')
        <script>
            // Поиск возможных дублей лида по телефону, Instagram и email (предупреждение, не запрет)
            window.leadDuplicates = (url, except) => ({
                dups: { leads: [], clients: [] },
                async check(form) {
                    const params = new URLSearchParams();
                    ['phone', 'instagram', 'email'].forEach((k) => form.elements[k]?.value && params.set(k, form.elements[k].value));
                    if (except) params.set('except', except);
                    if (![...params.keys()].some((k) => k !== 'except')) { this.dups = { leads: [], clients: [] }; return; }
                    try { this.dups = await window.api(url + '?' + params.toString()); } catch (e) { this.dups = { leads: [], clients: [] }; }
                },
            });
        </script>
    @endpush
@endonce
