{{-- Строка «Моего дня» (Alpine: переменная it) --}}
@php($kindIcons = ['meeting' => 'calendar', 'task' => 'check-square', 'todo' => 'list', 'followUp' => 'refresh', 'deal' => 'briefcase', 'smm' => 'play', 'payment' => 'dollar'])
<li class="flex min-w-0 items-start gap-3 rounded-xl px-2 py-2 hover:bg-muted/40" :class="it.overdue && 'bg-danger-soft/60'">
    <span class="w-12 shrink-0 pt-0.5 text-right text-sm font-semibold tabular-nums" :class="it.overdue ? 'text-danger' : ''">
        <span x-text="it.time ?? ''"></span>
        <span x-show="!it.time" class="text-xs font-normal text-muted-foreground">{{ t('home.agenda.allDay') }}</span>
        <span x-show="it.end" class="block text-xs font-normal text-muted-foreground" x-text="it.end"></span>
    </span>
    <span class="mt-0.5 grid size-7 shrink-0 place-items-center rounded-lg" :class="kindTone[it.kind]">
        @foreach ($kindIcons as $k => $icon)<x-icon :name="$icon" class="size-3.5" x-show="it.kind === '{{ $k }}'" />@endforeach
    </span>
    <span class="min-w-0 flex-1">
        <a :href="it.href" class="block truncate text-sm font-medium hover:underline" x-text="it.title"></a>
        <span class="block truncate text-xs text-muted-foreground" x-show="it.sub || (it.people && it.people.length)"
            x-text="[it.sub, it.people && it.people.length ? it.people.join(', ') : null].filter(Boolean).join(' · ')"></span>
    </span>
    <span class="flex shrink-0 flex-col items-end gap-1">
        <template x-if="it.badge"><span class="badge whitespace-nowrap" :class="'badge-' + it.badge.color" x-text="it.badge.text"></span></template>
        <span x-show="it.amount" class="whitespace-nowrap text-xs font-medium tabular-nums" x-text="it.amount"></span>
        <template x-if="it.link"><a :href="it.link" target="_blank" rel="noopener" class="link text-xs">{{ t('home.agenda.join') }}</a></template>
    </span>
</li>
