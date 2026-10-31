{{-- Этап воронки: точка цвета этапа + название --}}
<span class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-full border px-2 py-0.5 text-xs font-medium">
    <span class="size-2 rounded-full" style="background-color: {{ $stage->color }}" aria-hidden="true"></span>{{ $stage->label() }}
</span>
