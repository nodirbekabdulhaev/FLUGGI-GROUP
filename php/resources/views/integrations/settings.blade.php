@extends('layouts.app')
@section('title', t('integrations.title'))
@php
    $base = rtrim(url('/'), '/');
    $formJs = fn ($f) => [
        'action' => route('integrations.forms.update', $f->id), 'method' => 'PUT', 'name' => $f->name, 'title' => $f->title,
        'description' => (string) $f->description, 'button_text' => $f->button_text, 'success_message' => $f->success_message,
        'fields' => array_map(fn ($x) => $x + ['options' => [], 'required' => false], $f->fields ?? []), 'service_id' => (string) $f->service_id, 'source_id' => (string) $f->source_id,
        'owner_id' => (string) $f->owner_id, 'team_id' => (string) $f->team_id, 'is_active' => (bool) $f->is_active,
    ];
    $newForm = ['action' => route('integrations.forms.store'), 'method' => 'POST', 'name' => '', 'title' => t('integrations.defaultTitle'), 'description' => '',
        'button_text' => t('integrations.defaultButton'), 'success_message' => t('integrations.defaultSuccess'),
        'fields' => array_map(fn ($x) => $x + ['options' => []], $defaultFields), 'service_id' => '', 'source_id' => '', 'owner_id' => '', 'team_id' => '', 'is_active' => true];
@endphp
@section('content')
    <x-page-header :title="t('integrations.title')" :subtitle="t('integrations.subtitle')" back="/settings" />
    <div class="grid gap-6" x-data="integrations">
        <x-card :title="t('integrations.formsTitle')" :subtitle="t('integrations.formsText')" :padding="false">
            <x-slot:actions>
                <button type="button" class="btn btn-primary btn-sm" @click="edit(@js($newForm))"><x-icon name="plus" /> {{ t('integrations.formNew') }}</button>
            </x-slot:actions>
            @if (! $forms)
                <x-empty icon="file" :title="t('integrations.noForms')">{{ t('integrations.noFormsText') }}</x-empty>
            @else
                <div class="overflow-x-auto">
                    <table class="table">
                        <thead><tr><th>{{ t('integrations.formName') }}</th><th class="text-right">{{ t('integrations.stats') }}</th><th>{{ t('integrations.last') }}</th><th></th></tr></thead>
                        <tbody>
                            @foreach ($forms as $row)
                                @php($f = $row['form'])
                                <tr @class(['opacity-60' => ! $f->is_active])>
                                    <td><span class="font-medium">{{ $f->name }}</span> @unless ($f->is_active)<x-badge>{{ t('integrations.off') }}</x-badge>@endunless
                                        <span class="block text-xs text-muted-foreground">{{ $f->owner?->full_name ?? t('integrations.ownerAuto') }}</span></td>
                                    <td class="whitespace-nowrap text-right tabular-nums">{{ t('integrations.statsValue', ['submissions' => $row['submissions'], 'leads' => $row['leads']]) }}</td>
                                    <td class="whitespace-nowrap text-xs text-muted-foreground">{{ $row['last'] ? dt($row['last']) : '—' }}</td>
                                    <td class="whitespace-nowrap text-right">
                                        <div class="flex justify-end gap-1">
                                            <button type="button" class="btn btn-outline btn-sm" @click="code = @js(['name' => $f->name, 'embed' => '<div data-fluggi-form="'.$f->key.'"></div>'."\n".'<script src="'.$base.'/embed.js" async></script>', 'link' => $base.'/f/'.$f->key, 'webhook' => $base.'/hooks/intake/'.$f->key]); $dispatch('open-modal', 'form-code')"><x-icon name="link" /> {{ t('integrations.code') }}</button>
                                            <button type="button" class="btn btn-ghost btn-sm" aria-label="{{ t('integrations.submissions') }}: {{ $f->name }}" @click="loadSubmissions(@js(route('integrations.forms.submissions', $f->id)), @js($f->name))"><x-icon name="list" /></button>
                                            <button type="button" class="btn btn-ghost btn-sm" aria-label="{{ t('integrations.formEdit') }}: {{ $f->name }}" @click="edit(@js($formJs($f)))"><x-icon name="edit" /></button>
                                            <form method="POST" action="{{ route('integrations.forms.destroy', $f->id) }}" data-confirm="{{ t('integrations.deleteConfirm', ['name' => $f->name]) }}">@csrf @method('DELETE')
                                                <button class="btn btn-ghost btn-sm" aria-label="{{ t('integrations.delete') }}: {{ $f->name }}"><x-icon name="trash" /></button></form>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-card>

        <x-card :title="t('integrations.metaTitle')" :subtitle="t('integrations.metaText')">
            <div class="mb-6 grid gap-2 rounded-lg border p-3 text-sm">
                <p>
                    @if ($meta['configured'])<x-badge color="green">{{ t('integrations.metaOn') }}</x-badge>
                    @else<x-badge color="yellow">{{ t('integrations.metaOff') }}</x-badge>
                        <span class="text-muted-foreground">{{ t('integrations.metaMissing') }} <code class="text-xs">{{ implode(', ', $meta['missing']) }}</code></span>@endif
                </p>
                <p class="break-all"><span class="text-muted-foreground">{{ t('integrations.webhookUrl') }}:</span> <code class="text-xs" data-testid="meta-webhook">{{ $meta['webhookUrl'] }}</code></p>
                <p class="text-muted-foreground">{{ t('integrations.metaStats', ['threads' => $meta['threads'], 'ads' => $meta['adsLeads']]) }}@if ($meta['lastEventAt']) · {{ t('integrations.lastEvent') }}: {{ dt($meta['lastEventAt']) }}@endif</p>
                @if ($meta['lastError'])<p class="text-danger">{{ t('integrations.lastError') }}: {{ $meta['lastError'] }}</p>@endif
                <details class="text-muted-foreground">
                    <summary class="cursor-pointer text-foreground">{{ t('integrations.howTo') }}</summary>
                    <ol class="mt-2 list-decimal space-y-1 pl-5 text-xs">@foreach (range(1, 6) as $i)<li>{{ t('integrations.how'.$i) }}</li>@endforeach</ol>
                </details>
            </div>
            <form method="POST" action="{{ route('integrations.meta') }}" class="grid gap-4 sm:grid-cols-2">
                @csrf @method('PUT')
                <x-select name="ownerId" :label="t('integrations.owner')" :hint="t('integrations.ownerHint')" :options="$owners" :value="$settings['ownerId']" :placeholder="t('integrations.ownerAuto')" />
                <x-select name="teamId" :label="t('integrations.team')" :options="$teams" :value="$settings['teamId']" :placeholder="t('integrations.anyTeam')" />
                <x-select name="serviceId" :label="t('integrations.service')" :options="$services" :value="$settings['serviceId']" placeholder="—" />
                <x-select name="autoLeadFromComments" :label="t('integrations.commentsMode')" :value="$settings['autoLeadFromComments']"
                          :options="['keywords' => t('integrations.commentsKeywords'), 'all' => t('integrations.commentsAll'), 'off' => t('integrations.commentsOff')]" />
                <label class="flex items-center gap-2 text-sm sm:col-span-2"><input type="hidden" name="autoLeadFromDirect" value="0"><input type="checkbox" name="autoLeadFromDirect" value="1" @checked($settings['autoLeadFromDirect'])> {{ t('integrations.directAuto') }}</label>
                <x-field name="commentKeywords" :label="t('integrations.keywords')" :hint="t('integrations.keywordsHint')" :value="implode(', ', $settings['commentKeywords'])" class="sm:col-span-2" />
                <div><button class="btn btn-primary">{{ t('integrations.save') }}</button></div>
            </form>
        </x-card>

        {{-- Автопубликация SMM в Instagram: права токена страницы, аккаунт, лимит, место на диске --}}
        <x-card :title="t('smm.ig.settingsTitle')" :subtitle="t('smm.ig.settingsText')" id="instagram-publishing">
            <div class="grid gap-2 text-sm">
                <p>
                    @if (! $instagram['tokenSet'])
                        <x-badge color="yellow">{{ t('integrations.metaOff') }}</x-badge> <span class="text-muted-foreground">{{ t('integrations.errors.noPageToken') }}</span>
                    @elseif ($instagram['checkedAt'] && ! $instagram['error'])
                        <x-badge color="green">{{ t('smm.ig.accessOk') }}</x-badge>
                        @if ($instagram['username'])<span>{{ '@'.$instagram['username'] }}</span>@endif
                    @elseif ($instagram['error'])
                        <x-badge color="red">{{ t('smm.ig.accessError') }}</x-badge>
                    @else
                        <x-badge color="gray">{{ t('smm.ig.notChecked') }}</x-badge>
                    @endif
                </p>
                @if ($instagram['error'])<p class="text-danger" data-testid="ig-error">{{ $instagram['error'] }}</p>@endif
                @if ($instagram['checkedAt'])
                    <p class="text-muted-foreground">{{ t('smm.ig.checkedAt') }}: {{ dt($instagram['checkedAt']) }}
                        @if ($instagram['quotaTotal']) · {{ t('smm.ig.quota', ['usage' => $instagram['quotaUsage'] ?? 0, 'total' => $instagram['quotaTotal']]) }}@endif</p>
                @endif
                <p class="text-muted-foreground">{{ t('smm.ig.stats', ['scheduled' => $instagram['scheduled'], 'published' => $instagram['published']]) }}</p>
                <p class="text-muted-foreground" data-testid="ig-disk">{{ t('smm.ig.disk', ['size' => $instagram['diskUsage']]) }}</p>
                <p class="text-xs text-muted-foreground">{{ t('smm.ig.scopesHint') }}</p>
            </div>
            <form method="POST" action="{{ route('integrations.instagram.check') }}" class="mt-4">
                @csrf
                <button class="btn btn-outline" @disabled(! $instagram['tokenSet'])><x-icon name="refresh" /> {{ t('smm.ig.check') }}</button>
            </form>
        </x-card>

        {{-- Форма: создание и правка --}}
        <x-modal name="lead-form" :title="t('integrations.formEdit')" size="max-w-3xl">
            <form method="POST" :action="f.action" class="grid gap-4">
                @csrf
                <input type="hidden" name="_method" :value="f.method">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div><label class="label" for="lf-name">{{ t('integrations.formName') }} <span class="text-danger">*</span></label>
                        <input id="lf-name" name="name" class="input" x-model="f.name" required maxlength="120"><p class="hint">{{ t('integrations.formNameHint') }}</p></div>
                    <div><label class="label" for="lf-title">{{ t('integrations.formTitle') }} <span class="text-danger">*</span></label>
                        <input id="lf-title" name="title" class="input" x-model="f.title" required maxlength="200"></div>
                </div>
                <div><label class="label" for="lf-desc">{{ t('integrations.formDescription') }}</label>
                    <textarea id="lf-desc" name="description" rows="2" class="input" x-model="f.description" maxlength="1000"></textarea></div>
                <div class="grid gap-2">
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-sm font-medium">{{ t('integrations.fields') }}</span>
                        <button type="button" class="btn btn-outline btn-sm" @click="f.fields.push({ key: 'field_' + (f.fields.length + 1), label: '', type: 'text', required: false, options: [] })"><x-icon name="plus" /> {{ t('integrations.addField') }}</button>
                    </div>
                    <p class="text-xs text-muted-foreground">{{ t('integrations.fieldsHint') }}</p>
                    @error('fields')<p class="error">{{ $message }}</p>@enderror
                    <template x-for="(fl, i) in f.fields" :key="i">
                        <div class="grid gap-2 rounded-md border p-2 sm:grid-cols-[1fr_8rem_8rem_auto_2.5rem] sm:items-center">
                            <input class="input" :name="'fields[' + i + '][label]'" x-model="fl.label" required maxlength="120" placeholder="{{ t('integrations.fieldLabel') }}" :aria-label="@js(t('integrations.fieldLabel')) + ' ' + (i + 1)">
                            <input class="input font-mono text-xs" :name="'fields[' + i + '][key]'" x-model="fl.key" @input="fl.key = fl.key.toLowerCase().replace(/[^a-z0-9_]/g, '_')" required maxlength="40" placeholder="key" :aria-label="@js(t('integrations.fieldKey')) + ' ' + (i + 1)">
                            <select class="input" :name="'fields[' + i + '][type]'" x-model="fl.type" :aria-label="@js(t('integrations.fieldType')) + ' ' + (i + 1)">
                                @foreach (\App\Services\Integrations\FormService::FIELD_TYPES as $ft)<option value="{{ $ft }}">{{ t('integrations.types.'.$ft) }}</option>@endforeach
                            </select>
                            <label class="flex items-center gap-1 text-xs"><input type="hidden" :name="'fields[' + i + '][required]'" :value="fl.required ? 1 : 0"><input type="checkbox" x-model="fl.required"> {{ t('integrations.required') }}</label>
                            <button type="button" class="btn btn-ghost btn-sm" aria-label="{{ t('integrations.removeField') }}" @click="f.fields.splice(i, 1)"><x-icon name="trash" /></button>
                            <input x-show="fl.type === 'select'" class="input sm:col-span-5" :name="fl.type === 'select' ? 'fields[' + i + '][options]' : ''" :value="(fl.options || []).join('; ')" placeholder="{{ t('integrations.optionsHint') }}" :aria-label="@js(t('integrations.options')) + ' ' + (i + 1)">
                        </div>
                    </template>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div><label class="label" for="lf-btn">{{ t('integrations.buttonText') }}</label><input id="lf-btn" name="button_text" class="input" x-model="f.button_text" maxlength="60"></div>
                    <div><label class="label" for="lf-ok">{{ t('integrations.successMessage') }}</label><input id="lf-ok" name="success_message" class="input" x-model="f.success_message" maxlength="500"></div>
                    <div><label class="label" for="lf-svc">{{ t('integrations.service') }}</label>
                        <select id="lf-svc" name="service_id" class="input" x-model="f.service_id"><option value="">—</option>@foreach ($services as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select>
                        <p class="hint">{{ t('integrations.serviceHint') }}</p></div>
                    <div><label class="label" for="lf-src">{{ t('integrations.source') }}</label>
                        <select id="lf-src" name="source_id" class="input" x-model="f.source_id"><option value="">{{ t('integrations.sourceDefault') }}</option>@foreach ($sources as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select></div>
                    <div><label class="label" for="lf-owner">{{ t('integrations.owner') }}</label>
                        <select id="lf-owner" name="owner_id" class="input" x-model="f.owner_id"><option value="">{{ t('integrations.ownerAuto') }}</option>@foreach ($owners as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select>
                        <p class="hint">{{ t('integrations.ownerHint') }}</p></div>
                    <div><label class="label" for="lf-team">{{ t('integrations.team') }}</label>
                        <select id="lf-team" name="team_id" class="input" x-model="f.team_id"><option value="">{{ t('integrations.anyTeam') }}</option>@foreach ($teams as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select></div>
                </div>
                <label class="flex items-center gap-2 text-sm"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" x-model="f.is_active"> {{ t('integrations.active') }}</label>
                <div class="flex justify-end gap-2">
                    <button type="button" class="btn btn-outline" @click="$dispatch('close-modal', 'lead-form')">{{ t('integrations.cancel') }}</button>
                    <button class="btn btn-primary">{{ t('integrations.save') }}</button>
                </div>
            </form>
        </x-modal>

        <x-modal name="form-code" :title="t('integrations.code')" size="max-w-2xl">
            <div class="grid gap-4">
                <p class="font-medium" x-text="code.name"></p>
                @foreach (['embed' => ['embedTitle', 'embedHint'], 'link' => ['linkTitle', 'linkHint'], 'webhook' => ['webhookTitle', 'webhookHint']] as $k => [$title, $hint])
                    <div class="grid gap-1">
                        <p class="text-sm font-medium">{{ t('integrations.'.$title) }}</p>
                        <p class="text-xs text-muted-foreground">{{ t('integrations.'.$hint) }}</p>
                        <div class="flex gap-2">
                            <pre class="min-w-0 flex-1 overflow-x-auto whitespace-pre-wrap break-all rounded-md bg-muted p-2 font-mono text-xs" x-text="code.{{ $k }}"></pre>
                            <button type="button" class="btn btn-outline btn-sm" aria-label="{{ t('integrations.copy') }}: {{ t('integrations.'.$title) }}" @click="copy(code.{{ $k }})"><x-icon name="copy" /></button>
                        </div>
                    </div>
                @endforeach
                <a :href="code.link" target="_blank" rel="noopener noreferrer" class="link inline-flex items-center gap-1 text-sm"><x-icon name="eye" /> {{ t('integrations.preview') }}</a>
            </div>
        </x-modal>

        <x-modal name="form-submissions" :title="t('integrations.submissions')" size="max-w-3xl">
            <p class="mb-2 font-medium" x-text="subsName"></p>
            <p x-show="subsLoading" class="py-6 text-center text-sm text-muted-foreground">{{ t('common.loading') }}</p>
            <p x-show="!subsLoading && !subs.length" class="py-6 text-center text-sm text-muted-foreground">{{ t('integrations.noSubmissions') }}</p>
            <div x-show="subs.length" class="max-h-[60vh] overflow-auto">
                <table class="table">
                    <thead><tr><th>{{ t('integrations.when') }}</th><th>{{ t('integrations.data') }}</th><th>{{ t('integrations.result') }}</th></tr></thead>
                    <tbody>
                        <template x-for="s in subs" :key="s.id">
                            <tr>
                                <td class="whitespace-nowrap text-xs" x-text="s.createdAt"></td>
                                <td class="break-words text-xs"><span x-text="Object.entries(s.data).map(([k, v]) => k + ': ' + v).join(' · ')"></span>
                                    <span x-show="s.utm" class="block text-muted-foreground" x-text="s.utm ? Object.entries(s.utm).map(([k, v]) => k + '=' + v).join(', ') : ''"></span></td>
                                <td class="whitespace-nowrap text-xs"><span class="badge" :class="s.result === 'LEAD_CREATED' ? 'badge-green' : (s.result === 'SPAM' ? 'badge-red' : 'badge-gray')" x-text="s.resultLabel"></span>
                                    <a x-show="s.lead" :href="s.lead ? '/sales/leads/' + s.lead.id : '#'" class="link ml-1" x-text="s.lead?.number"></a></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </x-modal>
    </div>
    <script>
    document.addEventListener('alpine:init', () => {
        Alpine.data('integrations', () => ({
            f: @js($newForm), code: {}, subs: [], subsName: '', subsLoading: false,
            edit(form) { this.f = JSON.parse(JSON.stringify(form)); this.$dispatch('open-modal', 'lead-form'); },
            copy(text) { navigator.clipboard.writeText(text).then(() => window.toast(@js(t('integrations.copied'))), () => window.toast(@js(t('integrations.copyFailed')), 'error')); },
            async loadSubmissions(url, name) {
                this.subsName = name; this.subs = []; this.subsLoading = true;
                this.$dispatch('open-modal', 'form-submissions');
                try { this.subs = await window.api(url); } catch (e) { window.toast(e.message, 'error'); } finally { this.subsLoading = false; }
            },
        }));
    });
    </script>
@endsection
