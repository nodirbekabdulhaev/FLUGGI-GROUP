@extends('layouts.app')
@section('title', t('accounting.bank.title'))
@section('content')
    <x-page-header :title="t('accounting.bank.title')" :subtitle="t('accounting.bank.subtitle')">
        <a href="/accounting/operations" class="btn btn-outline">{{ t('accounting.tabs.operations') }}</a>
    </x-page-header>
    @include('accounting.partials.tabs', ['active' => '/accounting/operations'])

    <div class="grid gap-4 lg:grid-cols-3">
        <x-card :title="t('accounting.bank.uploadTitle')" class="lg:col-span-2">
            @if ($accounts->isEmpty())
                <x-empty icon="wallet" :title="t('accounting.accounts.empty')">{{ t('accounting.accounts.emptyText') }}</x-empty>
            @else
                <form method="POST" action="/accounting/bank-import" enctype="multipart/form-data" class="space-y-4">
                    @csrf
                    <div>
                        <label class="label" for="bank-file">{{ t('accounting.bank.file') }}<span class="text-danger"> *</span></label>
                        <input id="bank-file" type="file" name="file" class="input" accept=".xlsx,.xls,.csv" required>
                        @error('file')<p class="error">{{ $message }}</p>@else<p class="hint">{{ t('accounting.bank.fileHint') }}</p>@enderror
                    </div>
                    <x-select name="account_id" :label="t('accounting.fields.account')" :placeholder="t('accounting.bank.autoAccount')"
                              :options="$accounts->mapWithKeys(fn ($a) => [$a->id => $a->name.' · '.$a->currency.($a->account_number ? ' · '.$a->account_number : '')])->all()"
                              :hint="t('accounting.bank.accountHint')" />
                    <div class="flex justify-end">
                        <button class="btn btn-primary"><x-icon name="upload" /> {{ t('accounting.bank.read') }}</button>
                    </div>
                </form>
            @endif
        </x-card>
        <x-card :title="t('accounting.bank.howTitle')">
            <ul class="list-disc space-y-2 pl-5 text-sm text-muted-foreground">
                <li>{{ t('accounting.bank.how1') }}</li>
                <li>{{ t('accounting.bank.how2') }}</li>
                <li>{{ t('accounting.bank.how3') }}</li>
                <li>{{ t('accounting.bank.how4') }}</li>
            </ul>
        </x-card>
    </div>
@endsection
