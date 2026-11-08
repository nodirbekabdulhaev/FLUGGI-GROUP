@extends('layouts.app')
@section('title', t('accounting.settings.title'))
@section('content')
    <x-page-header :title="t('accounting.settings.title')" :subtitle="t('accounting.settings.subtitle')" />
    @include('accounting.partials.tabs', ['active' => '/accounting/settings'])
    <form method="POST" action="/accounting/settings" class="max-w-xl space-y-4">
        @csrf @method('PUT')
        <x-card>
            <div class="space-y-4">
                <x-field name="ndflBaseRate" inputmode="decimal" :label="t('accounting.settings.ndflBaseRate')" :hint="t('accounting.settings.ndflBaseRateHint')" :value="$settings['ndflBaseRate']" required />
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-field name="benefitCode" inputmode="numeric" maxlength="10" :label="t('accounting.settings.benefitCode')" :hint="t('accounting.settings.benefitCodeHint')" :value="$settings['benefitCode']" />
                    <x-field name="benefitPercent" inputmode="decimal" :label="t('accounting.settings.benefitPercent')" :hint="t('accounting.settings.benefitPercentHint')" :value="$settings['benefitPercent']" required />
                </div>
                <p class="text-sm">{{ t('accounting.settings.effectiveRate', ['rate' => $settings['ndflRate']]) }}</p>
                <x-field name="inpsRate" inputmode="decimal" :label="t('accounting.settings.inpsRate')" :value="$settings['inpsRate']" required />
                <label class="flex items-start gap-2 text-sm"><input type="checkbox" name="inpsWithinNdfl" value="1" class="mt-0.5" @checked(old('inpsWithinNdfl', $settings['inpsWithinNdfl']))>
                    <span>{{ t('accounting.settings.inpsWithinNdfl') }}<span class="block text-xs text-muted-foreground">{{ t('accounting.settings.inpsWithinNdflHint') }}</span></span></label>
                <x-field name="itparkRate" inputmode="decimal" :label="t('accounting.settings.itparkRate')" :hint="t('accounting.settings.itparkHint')" :value="$settings['itparkRate']" required />
            </div>
        </x-card>
        <x-card id="tax-balance">
            <h2 class="mb-1 font-medium">{{ t('accounting.settings.taxBalance') }}</h2>
            <p class="mb-3 text-sm text-muted-foreground">{{ t('accounting.settings.taxBalanceHint') }}</p>
            <div class="space-y-4">
                <x-field name="taxBalanceFrom" type="month" :label="t('accounting.settings.taxBalanceFrom')" :hint="t('accounting.settings.taxBalanceFromHint')" :value="$settings['taxBalanceFrom']" />
                <div class="grid gap-4 sm:grid-cols-3">
                    @foreach (\App\Services\Accounting\Accounting::OPENING_KEYS as $cat => $key)
                        <x-field :name="$key" inputmode="decimal" data-money data-allow-negative :label="t('accounting.settings.opening', ['tax' => t('accounting.category.'.$cat)])" :value="(string) \Brick\Math\BigDecimal::of($settings[$key])->stripTrailingZeros()" />
                    @endforeach
                </div>
                <p class="text-xs text-muted-foreground">{{ t('accounting.settings.openingHint') }}</p>
            </div>
        </x-card>
        <x-card>
            <h2 class="mb-3 font-medium">{{ t('accounting.settings.taxReport') }}</h2>
            <div class="space-y-4">
                <x-field name="companyInn" inputmode="numeric" maxlength="9" :label="t('accounting.settings.companyInn')" :placeholder="$companyInn"
                         :hint="$companyInn !== '' ? t('accounting.settings.companyInnFromCompany', ['inn' => $companyInn]) : t('accounting.settings.companyInnHint')" :value="$settings['companyInn']" />
                <x-field name="taxReportCode" :label="t('accounting.settings.taxReportCode')" :hint="t('accounting.settings.taxReportCodeHint')" :value="$settings['taxReportCode']" maxlength="30" />
                <x-field name="taxReportFileName" :label="t('accounting.settings.taxReportFileName')" :hint="t('accounting.settings.taxReportFileNameHint')" :value="$settings['taxReportFileName']" maxlength="191" />
            </div>
        </x-card>
        <div class="flex justify-end"><button class="btn btn-primary">{{ t('common.save') }}</button></div>
    </form>
@endsection
