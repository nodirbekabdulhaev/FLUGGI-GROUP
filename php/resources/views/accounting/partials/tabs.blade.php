{{-- Подразделы бухгалтерии --}}
@php
    $tabs = [
        '/accounting' => t('accounting.tabs.overview'),
        '/accounting/operations' => t('accounting.tabs.operations'),
        '/accounting/accounts' => t('accounting.tabs.accounts'),
        '/accounting/categories' => t('accounting.tabs.categories'),
        '/accounting/people' => t('accounting.tabs.people'),
        '/accounting/payroll' => t('accounting.tabs.payroll'),
        '/accounting/taxes' => t('accounting.tabs.taxes'),
        '/accounting/tax-report' => t('accounting.tabs.taxReport'),
        '/accounting/filed-reports' => t('accounting.tabs.filed'),
        '/accounting/statements' => t('accounting.tabs.statements'),
    ];
    if (access()->can('accounting.manage')) {
        $tabs['/accounting/settings'] = t('accounting.tabs.settings');
    }
@endphp
<x-tabs :items="$tabs" :active="$active" />
