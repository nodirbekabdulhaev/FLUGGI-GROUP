@extends('layouts.app')
@section('title', t('accounting.settings.title'))
@section('content')
    <x-page-header :title="t('accounting.settings.title')" :subtitle="t('accounting.settings.subtitle')" />
    @include('accounting.partials.tabs', ['active' => '/accounting/settings'])
    <x-card class="max-w-xl">
        <form method="POST" action="/accounting/settings" class="space-y-4">
            @csrf @method('PUT')
            <x-field name="ndflRate" inputmode="decimal" :label="t('accounting.settings.ndflRate')" :value="$settings['ndflRate']" required />
            <x-field name="inpsRate" inputmode="decimal" :label="t('accounting.settings.inpsRate')" :value="$settings['inpsRate']" required />
            <label class="flex items-start gap-2 text-sm"><input type="checkbox" name="inpsWithinNdfl" value="1" class="mt-0.5" @checked(old('inpsWithinNdfl', $settings['inpsWithinNdfl']))>
                <span>{{ t('accounting.settings.inpsWithinNdfl') }}<span class="block text-xs text-muted-foreground">{{ t('accounting.settings.inpsWithinNdflHint') }}</span></span></label>
            <x-field name="itparkRate" inputmode="decimal" :label="t('accounting.settings.itparkRate')" :hint="t('accounting.settings.itparkHint')" :value="$settings['itparkRate']" required />
            <div class="flex justify-end"><button class="btn btn-primary">{{ t('common.save') }}</button></div>
        </form>
    </x-card>
@endsection
