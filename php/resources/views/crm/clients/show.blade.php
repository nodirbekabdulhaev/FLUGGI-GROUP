@extends('layouts.app')
@section('title', $client->name)
@section('content')
    @include('crm.partials.errors')
@php
    $canEdit = can('client.update');
    $req = $requisites;
    $reqFields = [['legalName', true], ['inn', false], ['oked', false], ['director', false], ['directorPosition', false], ['signerGenitive', true],
        ['basis', false], ['vatCode', false], ['address', true], ['phone', false], ['bank', false], ['mfo', false], ['account', false]];
    $projectsSlot = view()->exists('projects.partials.client-projects') && can('project.read');
@endphp
    <a href="{{ route('crm.clients.index') }}" class="mb-4 inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"><x-icon name="arrow-left" /> {{ t('clients.title') }}</a>
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0">
            <p class="text-xs text-muted-foreground">{{ \App\Support\Format::code('C', $client->number) }} · {{ t('crm.clientType.'.$client->type) }}</p>
            <h1 class="break-words text-xl font-semibold tracking-tight sm:text-2xl">{{ $client->name }}</h1>
            <p class="mt-1 text-sm text-muted-foreground">{{ t('clients.dealsCount', ['count' => $dealsCount, 'open' => $openDealsCount]) }} · {{ $client->owner?->full_name }}</p>
        </div>
        <div class="flex flex-wrap gap-2" x-data>
            @if (can('deal.create'))<button type="button" class="btn btn-primary btn-sm" x-on:click="$dispatch('open-modal', 'deal-create')"><x-icon name="plus" /> {{ t('clients.newDeal') }}</button>@endif
            @if ($canEdit)<button type="button" class="btn btn-outline btn-sm" x-on:click="$dispatch('open-modal', 'client-edit')"><x-icon name="edit" /> {{ t('crm.common.edit') }}</button>@endif
        </div>
    </div>

    <div class="card card-body mb-6">
        @include('crm.partials.details', ['rows' => [
            [t('crm.fields.phone'), $client->phone], [t('crm.fields.email'), $client->email], [t('crm.fields.telegram'), $client->telegram],
            [t('crm.fields.website'), $client->website], [t('crm.fields.industry'), $client->industry],
            [t('crm.fields.city'), collect([$client->city, $client->country])->filter()->implode(', ')],
            [t('crm.fields.source'), $client->source?->label()], [t('crm.fields.team'), $client->team?->name],
            [t('crm.fields.createdAt'), dt($client->created_at)],
            [t('crm.fields.comment'), $client->comment],
        ]])
    </div>

    @includeIf('analytics.partials.client-insight', ['client' => $client])

    <div x-data="{ tab: (location.hash || '#deals').slice(1) }" x-on:hashchange.window="tab = (location.hash || '#deals').slice(1)">
        <nav class="mb-4 flex gap-1 overflow-x-auto border-b">
            @foreach (array_filter([
                'deals' => t('clients.deals').' ('.$deals->count().')', 'contacts' => t('clients.contacts').' ('.$client->contacts->count().')',
                'requisites' => t('requisites.tab'), 'activity' => t('leads.tabs.activity'), 'comments' => t('leads.tabs.comments'),
                'projects' => $projectsSlot ? t('leads.tabs.projects') : null,
            ]) as $key => $label)
                <a href="#{{ $key }}" class="whitespace-nowrap border-b-2 px-3 py-2 text-sm font-medium" :class="tab === '{{ $key }}' ? 'border-foreground text-foreground' : 'border-transparent text-muted-foreground hover:text-foreground'">{{ $label }}</a>
            @endforeach
        </nav>
        <div class="card">
            <div x-show="tab === 'deals'">
                @if ($deals->isEmpty())
                    <x-empty :title="t('clients.noDeals')" />
                @else
                    <ul class="divide-y">
                        @foreach ($deals as $d)
                            <li><a href="{{ route('crm.deals.show', $d->id) }}" class="flex flex-wrap items-center gap-3 px-5 py-3 hover:bg-muted/40">
                                <div class="min-w-0 flex-1"><p class="font-medium">{{ $d->title }}</p><p class="text-xs text-muted-foreground">{{ \App\Support\Format::code('D', $d->number) }} · {{ $d->owner?->full_name }}</p></div>
                                @if ($d->status === 'OPEN') @include('crm.partials.stage', ['stage' => $d->stage]) @else @include('crm.partials.status', ['status' => $d->status, 'kind' => 'deal']) @endif
                                <span class="font-medium sm:w-36 sm:text-right">{{ money($d->amount, $d->currency) }}</span>
                            </a></li>
                        @endforeach
                    </ul>
                @endif
            </div>
            <div x-show="tab === 'contacts'" x-cloak>
                @if ($canEdit)
                    <div class="border-b p-4"><button type="button" class="btn btn-outline btn-sm" x-data
                        x-on:click="$dispatch('contact-edit', { url: @js(route('crm.contacts.store', $client->id)), method: 'POST', full_name: '', position: '', phone: '', telegram: '', whatsapp: '', instagram: '', email: '', is_primary: false }); $dispatch('open-modal', 'contact')"><x-icon name="plus" /> {{ t('clients.addContact') }}</button></div>
                @endif
                @if ($client->contacts->isEmpty())
                    <x-empty :title="t('clients.noContacts')" />
                @else
                    <ul class="divide-y">
                        @foreach ($client->contacts as $ct)
                            <li class="flex items-center gap-3 px-5 py-3 text-sm">
                                <div class="min-w-0 flex-1">
                                    <p class="flex items-center gap-1.5 font-medium">{{ $ct->full_name }}@if ($ct->is_primary)<x-badge color="yellow">{{ t('clients.primary') }}</x-badge>@endif</p>
                                    <p class="break-words text-muted-foreground">{{ collect([$ct->position, $ct->phone, $ct->telegram, $ct->whatsapp, $ct->instagram, $ct->email])->filter()->implode(' · ') }}</p>
                                </div>
                                @if ($canEdit)
                                    <button type="button" class="btn btn-ghost btn-icon" aria-label="{{ t('crm.common.edit') }}" x-data
                                        x-on:click="$dispatch('contact-edit', @js(['url' => route('crm.contacts.update', $ct->id), 'method' => 'PUT'] + collect($ct->only(['full_name', 'position', 'phone', 'telegram', 'whatsapp', 'instagram', 'email']))->map(fn ($v) => $v ?? '')->all() + ['is_primary' => $ct->is_primary])); $dispatch('open-modal', 'contact')"><x-icon name="edit" /></button>
                                    <form method="POST" action="{{ route('crm.contacts.destroy', $ct->id) }}" data-confirm="{{ t('crm.common.deleteConfirm', ['name' => $ct->full_name]) }}">@csrf @method('DELETE')
                                        <button class="btn btn-ghost btn-icon" aria-label="{{ t('crm.common.delete') }}"><x-icon name="trash" /></button>
                                    </form>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
            <div x-show="tab === 'requisites'" x-cloak class="card-body">
                @if ($req)
                    @include('crm.partials.details', ['rows' => collect($reqFields)->map(fn ($f) => [t('requisites.'.$f[0]), $req[$f[0]] ?? null])->all()])
                @else
                    <x-empty :title="t('requisites.empty')">{{ t('requisites.emptyText') }}</x-empty>
                @endif
                @if ($canEdit)
                    <div class="mt-4"><button type="button" class="btn btn-outline btn-sm" x-data x-on:click="$dispatch('open-modal', 'requisites')"><x-icon name="edit" /> {{ $req ? t('requisites.edit') : t('requisites.fill') }}</button></div>
                @endif
            </div>
            <div x-show="tab === 'activity'" x-cloak class="card-body">@include('crm.partials.timeline')</div>
            <div x-show="tab === 'comments'" x-cloak class="card-body">@include('crm.partials.comments', ['target' => ['client_id' => $client->id]])</div>
            @if ($projectsSlot)<div x-show="tab === 'projects'" x-cloak>@includeIf('projects.partials.client-projects', ['client' => $client])</div>@endif
        </div>
    </div>

    @if ($canEdit)
        <x-modal name="client-edit" :title="t('clients.editTitle')" size="max-w-2xl" :open="$errors->any() && old('_form') === 'client-edit'">
            @include('crm.clients.form', ['client' => $client])
        </x-modal>
        <x-modal name="contact" :title="t('clients.contact')">
            <form method="POST" class="grid gap-4" x-data="{ c: { url: '', method: 'POST' } }" x-on:contact-edit.window="c = $event.detail" :action="c.url">
                @csrf <input type="hidden" name="_method" :value="c.method">
                @foreach (['full_name' => 'contactName', 'position' => 'position', 'phone' => 'phone', 'telegram' => 'telegram', 'whatsapp' => 'whatsapp', 'instagram' => 'instagram', 'email' => 'email'] as $field => $label)
                    <div><label class="label" for="ct-{{ $field }}">{{ t('crm.fields.'.$label) }}@if ($field === 'full_name')<span class="text-danger"> *</span>@endif</label>
                        <input id="ct-{{ $field }}" name="{{ $field }}" class="input" x-model="c.{{ $field }}" @if ($field === 'full_name') required maxlength="120" @endif></div>
                @endforeach
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_primary" value="1" class="size-4" x-model="c.is_primary"> {{ t('clients.primary') }}</label>
                <div class="flex justify-end gap-2">
                    <button type="button" class="btn btn-outline" x-on:click="$dispatch('close-modal', 'contact')">{{ t('common.cancel') }}</button>
                    <button type="submit" class="btn btn-primary">{{ t('common.save') }}</button>
                </div>
            </form>
        </x-modal>
        <x-modal name="requisites" :title="t('requisites.title')" size="max-w-2xl" :open="$errors->any() && old('_form') === 'requisites'">
            <form method="POST" action="{{ url('/clients/'.$client->id.'/requisites') }}" class="grid gap-4 sm:grid-cols-2">
                @csrf @method('PUT') <input type="hidden" name="_form" value="requisites">
                @php $defaults = ['legalName' => $client->name, 'directorPosition' => 'Директор', 'basis' => 'Устава', 'phone' => $client->phone]; @endphp
                @foreach ($reqFields as [$key, $wide])
                    <x-field :name="$key" :class="$wide ? 'sm:col-span-2' : ''" :label="t('requisites.'.$key)" :value="$req[$key] ?? ($defaults[$key] ?? '')"
                             :hint="in_array($key, ['signerGenitive', 'basis'], true) ? t('requisites.'.$key.'Hint') : null"
                             :inputmode="in_array($key, ['inn', 'mfo', 'account'], true) ? 'numeric' : null" />
                @endforeach
                <div class="flex justify-end gap-2 sm:col-span-2">
                    <button type="button" class="btn btn-outline" x-on:click="$dispatch('close-modal', 'requisites')">{{ t('requisites.cancel') }}</button>
                    <button type="submit" class="btn btn-primary">{{ t('requisites.save') }}</button>
                </div>
            </form>
        </x-modal>
    @endif
    @if (can('deal.create'))
        <x-modal name="deal-create" :title="t('deals.createTitle')" :open="$errors->any() && old('_form') === 'deal-create'">
            @include('crm.deals.form', ['deal' => null, 'fixedClient' => $client])
        </x-modal>
    @endif
@endsection
