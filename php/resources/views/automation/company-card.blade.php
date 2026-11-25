{{-- Реквизиты компании — в КП, договорах и пакете для бухгалтера. Подключение: @include('automation.company-card') --}}
@php($company = $company ?? \App\Support\Settings::company())
<x-card :title="t('company.title')" :subtitle="t('company.text')" id="company">
    <form method="POST" action="{{ route('automation.company') }}" class="grid gap-4 sm:grid-cols-2">
        @csrf @method('PUT')
        <h3 class="border-b pb-1 pt-2 text-sm font-semibold sm:col-span-2">{{ t('company.groupCompany') }}</h3>
        <x-field name="name" :label="t('company.name')" :hint="t('company.nameHint')" :value="$company['name']" />
        <x-field name="legalName" :label="t('company.legalName')" :hint="t('company.legalNameHint')" :value="$company['legalName']" />
        <x-field name="inn" :label="t('company.inn')" :value="$company['inn']" inputmode="numeric" maxlength="9" />
        <x-select name="taxRegime" :label="t('company.regime')" :value="$company['taxRegime']"
                  :options="collect(\App\Http\Controllers\Automation\AutomationController::TAX_REGIMES)->mapWithKeys(fn ($r) => [$r => t('company.regimes.'.$r)])->all()" />
        <x-field name="oked" :label="t('company.oked')" :value="$company['oked']" />
        <x-field name="vatCode" :label="t('company.vatCode')" :value="$company['vatCode']" />
        <h3 class="border-b pb-1 pt-2 text-sm font-semibold sm:col-span-2">{{ t('company.groupSigner') }}</h3>
        <x-field name="director" :label="t('company.director')" :hint="t('company.directorHint')" :value="$company['director']" />
        <x-field name="directorPosition" :label="t('company.directorPosition')" :value="$company['directorPosition']" />
        <x-field name="signerGenitive" :label="t('company.signerGenitive')" :hint="t('company.signerGenitiveHint')" :value="$company['signerGenitive']" />
        <x-field name="basis" :label="t('company.basis')" :hint="t('company.basisHint')" :value="$company['basis']" />
        <x-field name="accountant" :label="t('company.accountant')" :value="$company['accountant']" />
        <h3 class="border-b pb-1 pt-2 text-sm font-semibold sm:col-span-2">{{ t('company.groupBank') }}</h3>
        <x-field name="bank" :label="t('company.bank')" :value="$company['bank']" />
        <x-field name="mfo" :label="t('company.mfo')" :value="$company['mfo']" inputmode="numeric" maxlength="5" />
        <x-field name="account" :label="t('company.account')" :value="$company['account']" inputmode="numeric" maxlength="20" class="sm:col-span-2" />
        <h3 class="border-b pb-1 pt-2 text-sm font-semibold sm:col-span-2">{{ t('company.groupContacts') }}</h3>
        <x-field name="address" :label="t('company.address')" :value="$company['address']" class="sm:col-span-2" />
        <x-field name="phone" :label="t('company.phone')" :value="$company['phone']" />
        <x-field name="email" type="email" :label="t('company.email')" :value="$company['email']" />
        <x-field name="website" :label="t('company.website')" :value="$company['website']" />
        <div class="sm:col-span-2"><button class="btn btn-primary">{{ t('company.saveRequisites') }}</button></div>
    </form>
</x-card>
