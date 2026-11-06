{{-- «Просрочено N дней» (ТЗ §24) --}}
@if (($days ?? 0) > 0)
    <x-badge color="red" class="{{ $class ?? '' }}"><x-icon name="alert" class="mr-1 size-3" />{{ t('projects.overdue', ['count' => $days]) }}</x-badge>
@endif
