@php
 $user = auth()->user();
 $menu = \App\Support\Menu::visible();
 $path = trim(request()->path(), '/');
 // active item = the item whose prefix is the longest match of the current path
 $activeUrl = null; $best = -1;
 foreach ($menu as $items) foreach ($items as $it) {
    $m = $it[4];
    if (($path === $m || str_starts_with($path, $m.'/')) && strlen($m) > $best) { $best = strlen($m); $activeUrl = $it[2]; }
 }
 $unread = \App\Models\Notification::where('channel', 'web')->where('user_id', $user->id)->whereNull('read_at')->count();
 $can = fn($p) => \Illuminate\Support\Facades\Gate::allows($p);
@endphp
<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#4f46e5">
    <link rel="manifest" href="{{ asset('manifest.webmanifest') }}">
    <link rel="icon" href="{{ asset('icons/icon.svg') }}" type="image/svg+xml">
    <link rel="apple-touch-icon" href="{{ asset('icons/icon.svg') }}">
    <title>@yield('title', 'Dashboard') — {{ $currentOrg->name ?? 'FLUGGI EDU' }}</title>
    <link rel="stylesheet" href="{{ asset_v('css/app.css') }}">
    <script defer src="{{ asset('vendor/alpine.min.js') }}"></script>
    @stack('head')
</head>
<body x-data="{ menu: false, quick: false, search: false }" @keydown.window.ctrl.k.prevent="search = true; $nextTick(() => $refs.q && $refs.q.focus())" @keydown.escape.window="menu = quick = search = false">

{{-- Desktop sidebar / mobile drawer --}}
<div x-cloak x-show="menu" @click="menu = false" class="fixed inset-0 z-40 bg-slate-900/50 lg:hidden"></div>
<aside :class="menu ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'"
       class="fixed inset-y-0 left-0 z-50 flex w-64 flex-col border-r border-slate-200 bg-white transition-transform dark:border-slate-800 dark:bg-slate-900">
    <div class="flex h-14 shrink-0 items-center gap-2 px-4">
        @if($currentOrg?->logo_path)<img src="{{ route('files.show', ['logo', 0]) }}" alt="" class="h-8 w-8 rounded-lg object-cover">@else<div class="flex h-8 w-8 items-center justify-center rounded-lg bg-brand-600 text-sm font-bold text-white">F</div>@endif
        <div class="min-w-0"><div class="truncate text-sm font-semibold leading-tight">{{ $currentOrg->name ?? 'FLUGGI EDU' }}</div><div class="text-[10px] uppercase tracking-wider text-slate-400">EDU ERP</div></div>
        <button class="ml-auto lg:hidden" @click="menu = false"><x-icon name="x" /></button>
    </div>
    <nav class="flex-1 space-y-4 overflow-y-auto px-3 pb-6">
        @foreach($menu as $group => $items)
            <div>
                @if($group)<div class="mb-1 px-3 text-[11px] font-semibold uppercase tracking-wider text-slate-400">{{ __($group) }}</div>@endif
                @foreach($items as $it)
                    <a href="{{ $it[2] }}" class="nav-link {{ $it[2] === $activeUrl ? 'nav-link-active' : '' }}"><x-icon :name="$it[1]" class="h-[18px] w-[18px]" /> {{ __($it[0]) }}</a>
                @endforeach
            </div>
        @endforeach
    </nav>
</aside>

<div class="lg:pl-64">
    {{-- Top bar --}}
    <header class="sticky top-0 z-30 flex h-14 items-center gap-2 border-b border-slate-200 bg-white/90 px-3 backdrop-blur dark:border-slate-800 dark:bg-slate-900/90 sm:px-5">
        <button class="btn-ghost -ml-1 px-2 lg:hidden" @click="menu = true" aria-label="Menu"><x-icon name="menu" /></button>
        <h1 class="min-w-0 flex-1 truncate text-base font-semibold lg:hidden">@yield('title', 'Dashboard')</h1>
        <button @click="search = true; $nextTick(() => $refs.q && $refs.q.focus())" class="hidden w-72 items-center gap-2 rounded-lg border border-slate-200 bg-slate-50 px-3 py-1.5 text-left text-sm text-slate-400 hover:border-slate-300 dark:border-slate-700 dark:bg-slate-800 lg:flex">
            <x-icon name="search" class="h-4 w-4" /> {{ __('Поиск: имя, телефон, ID…') }} <kbd class="ml-auto rounded border px-1 text-[10px]">Ctrl K</kbd>
        </button>
        <div class="ml-auto hidden lg:block"></div>

        @if(! $user->branch_id && isset($tenantBranches) && $tenantBranches->count() > 1)
        <form method="POST" action="{{ route('branch.switch') }}" class="hidden sm:block">@csrf
            <select name="branch_id" onchange="this.form.submit()" class="input !w-auto !py-1.5 text-xs" title="{{ __('Филиал') }}">
                <option value="">{{ __('Все филиалы') }}</option>
                @foreach($tenantBranches as $b)<option value="{{ $b->id }}" @selected($currentBranchId == $b->id)>{{ $b->name }}</option>@endforeach
            </select>
        </form>
        @elseif($user->branch_id)
            <x-badge color="indigo" class="hidden sm:inline-flex">{{ $user->branch?->name }}</x-badge>
        @endif

        <button class="btn-ghost px-2 lg:hidden" @click="search = true; $nextTick(() => $refs.q && $refs.q.focus())" aria-label="Search"><x-icon name="search" /></button>

        {{-- Notification center --}}
        <div class="relative" x-data="bell()">
            <button class="btn-ghost relative px-2" @click="toggle()" aria-label="Notifications">
                <x-icon name="bell" />
                <span x-show="unread > 0" x-cloak x-text="unread > 9 ? '9+' : unread" class="absolute -right-0.5 -top-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-rose-600 px-1 text-[10px] font-semibold text-white"></span>
            </button>
            <div x-cloak x-show="open" @click.outside="open = false" class="absolute right-0 mt-2 w-80 max-w-[92vw] overflow-hidden rounded-xl border border-slate-200 bg-white shadow-xl dark:border-slate-700 dark:bg-slate-900">
                <div class="flex items-center justify-between border-b px-4 py-2.5 text-sm font-semibold dark:border-slate-700">{{ __('Уведомления') }}
                    <button class="text-xs font-normal text-brand-600" @click="readAll()">{{ __('Прочитать все') }}</button></div>
                <div class="max-h-96 divide-y overflow-y-auto dark:divide-slate-800">
                    <template x-for="n in items" :key="n.id">
                        <a :href="n.url || '#'" class="block px-4 py-2.5 text-sm hover:bg-slate-50 dark:hover:bg-slate-800" :class="n.read ? 'opacity-60' : ''">
                            <div class="font-medium" x-text="n.title"></div>
                            <div class="line-clamp-2 whitespace-pre-line text-xs text-slate-500" x-text="n.body"></div>
                            <div class="mt-0.5 text-[10px] text-slate-400" x-text="n.time"></div>
                        </a>
                    </template>
                    <div x-show="!items.length" class="px-4 py-6 text-center text-sm text-slate-400">{{ __('Нет уведомлений') }}</div>
                </div>
            </div>
        </div>

        {{-- User menu --}}
        <div class="relative" x-data="{ o: false }">
            <button @click="o = !o" class="flex h-8 w-8 items-center justify-center rounded-full bg-brand-100 text-xs font-semibold text-brand-700">{{ mb_strtoupper(mb_substr($user->name, 0, 1)) }}</button>
            <div x-cloak x-show="o" @click.outside="o = false" class="absolute right-0 mt-2 w-56 rounded-xl border border-slate-200 bg-white py-1 text-sm shadow-xl dark:border-slate-700 dark:bg-slate-900">
                <div class="border-b px-4 py-2 dark:border-slate-700"><div class="font-medium">{{ $user->name }}</div><div class="text-xs text-slate-500">{{ $user->roles->pluck('name')->join(', ') }}</div></div>
                <a href="{{ route('profile') }}" class="block px-4 py-2 hover:bg-slate-50 dark:hover:bg-slate-800">{{ __('Профиль и пароль') }}</a>
                <a href="{{ route('locale', 'ru') }}" class="block px-4 py-2 hover:bg-slate-50 dark:hover:bg-slate-800">Русский @if(app()->getLocale()==='ru')✓@endif</a>
                <a href="{{ route('locale', 'uz') }}" class="block px-4 py-2 hover:bg-slate-50 dark:hover:bg-slate-800">O‘zbekcha @if(app()->getLocale()==='uz')✓@endif</a>
                <form method="POST" action="{{ route('logout') }}">@csrf<button class="block w-full px-4 py-2 text-left text-rose-600 hover:bg-slate-50 dark:hover:bg-slate-800">{{ __('Выйти') }}</button></form>
            </div>
        </div>
    </header>

    <main class="mx-auto max-w-[1500px] px-3 pb-28 pt-4 sm:px-5 lg:pb-10">
        @if(session('ok'))<div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-2.5 text-sm text-emerald-800" role="status">{{ session('ok') }}</div>@endif
        @if(session('warn'))<div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-2.5 text-sm text-amber-800">{{ session('warn') }}</div>@endif
        @if($errors->any() && ! $errors->has('conflict'))
            <div class="mb-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-2.5 text-sm text-rose-800" role="alert">
                <ul class="list-inside list-disc">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
            </div>
        @endif
        @if($errors->has('conflict'))
            <div class="mb-4 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900" role="alert">
                <div class="mb-1 font-semibold">⚠ {{ __('Конфликт расписания') }}</div>
                <ul class="list-inside list-disc">@foreach($errors->get('conflict') as $e)<li>{{ $e }}</li>@endforeach</ul>
            </div>
        @endif

        <div class="mb-4 hidden items-center justify-between gap-3 lg:flex">
            <h1 class="text-xl font-semibold">@yield('title', 'Dashboard')</h1>
            <div class="flex items-center gap-2">@yield('actions')</div>
        </div>
        <div class="mb-3 flex items-center justify-end gap-2 lg:hidden">@yield('actions')</div>

        @yield('content')
    </main>
</div>

{{-- Mobile bottom navigation --}}
<nav class="fixed inset-x-0 bottom-0 z-30 border-t border-slate-200 bg-white pb-[env(safe-area-inset-bottom)] dark:border-slate-800 dark:bg-slate-900 lg:hidden">
    <div class="mx-auto grid max-w-md grid-cols-5 items-end text-[11px]">
        @php
          $bn = fn($href, $label, $icon, $match) => '<a href="'.$href.'" class="flex flex-col items-center gap-0.5 py-2 '.(str_starts_with($path, $match) ? 'text-brand-600' : 'text-slate-500').'">'.view('components.icon', ['name' => $icon])->render().'<span>'.e(__($label)).'</span></a>';
        @endphp
        {!! $can('dashboard.view') ? $bn('/dashboard', 'Главная', 'home', 'dashboard') : $bn(\App\Support\Menu::home(), 'Главная', 'home', '~') !!}
        {!! $can('students.view') ? $bn('/students', 'Ученики', 'academic', 'students') : ($can('leads.view') ? $bn('/leads', 'Лиды', 'phone', 'leads') : '<span></span>') !!}
        <button @click="quick = true" class="-mt-4 flex flex-col items-center gap-0.5 pb-2 text-brand-600" aria-label="Quick actions">
            <span class="flex h-12 w-12 items-center justify-center rounded-full bg-brand-600 text-white shadow-lg"><x-icon name="plus" class="h-6 w-6" /></span>
        </button>
        {!! $can('schedule.view') || $can('attendance.mark') ? $bn('/lessons', 'Занятия', 'clock', 'lessons') : '<span></span>' !!}
        <button @click="menu = true" class="flex flex-col items-center gap-0.5 py-2 text-slate-500"><x-icon name="menu" /><span>{{ __('Меню') }}</span></button>
    </div>
</nav>

{{-- Quick actions sheet (mobile) --}}
<div x-cloak x-show="quick" class="fixed inset-0 z-50 lg:hidden">
    <div class="absolute inset-0 bg-slate-900/50" @click="quick = false"></div>
    <div class="absolute inset-x-0 bottom-0 rounded-t-2xl bg-white p-4 pb-8 dark:bg-slate-900">
        <div class="mb-3 text-sm font-semibold">{{ __('Быстрые действия') }}</div>
        <div class="grid grid-cols-2 gap-2">
            @if($can('leads.manage'))<a href="{{ route('leads.create') }}" class="card flex items-center gap-2 p-3 text-sm"><x-icon name="phone" class="text-brand-600" /> {{ __('Добавить лид') }}</a>@endif
            <button @click="quick = false; search = true; $nextTick(() => $refs.q && $refs.q.focus())" class="card flex items-center gap-2 p-3 text-left text-sm"><x-icon name="search" class="text-brand-600" /> {{ __('Найти ученика') }}</button>
            @if($can('payments.create'))<a href="{{ route('payments.create') }}" class="card flex items-center gap-2 p-3 text-sm"><x-icon name="cash" class="text-brand-600" /> {{ __('Принять оплату') }}</a>@endif
            @if($can('attendance.mark'))<a href="{{ route('lessons.index', ['date' => today()->toDateString()]) }}" class="card flex items-center gap-2 p-3 text-sm"><x-icon name="check" class="text-brand-600" /> {{ __('Отметить посещаемость') }}</a>@endif
            @if($can('schedule.view'))<a href="{{ route('schedule.index') }}" class="card flex items-center gap-2 p-3 text-sm"><x-icon name="calendar" class="text-brand-600" /> {{ __('Расписание') }}</a>@endif
        </div>
    </div>
</div>

{{-- Global search overlay --}}
<div x-cloak x-show="search" class="fixed inset-0 z-[60]" x-data="globalSearch()">
    <div class="absolute inset-0 bg-slate-900/50" @click="search = false"></div>
    <div class="relative mx-auto mt-0 w-full max-w-xl p-3 sm:mt-16">
        <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-2xl dark:border-slate-700 dark:bg-slate-900">
            <div class="flex items-center gap-2 border-b px-3 dark:border-slate-700">
                <x-icon name="search" class="h-4 w-4 text-slate-400" />
                <input x-ref="q" x-model="q" @input.debounce.250ms="run()" type="search" autocomplete="off" placeholder="{{ __('Имя, телефон, ID, Telegram, группа…') }}" class="w-full border-0 bg-transparent py-3 text-sm focus:ring-0">
                <button class="text-xs text-slate-400" @click="search = false">Esc</button>
            </div>
            <div class="max-h-[65vh] overflow-y-auto">
                <template x-for="(rows, grp) in results" :key="grp">
                    <div>
                        <div class="bg-slate-50 px-3 py-1 text-[11px] font-semibold uppercase tracking-wide text-slate-400 dark:bg-slate-800" x-text="labels[grp] || grp"></div>
                        <template x-for="r in rows" :key="r.url">
                            <a :href="r.url" class="flex items-center justify-between px-3 py-2 text-sm hover:bg-slate-50 dark:hover:bg-slate-800">
                                <span class="font-medium" x-text="r.title"></span><span class="text-xs text-slate-500" x-text="r.sub"></span>
                            </a>
                        </template>
                    </div>
                </template>
                <div x-show="q.length > 1 && !loading && !Object.keys(results).length" class="px-4 py-6 text-center text-sm text-slate-400">{{ __('Ничего не найдено') }}</div>
            </div>
        </div>
    </div>
</div>

<script>
function bell() {
    return {
        open: false, unread: {{ $unread }}, items: [], loaded: false,
        async toggle() {
            this.open = !this.open;
            if (this.open) { const r = await fetch('{{ route('notifications.bell') }}', { headers: { Accept: 'application/json' } }); this.items = (await r.json()).items; }
        },
        async readAll() {
            await fetch('{{ route('notifications.read') }}', { method: 'POST', headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, Accept: 'application/json' } });
            this.unread = 0; this.items = this.items.map(i => ({ ...i, read: true }));
        },
    };
}
function globalSearch() {
    return {
        q: '', results: {}, loading: false,
        labels: { students: @json(__('Ученики')), parents: @json(__('Родители')), leads: @json(__('Лиды')), groups: @json(__('Группы')) },
        async run() {
            if (this.q.trim().length < 2) { this.results = {}; return; }
            this.loading = true;
            try { const r = await fetch('{{ route('search.json') }}?q=' + encodeURIComponent(this.q), { headers: { Accept: 'application/json' } }); this.results = (await r.json()).results; }
            finally { this.loading = false; }
        },
    };
}
</script>
@stack('scripts')
</body>
</html>
