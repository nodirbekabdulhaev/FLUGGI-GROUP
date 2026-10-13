{{-- Подразделы бухгалтерии --}}
@php
    $tabs = [
        '/accounting' => t('accounting.tabs.overview'),
        '/accounting/operations' => t('accounting.tabs.operations'),
        '/accounting/accounts' => t('accounting.tabs.accounts'),
        '/accounting/people' => t('accounting.tabs.people'),
        '/accounting/payroll' => t('accounting.tabs.payroll'),
        '/accounting/taxes' => t('accounting.tabs.taxes'),
    ];
    if (access()->can('accounting.manage')) {
        $tabs['/accounting/settings'] = t('accounting.tabs.settings');
    }
@endphp
<x-tabs :items="$tabs" :active="$active" />
