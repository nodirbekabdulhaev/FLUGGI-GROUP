<div class="card overflow-x-auto"><table class="min-w-full text-sm">
    <thead class="bg-slate-50 dark:bg-slate-800/50"><tr><th class="th">{{ __('Источник') }}</th><th class="th text-right">{{ __('Лиды') }}</th><th class="th text-right">{{ __('Продажи') }}</th><th class="th text-right">{{ __('Конверсия') }}</th><th class="th text-right">{{ __('Выручка') }}</th><th class="th text-right">{{ __('Ср. чек') }}</th></tr></thead>
    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
    @forelse($d['sources'] as $s)
        <tr><td class="td font-medium">{{ $s['name'] }}</td><td class="td text-right">{{ $s['leads'] }}</td><td class="td text-right">{{ $s['sales'] }}</td>
            <td class="td text-right font-semibold">{{ $s['conversion'] === null ? '—' : $s['conversion'].'%' }}</td><td class="td text-right">{{ money($s['revenue'], false) }}</td><td class="td text-right">{{ money($s['avg_check'], false) }}</td></tr>
    @empty<tr><td colspan="6" class="px-3 py-8 text-center text-slate-400">{{ __('Нет данных') }}</td></tr>@endforelse
    </tbody></table></div>
