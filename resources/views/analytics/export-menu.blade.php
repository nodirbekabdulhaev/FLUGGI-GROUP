{{--
  Экспорт списка в Excel или CSV (ТЗ §44). Файл формирует сервер — строго в пределах прав, выгрузка
  записывается в журнал аудита. Без права export.run кнопка не показывается.
  Подключение: @include('analytics.export-menu', ['entity' => 'leads'])  (leads|deals|clients|payments|expenses|projects|tasks)
--}}
@if (can('export.run'))
    <div class="relative" x-data="{ open: false }" @click.outside="open = false">
        <button type="button" class="btn btn-outline btn-sm" @click="open = !open"><x-icon name="download" /> {{ t('export.button') }}</button>
        <div x-show="open" x-cloak class="absolute right-0 z-20 mt-1 w-56 rounded-md border bg-surface py-1 shadow-lg">
            <p class="px-3 py-1.5 text-xs font-medium text-muted-foreground">{{ t('export.label') }}</p>
            <a href="{{ route('exports', ['entity' => $entity, 'format' => 'xlsx', 'period' => $period ?? 'all']) }}" class="block px-3 py-2 text-sm hover:bg-muted">{{ t('export.xlsx') }}</a>
            <a href="{{ route('exports', ['entity' => $entity, 'format' => 'csv', 'period' => $period ?? 'all']) }}" class="block px-3 py-2 text-sm hover:bg-muted">{{ t('export.csv') }}</a>
        </div>
    </div>
@endif
