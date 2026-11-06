@extends('layouts.app')
@section('title', t('documents.settingsTitle'))
@section('content')
    @php
        $s = $settings;
        $fields = ['legalName', 'name', 'inn', 'director', 'signerGenitive', 'bank', 'mfo', 'account', 'address', 'phone'];
    @endphp
    <x-page-header :title="t('documents.settingsTitle')" :subtitle="t('documents.settingsSubtitle')" :back="url('/settings')" />
    <div class="grid gap-6">
        {{-- Реквизиты компании редактируются в «Настройки → Компания»; здесь — что попадёт в документы --}}
        <x-card :title="t('documents.companyTitle')" :subtitle="t('documents.companyText')">
            @if (\Illuminate\Support\Facades\Route::has('automation'))
                <x-slot:actions>
                    <a class="btn btn-outline btn-sm" href="{{ route('automation') }}#company"><x-icon name="edit" /> {{ t('common.edit') }}</a>
                </x-slot:actions>
            @endif
            <dl class="grid grid-cols-1 gap-x-4 gap-y-1.5 text-sm sm:grid-cols-[auto,1fr]">
                @foreach ($fields as $k)
                    <dt class="muted">{{ t('documents.company.'.$k) }}</dt>
                    <dd class="break-words">{{ ($company[$k] ?? '') !== '' ? $company[$k] : '—' }}</dd>
                @endforeach
            </dl>
        </x-card>

        <x-card :title="t('documents.templatesTitle')" :subtitle="t('documents.templatesText')">
            <form method="POST" action="{{ route('sales.documents.save') }}" class="grid gap-4"
                  x-data="{ tpl: @js(old('contractTemplate', $s['contractTemplate'])), def: @js(\App\Services\Sales\DocumentsService::DEFAULT_CONTRACT_TEMPLATE),
                            insert(key) { const el = this.$refs.area; const token = `@{{${key}}}`; const a = el.selectionStart, b = el.selectionEnd;
                                this.tpl = this.tpl.slice(0, a) + token + this.tpl.slice(b);
                                this.$nextTick(() => { el.focus(); el.setSelectionRange(a + token.length, a + token.length); }); } }">
                @csrf @method('PUT')
                <x-field name="city" class="sm:w-64" :label="t('documents.city')" :value="$s['city']" required maxlength="60" />
                <x-field name="proposalIntro" type="textarea" rows="2" :label="t('documents.proposalIntro')" :value="$s['proposalIntro']" maxlength="4000" />
                <x-field name="proposalNote" type="textarea" rows="2" :label="t('documents.proposalNote')" :hint="t('documents.proposalNoteHint')" :value="$s['proposalNote']" maxlength="4000" />
                <div class="grid gap-4 lg:grid-cols-[1fr_16rem]">
                    <div>
                        <label class="label" for="ds-contract">{{ t('documents.contractTemplate') }}</label>
                        <textarea id="ds-contract" name="contractTemplate" rows="22" class="input font-mono text-xs" x-ref="area" x-model="tpl" maxlength="50000"></textarea>
                        @error('contractTemplate')<p class="error">{{ $message }}</p>@else<p class="hint">{{ t('documents.contractTemplateHint') }}</p>@enderror
                    </div>
                    <div class="grid content-start gap-1 text-xs">
                        <p class="text-sm font-medium">{{ t('documents.placeholders') }}</p>
                        <p class="text-muted-foreground">{{ t('documents.placeholdersText') }}</p>
                        @foreach (\App\Services\Sales\DocumentsService::PLACEHOLDERS as $key)
                            @php $token = str_repeat('{', 2).$key.str_repeat('}', 2); @endphp
                            <button type="button" class="rounded border px-2 py-1 text-left hover:bg-muted" x-on:click="insert(@js($key))">
                                <code class="text-accent">{{ $token }}</code>
                                <span class="block text-muted-foreground">{{ t('documents.placeholder.'.str_replace('.', '_', $key)) }}</span>
                            </button>
                        @endforeach
                        <p class="mt-2 text-muted-foreground">{{ t('documents.blocksText') }}</p>
                    </div>
                </div>
                <div class="flex flex-wrap gap-2">
                    <button class="btn btn-primary">{{ t('documents.saveTemplates') }}</button>
                    <button type="button" class="btn btn-outline" x-on:click="if (confirm(@js(t('documents.resetConfirm')))) tpl = def">{{ t('documents.reset') }}</button>
                </div>
            </form>
        </x-card>
    </div>
@endsection
