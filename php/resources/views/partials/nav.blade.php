@php use App\Support\Navigation; @endphp
{{-- Меню: светлая «стеклянная» панель, пункт — значок и подпись, активный — белая «таблетка» --}}
<nav class="space-y-0.5 text-sm" aria-label="{{ t('nav.mainMenu') }}">
    @foreach ($nav as $section)
        @if (isset($section['children']))
            @php($open = collect($section['children'])->contains(fn ($c) => Navigation::isActive($c['href'])))
            <div x-data="{ open: @js($open) }">
                <button type="button" @click="open = !open" @class(['flex w-full items-center gap-3 rounded-xl px-3 py-2 font-medium transition-colors hover:bg-white/70 hover:text-foreground', 'text-foreground' => $open, 'text-muted-foreground' => ! $open])>
                    <x-icon :name="$section['icon']" :class="$open ? 'size-[18px] text-primary' : 'size-[18px]'" /> <span class="flex-1 text-left">{{ t('nav.'.$section['key']) }}</span>
                    <x-icon name="chevron-down" class="size-3.5 transition-transform" ::class="open && 'rotate-180'" />
                </button>
                <div x-show="open" x-collapse class="ml-[1.3rem] space-y-0.5 border-l border-primary/15 py-0.5 pl-3">
                    @foreach ($section['children'] as $item)
                        @php($count = isset($item['count']) ? (int) call_user_func($item['count']) : 0)
                        @php($on = Navigation::isActive($item['href']))
                        <a href="{{ $item['href'] }}" @if ($on) aria-current="page" @endif @class(['nav-item flex items-center justify-between gap-2 rounded-xl px-3 py-1.5 transition-colors', 'bg-white font-medium text-primary-strong shadow-soft' => $on, 'text-muted-foreground hover:bg-white/70 hover:text-foreground' => ! $on])><span>{{ t('nav.'.$item['key']) }}</span>@if ($count > 0)<span class="badge">{{ $count }}</span>@endif</a>
                    @endforeach
                </div>
            </div>
        @else
            @php($on = Navigation::isActive($section['href']))
            <a href="{{ $section['href'] }}" @if ($on) aria-current="page" @endif @class(['flex items-center gap-3 rounded-xl px-3 py-2 font-medium transition-colors', 'bg-white text-primary-strong shadow-soft' => $on, 'text-muted-foreground hover:bg-white/70 hover:text-foreground' => ! $on])>
                <x-icon :name="$section['icon']" :class="$on ? 'size-[18px] text-primary' : 'size-[18px]'" /> {{ t('nav.'.$section['key']) }}
            </a>
        @endif
    @endforeach
</nav>
