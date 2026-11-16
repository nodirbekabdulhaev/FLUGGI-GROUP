{{-- Кнопка «Импорт» рядом с меню экспорта списка: @include('import.partials.button', ['entity' => 'leads'|'deals'|'clients'|'projects'|'attendance']) --}}
@php($importClass = \App\Services\Import\ImportService::IMPORTERS[$entity] ?? null)
@if ($importClass && can($importClass::permission()))
    <a href="{{ route('import.'.$entity) }}" class="btn btn-outline btn-sm"><x-icon name="upload" /> {{ t('import.button') }}</a>
@endif
