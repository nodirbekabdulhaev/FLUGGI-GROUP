{{-- Плитка показателя; href — ссылка на раздел, danger — выделить красным, icon — значок (x-icon), tone — sky|indigo|pink|emerald|amber --}}
@php($tones = ['sky' => 'bg-sky-100 text-sky-600', 'indigo' => 'bg-indigo-100 text-indigo-600', 'pink' => 'bg-pink-100 text-pink-600', 'emerald' => 'bg-emerald-100 text-emerald-600', 'amber' => 'bg-amber-100 text-amber-700'])
@if (! empty($href))<a href="{{ $href }}" class="block min-w-0">@endif
<div @class(['card card-body grid h-full min-w-0 content-start gap-1 transition', 'hover:-translate-y-0.5 hover:shadow-md' => ! empty($href)])>
    <div class="flex items-start justify-between gap-2">
        <p class="min-w-0 text-sm text-muted-foreground">{{ $label }}</p>
        @if (! empty($icon))<span class="grid size-9 shrink-0 place-items-center rounded-xl ring-1 ring-white/80 {{ $tones[$tone ?? 'sky'] ?? $tones['sky'] }}"><x-icon :name="$icon" class="size-[18px]" /></span>@endif
    </div>
    <p @class(['break-words text-lg font-semibold tracking-tight tabular-nums sm:text-2xl', 'text-danger' => ! empty($danger)])>{{ $value }}</p>
    @if (! empty($hint))<p class="text-xs text-muted-foreground">{{ $hint }}</p>@endif
</div>
@if (! empty($href))</a>@endif
