{{-- Шапка: глобальный поиск (⌘K / Ctrl+K) и колокольчик уведомлений (опрос раз в 30 с) --}}
<div x-data="globalSearch" @keydown.window.prevent.ctrl.k="openSearch()" @keydown.window.prevent.meta.k="openSearch()" class="flex items-center">
    <button type="button" class="flex h-9 items-center gap-2 rounded-md border bg-surface px-2.5 text-sm text-muted-foreground hover:bg-muted sm:w-56" @click="openSearch()" aria-label="{{ t('search.open') }}">
        <x-icon name="search" class="size-4 shrink-0" />
        <span class="hidden flex-1 text-left sm:inline">{{ t('search.placeholder') }}</span>
        <kbd class="hidden rounded border px-1 text-[10px] sm:inline">⌘K</kbd>
    </button>
    <div x-show="open" x-cloak class="fixed inset-0 z-50 flex items-start justify-center p-0 sm:p-4 sm:pt-[12vh]" role="dialog" aria-modal="true" @keydown.escape.window="open = false">
        <div class="absolute inset-0 bg-black/40" @click="open = false"></div>
        <div class="relative w-full max-w-xl overflow-hidden bg-surface shadow-xl sm:rounded-xl">
            <header class="flex items-center justify-between border-b px-4 py-3">
                <h2 class="font-semibold">{{ t('search.title') }}</h2>
                <button type="button" class="btn btn-ghost btn-icon" @click="open = false" aria-label="{{ t('common.close') }}"><x-icon name="x" /></button>
            </header>
            <div class="p-3">
                <input x-ref="input" type="search" class="input" role="combobox" aria-controls="global-search-results" placeholder="{{ t('search.hint') }}"
                       x-model="q" @input.debounce.250ms="run()" @keydown.arrow-down.prevent="move(1)" @keydown.arrow-up.prevent="move(-1)" @keydown.enter.prevent="go(hits[active])">
            </div>
            <div class="max-h-[60dvh] min-h-24 overflow-y-auto px-3 pb-3">
                <p x-show="q.trim().length < 2" class="py-6 text-center text-sm text-muted-foreground">{{ t('search.minLength') }}</p>
                <p x-show="q.trim().length >= 2 && loading" class="py-6 text-center text-sm text-muted-foreground">{{ t('search.searching') }}</p>
                <p x-show="error" class="py-6 text-center text-sm text-danger" x-text="error"></p>
                <p x-show="q.trim().length >= 2 && !loading && !error && hits.length === 0" class="py-6 text-center text-sm text-muted-foreground" x-text="nothing.replace('{q}', q.trim())"></p>
                <ul id="global-search-results" role="listbox" class="grid gap-0.5">
                    <template x-for="(h, i) in hits" :key="h.kind + h.id">
                        <li role="option" :aria-selected="i === active">
                            <p x-show="i === 0 || hits[i - 1].kind !== h.kind" class="px-2 pb-1 pt-2 text-xs font-medium text-muted-foreground" x-text="kinds[h.kind]"></p>
                            <a :href="h.link" @mouseenter="active = i" :class="i === active && 'bg-muted'" class="flex items-center gap-3 rounded-md px-2 py-2">
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-sm" x-text="h.title"></span>
                                    <span class="block truncate text-xs text-muted-foreground" x-text="h.subtitle"></span>
                                </span>
                            </a>
                        </li>
                    </template>
                </ul>
            </div>
        </div>
    </div>
</div>
<a href="{{ route('notifications') }}" x-data="unreadBell({{ \App\Services\Notifications\NotificationService::unread(access()->id()) }})" class="btn btn-ghost btn-icon relative" :aria-label="label()" aria-label="{{ t('notifications.title') }}">
    <x-icon name="bell" class="size-5" />
    <span x-show="count > 0" x-cloak class="absolute right-1 top-1 flex min-w-4 items-center justify-center rounded-full bg-danger px-1 text-[10px] font-semibold leading-4 text-white" x-text="count > 99 ? '99+' : count"></span>
</a>
@once
<script>
document.addEventListener('alpine:init', () => {
    const ORDER = @js(\App\Services\Analytics\SearchService::ORDER);
    Alpine.data('globalSearch', () => ({
        open: false, q: '', hits: [], active: 0, loading: false, error: '', seq: 0,
        kinds: @js(collect(\App\Services\Analytics\SearchService::ORDER)->mapWithKeys(fn ($k) => [$k => t('search.kinds.'.$k)])),
        nothing: @js(t('search.nothing', ['q' => '{q}'])),
        openSearch() { this.open = true; this.$nextTick(() => this.$refs.input.focus()); },
        async run() {
            const q = this.q.trim();
            this.error = ''; this.active = 0;
            if (q.length < 2) { this.hits = []; return; }
            const id = ++this.seq;
            this.loading = true;
            try {
                const hits = await window.api('{{ route('search') }}?q=' + encodeURIComponent(q));
                if (id === this.seq) this.hits = hits.sort((a, b) => ORDER.indexOf(a.kind) - ORDER.indexOf(b.kind));
            } catch (e) { if (id === this.seq) this.error = e.message; }
            finally { if (id === this.seq) this.loading = false; }
        },
        move(d) { this.active = Math.max(0, Math.min(this.hits.length - 1, this.active + d)); },
        go(h) { if (h) window.location.href = h.link; },
    }));
    Alpine.data('unreadBell', (initial) => ({
        count: initial,
        init() {
            setInterval(async () => {
                if (document.hidden) return;
                try { this.count = (await window.api('{{ route('notifications.unread') }}')).count; } catch (e) {}
            }, 30000);
        },
        label() { return @js(t('notifications.title')) + (this.count ? ' (' + this.count + ')' : ''); },
    }));
});
</script>
@endonce
