{{-- Кнопка экспорта списка (Excel/CSV): @include('analytics.partials.export-menu', ['entity' => 'leads'|'deals'|'clients'|'payments'|'expenses'|'projects'|'tasks']) --}}
@include('analytics.export-menu', ['entity' => $entity, 'period' => $period ?? 'all'])
