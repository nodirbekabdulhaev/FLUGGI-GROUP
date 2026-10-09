@php use App\Support\Navigation; @endphp
<nav class="space-y-0.5 text-sm" aria-label="{{ t('nav.mainMenu') }}">
    @foreach ($nav as $section)
        @if (isset($section['children']))
            @php($open = collect($section['children'])->contains(fn ($c) => Navigation::isActive($c['href'])))
            <div x-data="{ open: @js($open) }">
                <button type="button" @click="open = !open" class="flex w-full items-center gap-3 rounded-md px-3 py-2 font-medium text-muted-foreground hover:bg-muted hover:text-foreground">
                    <x-icon :name="$section['icon']" /> <span class="flex-1 text-left">{{ t('nav.'.$section['key']) }}</span>
                    <x-icon name="chevron-down" class="size-3.5 transition-transform" ::class="open && 'rotate-180'" />
                </button>
                <div x-show="open" x-collapse class="ml-5 space-y-0.5 border-l pl-3">
                    @foreach ($section['children'] as $item)
                        <a href="{{ $item['href'] }}" @class(['block rounded-md px-3 py-1.5', 'bg-muted font-medium text-foreground' => Navigation::isActive($item['href']), 'text-muted-foreground hover:bg-muted hover:text-foreground' => ! Navigation::isActive($item['href'])])>{{ t('nav.'.$item['key']) }}</a>
                    @endforeach
                </div>
            </div>
        @else
            <a href="{{ $section['href'] }}" @class(['flex items-center gap-3 rounded-md px-3 py-2 font-medium', 'bg-muted text-foreground' => Navigation::isActive($section['href']), 'text-muted-foreground hover:bg-muted hover:text-foreground' => ! Navigation::isActive($section['href'])])>
                <x-icon :name="$section['icon']" /> {{ t('nav.'.$section['key']) }}
            </a>
        @endif
    @endforeach
</nav>
