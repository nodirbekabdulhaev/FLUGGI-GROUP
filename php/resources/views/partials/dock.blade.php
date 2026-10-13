@php
    $dockAccess = access();
    $dockOpen = \App\Models\Todo::where('owner_id', $dockAccess->id())->where('status', 'OPEN');
    $dockCount = (clone $dockOpen)->count();
    $dockOverdue = (clone $dockOpen)->whereNotNull('due_at')->where('due_at', '<', now())->count();
    $dockClients = \App\Http\Controllers\Todos\TodoController::clients();
    $dockChatUnread = \App\Services\Chat\ChatService::unreadTotal($dockAccess->id());
@endphp
{{-- «Список дел» внизу экрана (только мои личные дела) и чат сотрудников. Обновление — опросом. --}}
<div x-data="todoDock({{ $dockCount }}, {{ $dockOverdue }})">
    <section x-show="open" x-cloak aria-label="{{ t('todos.dockTitle') }}"
             class="fixed inset-x-0 bottom-12 z-30 flex max-h-[75dvh] flex-col rounded-t-2xl border bg-surface shadow-xl sm:inset-x-auto sm:left-4 sm:w-[440px] sm:rounded-2xl lg:left-[17rem]">
        <header class="grid gap-3 border-b-2 border-success/40 px-5 pb-3 pt-4">
            <div class="flex items-center gap-2">
                <x-icon name="list" class="size-5 text-muted-foreground" />
                <h2 class="text-lg">{{ t('todos.dockTitle') }}</h2>
                <span class="text-xs text-muted-foreground" x-text="summary()"></span>
                <button type="button" class="ml-auto rounded p-1 text-muted-foreground hover:bg-muted" aria-label="{{ t('todos.minimize') }}" @click="open = false"><x-icon name="chevron-down" class="size-5" /></button>
            </div>
            <div class="flex flex-wrap gap-2">
                <select class="input h-8 w-40 text-xs" x-model="sort" aria-label="{{ t('todos.sort') }}">
                    <option value="due">{{ t('todos.sortDue') }}</option>
                    <option value="priority">{{ t('todos.sortPriority') }}</option>
                </select>
                <button type="button" class="btn btn-primary btn-sm ml-auto" @click="edit(null)"><x-icon name="plus" /> {{ t('todos.add') }}</button>
            </div>
        </header>
        <div class="overflow-y-auto px-5">
            <p x-show="loading && !items.length" class="py-6 text-sm text-muted-foreground">{{ t('todos.loading') }}</p>
            <p x-show="!loading && !items.length" class="py-8 text-center text-sm text-muted-foreground">{{ t('todos.empty') }}</p>
            <ul class="divide-y">
                <template x-for="t in sorted()" :key="t.id">
                    <li class="grid gap-1 py-3" x-data="{ more: false }">
                        <div class="flex items-start gap-2">
                            <span class="mt-1.5 size-2.5 shrink-0 rounded-full" :class="dot(t.priority)"></span>
                            <button type="button" class="min-w-0 flex-1 break-words text-left text-sm font-semibold leading-snug hover:underline" @click="edit(t)" x-text="t.title"></button>
                            <button type="button" class="flex size-5 shrink-0 items-center justify-center rounded border border-input text-transparent hover:border-success hover:text-success"
                                    :aria-label="completeLabel.replace('{title}', t.title)" title="{{ t('todos.markDone') }}" @click="complete(t)"><x-icon name="check" class="size-3.5" /></button>
                        </div>
                        <div class="flex items-start gap-1 pl-4 text-xs text-muted-foreground">
                            <button type="button" class="mt-0.5" @click="more = !more" :aria-expanded="more" aria-label="{{ t('todos.expand') }}">
                                <x-icon name="chevron-right" class="size-3.5 transition-transform" ::class="more && 'rotate-90'" />
                            </button>
                            <div class="min-w-0 flex-1">
                                <p x-show="t.record" class="flex flex-wrap items-center gap-1 text-sm text-foreground">
                                    <x-icon name="briefcase" class="size-3.5 text-accent" />
                                    <template x-if="t.record"><a :href="t.record.url" class="font-medium hover:underline" x-text="t.record.label"></a></template>
                                </p>
                                <p class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1">
                                    <span x-text="t.kindLabel"></span>
                                    <span :class="t.overdue && 'font-medium text-danger'" class="flex items-center gap-1"><x-icon name="calendar" class="size-3.5" /><span x-text="(t.overdue ? overdueLabel + ' · ' : '') + t.dueLabel"></span></span>
                                    <span x-show="t.recurring" class="flex items-center gap-1"><x-icon name="refresh" class="size-3.5" />{{ t('todos.recurring') }}</span>
                                </p>
                                <div x-show="more" class="mt-2 grid gap-1">
                                    <p x-show="t.description" class="whitespace-pre-wrap break-words text-foreground/80" x-text="t.description"></p>
                                    <p x-show="t.creator.id !== t.owner.id" x-text="fromLabel.replace('{name}', t.creator.name)"></p>
                                </div>
                            </div>
                        </div>
                    </li>
                </template>
            </ul>
        </div>
        <footer class="border-t px-5 py-2 text-right"><a href="{{ route('todos') }}" class="text-xs text-muted-foreground hover:underline">{{ t('todos.openPage') }}</a></footer>
    </section>
    <button type="button" @click="toggle()" :aria-expanded="open"
            class="fixed inset-x-0 bottom-0 z-30 flex h-12 items-center gap-3 border-t bg-surface px-5 text-sm text-muted-foreground hover:text-foreground lg:left-64">
        <x-icon name="list" class="size-5 text-success" />
        <span class="font-medium">{{ t('todos.dockTitle') }}</span>
        <span x-show="count" class="rounded-full px-2 py-0.5 text-xs font-medium" :class="overdue ? 'bg-danger-soft text-danger' : 'bg-muted text-foreground'" x-text="count"></span>
    </button>

    {{-- Новое дело / изменить дело --}}
    <div x-show="dialog" x-cloak class="fixed inset-0 z-50 flex items-end justify-center sm:items-center sm:p-4" role="dialog" aria-modal="true" @keydown.escape.window="dialog = false">
        <div class="absolute inset-0 bg-black/40" @click="dialog = false"></div>
        <form class="relative max-h-[92dvh] w-full max-w-lg overflow-y-auto rounded-t-xl bg-surface shadow-xl sm:rounded-xl" @submit.prevent="save()">
            <header class="sticky top-0 flex items-center justify-between border-b bg-surface px-4 py-3">
                <h2 class="font-semibold" x-text="form.id ? @js(t('todos.edit')) : @js(t('todos.new'))"></h2>
                <button type="button" class="btn btn-ghost btn-icon" @click="dialog = false" aria-label="{{ t('common.close') }}"><x-icon name="x" /></button>
            </header>
            <div class="grid gap-4 p-4">
                <div><label class="label" for="dock-title">{{ t('todos.title') }}</label>
                    <input id="dock-title" class="input" x-model="form.title" required maxlength="300"><p class="error" x-show="errors.title" x-text="errors.title"></p></div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div><label class="label" for="dock-kind">{{ t('todos.kind') }}</label>
                        <select id="dock-kind" class="input" x-model="form.kind">@foreach (\App\Services\Todos\TodoService::KINDS as $k)<option value="{{ $k }}">{{ t('todos.kinds.'.$k) }}</option>@endforeach</select></div>
                    <div><label class="label" for="dock-priority">{{ t('todos.priority') }}</label>
                        <select id="dock-priority" class="input" x-model="form.priority">@foreach (\App\Services\Todos\TodoService::PRIORITIES as $p)<option value="{{ $p }}">{{ t('crm.priority.'.$p) }}</option>@endforeach</select></div>
                    <div><label class="label" for="dock-due">{{ t('todos.due') }}</label>
                        <input id="dock-due" type="datetime-local" class="input" x-model="form.due_at"><p class="error" x-show="errors.due_at" x-text="errors.due_at"></p></div>
                    <div x-show="owners.length > 1"><label class="label" for="dock-owner">{{ t('todos.owner') }}</label>
                        <select id="dock-owner" class="input" x-model="form.owner_id">
                            <template x-for="o in owners" :key="o.id"><option :value="o.id" x-text="o.name" :selected="o.id === form.owner_id"></option></template>
                        </select>
                        <p class="error" x-show="errors.owner_id" x-text="errors.owner_id"></p></div>
                    @if ($dockClients)
                        <div><label class="label" for="dock-client">{{ t('todos.client') }}</label>
                            <select id="dock-client" class="input" x-model="form.client_id"><option value="">{{ t('todos.noClient') }}</option>@foreach ($dockClients as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select></div>
                    @endif
                </div>
                <div><label class="label" for="dock-desc">{{ t('todos.description') }}</label>
                    <textarea id="dock-desc" class="input" rows="3" x-model="form.description" maxlength="4000"></textarea></div>
                <div class="flex justify-end gap-2">
                    <button type="button" class="btn btn-outline" @click="dialog = false">{{ t('todos.cancel') }}</button>
                    <button type="submit" class="btn btn-primary" :disabled="busy">{{ t('todos.save') }}</button>
                </div>
            </div>
        </form>
    </div>
</div>

{{-- Чат сотрудников (всплывающее окно). Ссылка из Telegram открывает переписку: ?chat=… --}}
<div x-data="chatWidget({{ $dockChatUnread }})">
    <section x-show="open" x-cloak aria-label="{{ t('chat.title') }}"
             class="fixed inset-x-0 bottom-12 z-40 flex h-[min(560px,80dvh)] flex-col overflow-hidden rounded-t-2xl border bg-surface shadow-xl sm:inset-x-auto sm:bottom-[7.5rem] sm:right-4 sm:w-[380px] sm:rounded-2xl">
        <template x-if="active">
            <div class="flex min-h-0 flex-1 flex-col">
                <header class="flex items-center gap-2 border-b px-3 py-2">
                    <button type="button" class="rounded p-1 hover:bg-muted" @click="back()" aria-label="{{ t('chat.back') }}"><x-icon name="arrow-left" /></button>
                    <span class="flex size-9 shrink-0 items-center justify-center rounded-full bg-accent-soft text-xs font-semibold text-accent" x-text="initials(active.peer)"></span>
                    <p class="min-w-0 flex-1 truncate text-sm font-medium" x-text="active.peer"></p>
                </header>
                <div class="flex-1 overflow-y-auto px-3 py-3" x-ref="messages">
                    <p x-show="loaded && !messages.length" class="py-8 text-center text-sm text-muted-foreground">{{ t('chat.firstMessage') }}</p>
                    <ul class="grid gap-2">
                        <template x-for="m in messages" :key="m.id">
                            <li class="flex" :class="m.mine ? 'justify-end' : 'justify-start'">
                                <div class="max-w-[80%] rounded-2xl px-3 py-2 text-sm" :class="m.mine ? 'rounded-br-sm bg-accent text-white' : 'rounded-bl-sm bg-muted'">
                                    <p class="whitespace-pre-wrap break-words" x-text="m.body"></p>
                                    <p class="mt-0.5 text-right text-[10px]" :class="m.mine ? 'text-white/70' : 'text-muted-foreground'" x-text="m.time"></p>
                                </div>
                            </li>
                        </template>
                    </ul>
                </div>
                <form class="flex gap-2 border-t p-2" @submit.prevent="send()">
                    <input class="input" x-model="text" maxlength="4000" autocomplete="off" aria-label="{{ t('chat.message') }}" placeholder="{{ t('chat.placeholder') }}">
                    <button type="submit" class="btn btn-accent btn-icon shrink-0" :disabled="sending || !text.trim()" aria-label="{{ t('chat.send') }}"><x-icon name="send" /></button>
                </form>
            </div>
        </template>
        <template x-if="!active">
            <div class="flex min-h-0 flex-1 flex-col">
                <header class="flex items-center gap-2 border-b px-4 py-3">
                    <button type="button" x-show="picking" class="rounded p-1 hover:bg-muted" @click="picking = false" aria-label="{{ t('chat.back') }}"><x-icon name="arrow-left" /></button>
                    <h2 class="flex-1 font-medium" x-text="picking ? @js(t('chat.newChat')) : @js(t('chat.title'))"></h2>
                    <button type="button" x-show="!picking" class="rounded p-1 hover:bg-muted" @click="pick()" aria-label="{{ t('chat.newChat') }}" title="{{ t('chat.newChat') }}"><x-icon name="plus" /></button>
                    <button type="button" class="rounded p-1 hover:bg-muted" @click="open = false" aria-label="{{ t('chat.close') }}"><x-icon name="x" /></button>
                </header>
                <div x-show="picking" class="flex min-h-0 flex-1 flex-col">
                    <div class="p-3"><input class="input" x-model="q" aria-label="{{ t('chat.searchPeople') }}" placeholder="{{ t('chat.searchPeople') }}"></div>
                    <ul class="flex-1 overflow-y-auto">
                        <template x-for="c in contacts.filter(c => c.name.toLowerCase().includes(q.trim().toLowerCase()))" :key="c.id">
                            <li><button type="button" class="flex w-full items-center gap-3 px-4 py-2 text-left hover:bg-muted" @click="startWith(c)">
                                <span class="flex size-9 shrink-0 items-center justify-center rounded-full bg-accent-soft text-xs font-semibold text-accent" x-text="initials(c.name)"></span>
                                <span class="min-w-0"><span class="block truncate text-sm" x-text="c.name"></span><span class="block truncate text-xs text-muted-foreground" x-text="[c.role, c.team].filter(Boolean).join(' · ')"></span></span>
                            </button></li>
                        </template>
                    </ul>
                </div>
                <ul x-show="!picking" class="flex-1 overflow-y-auto">
                    <li x-show="loaded && !list.length" class="px-6 py-10 text-center text-sm text-muted-foreground">{{ t('chat.empty') }}</li>
                    <template x-for="c in list" :key="c.id">
                        <li><button type="button" class="flex w-full items-center gap-3 px-4 py-3 text-left hover:bg-muted" @click="openConversation(c.id, c.peer.name)">
                            <span class="flex size-9 shrink-0 items-center justify-center rounded-full bg-accent-soft text-xs font-semibold text-accent" x-text="initials(c.peer.name)"></span>
                            <span class="min-w-0 flex-1">
                                <span class="flex items-baseline justify-between gap-2"><span class="truncate text-sm font-medium" x-text="c.peer.name"></span>
                                    <span class="shrink-0 text-[11px] text-muted-foreground" x-text="c.lastMessage ? shortTime(c.lastMessage.createdAt) : ''"></span></span>
                                <span class="flex items-center justify-between gap-2">
                                    <span class="truncate text-xs text-muted-foreground" x-text="c.lastMessage ? (c.lastMessage.mine ? youLabel + ': ' : '') + c.lastMessage.body : ''"></span>
                                    <span x-show="c.unread" class="shrink-0 rounded-full bg-accent px-1.5 text-[11px] font-medium text-white" x-text="c.unread"></span>
                                </span>
                            </span>
                        </button></li>
                    </template>
                </ul>
            </div>
        </template>
    </section>
    <button type="button" @click="toggle()" :class="open && 'max-sm:hidden'" :aria-label="count ? unreadLabel.replace('{count}', count) : @js(t('chat.open'))"
            class="fixed bottom-14 right-4 z-40 flex size-12 items-center justify-center rounded-full bg-accent text-white shadow-lg hover:bg-accent/90">
        <span x-show="!open"><x-icon name="message" class="size-5" /></span>
        <span x-show="open" x-cloak><x-icon name="x" class="size-5" /></span>
        <span x-show="count && !open" x-cloak class="absolute -right-1 -top-1 min-w-5 rounded-full bg-danger px-1 text-center text-[11px] font-semibold leading-5" x-text="count > 99 ? '99+' : count"></span>
    </button>
</div>

@once
<script>
document.addEventListener('alpine:init', () => {
    const PRIORITY = { URGENT: 0, HIGH: 1, MEDIUM: 2, LOW: 3 };
    const DOT = { URGENT: 'bg-danger', HIGH: 'bg-warning', MEDIUM: 'bg-success', LOW: 'bg-muted-foreground/40' };
    const fieldErrors = (e) => Object.fromEntries(Object.entries(e.errors || {}).map(([k, v]) => [k, Array.isArray(v) ? v[0] : v]));

    Alpine.data('todoDock', (count, overdue) => ({
        open: false, loading: false, items: [], owners: [], count, overdue, sort: 'due', dialog: false, busy: false, errors: {}, form: {},
        me: @js($dockAccess->id()),
        completeLabel: @js(t('todos.complete', ['title' => '{title}'])),
        overdueLabel: @js(t('todos.overdue')),
        fromLabel: @js(t('todos.from').': {name}'),
        countLabels: @js(collect(range(0, 200))->map(fn ($n) => t('todos.count', ['count' => $n]))->all()),
        overdueCountLabel: @js(t('todos.overdueCount', ['count' => '{count}'])),
        init() { setInterval(() => { if (!document.hidden) this.load(); }, 60000); },
        summary() {
            return (this.countLabels[this.count] ?? this.count)
                + (this.overdue ? ' · ' + this.overdueCountLabel.replace('{count}', this.overdue) : '');
        },
        toggle() { this.open = !this.open; if (this.open) this.load(); },
        async load() {
            this.loading = true;
            try {
                const data = await window.api('{{ route('todos.dock') }}');
                this.items = data.todos;
                this.owners = data.owners ?? [];
                this.count = this.items.length;
                this.overdue = this.items.filter((t) => t.overdue).length;
            } catch (e) {} finally { this.loading = false; }
        },
        sorted() {
            const due = (t) => t.dueAt ?? '9';
            return [...this.items].sort((a, b) => this.sort === 'priority'
                ? PRIORITY[a.priority] - PRIORITY[b.priority] || due(a).localeCompare(due(b))
                : due(a).localeCompare(due(b)) || PRIORITY[a.priority] - PRIORITY[b.priority]);
        },
        dot(p) { return DOT[p]; },
        edit(t) {
            this.errors = {};
            this.form = t ? { id: t.id, title: t.title, description: t.description ?? '', kind: t.kind, priority: t.priority, due_at: t.dueLocal, owner_id: t.owner.id, client_id: t.client?.id ?? '' }
                : { id: null, title: '', description: '', kind: 'TASK', priority: 'MEDIUM', due_at: '', owner_id: this.me, client_id: '' };
            this.dialog = true;
        },
        async save() {
            this.busy = true; this.errors = {};
            const body = { ...this.form, description: this.form.description || null, due_at: this.form.due_at || null, client_id: this.form.client_id || null };
            delete body.id;
            try {
                await window.api(this.form.id ? '/todos/' + this.form.id : '{{ route('todos.store') }}', { method: this.form.id ? 'PATCH' : 'POST', body });
                window.toast(this.form.id ? @js(t('todos.saved')) : @js(t('todos.created')));
                this.dialog = false;
                await this.load();
            } catch (e) { this.errors = fieldErrors(e); window.toast(e.message, 'error'); } finally { this.busy = false; }
        },
        async complete(t) {
            try { await window.api('/todos/' + t.id + '/complete', { method: 'POST' }); window.toast(@js(t('todos.doneToast'))); await this.load(); }
            catch (e) { window.toast(e.message, 'error'); }
        },
    }));

    Alpine.data('chatWidget', (count) => ({
        open: false, count, list: [], loaded: false, active: null, messages: [], text: '', sending: false, picking: false, contacts: [], q: '',
        youLabel: @js(t('chat.you')), unreadLabel: @js(t('chat.openUnread', ['count' => '{count}'])),
        timer: null,
        init() {
            setInterval(async () => {
                if (document.hidden) return;
                try { this.count = (await window.api('{{ route('chats.unread') }}')).count; } catch (e) {}
                if (this.open && !this.active) this.loadList();
            }, 20000);
            // Ссылка из Telegram: /dashboard?chat=<id>
            const url = new URL(window.location.href);
            const id = url.searchParams.get('chat');
            if (id) {
                url.searchParams.delete('chat');
                history.replaceState(null, '', url.pathname + url.search);
                this.open = true;
                this.loadList().then(() => this.openConversation(id, this.list.find((c) => c.id === id)?.peer.name ?? ''));
            }
        },
        initials(name) { return (name || '').split(/\s+/).slice(0, 2).map((p) => p[0] || '').join('').toUpperCase(); },
        shortTime(iso) {
            const d = new Date(iso);
            return d.toDateString() === new Date().toDateString()
                ? d.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit', timeZone: 'Asia/Tashkent' })
                : d.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', timeZone: 'Asia/Tashkent' });
        },
        toggle() { this.open = !this.open; if (this.open && !this.active) this.loadList(); },
        async loadList() { try { this.list = await window.api('{{ route('chats') }}'); this.loaded = true; } catch (e) {} },
        async pick() {
            this.picking = true;
            if (!this.contacts.length) { try { this.contacts = await window.api('{{ route('chats.contacts') }}'); } catch (e) { window.toast(e.message, 'error'); } }
        },
        async startWith(c) {
            try {
                const r = await window.api('{{ route('chats.direct') }}', { method: 'POST', body: { userId: c.id } });
                this.picking = false; this.q = '';
                this.openConversation(r.id, c.name);
            } catch (e) { window.toast(e.message, 'error'); }
        },
        async openConversation(id, peer) {
            this.active = { id, peer }; this.messages = []; this.loaded = false;
            await this.loadMessages();
            clearInterval(this.timer);
            this.timer = setInterval(() => { if (!document.hidden && this.active) this.loadMessages(); }, 8000);
        },
        async loadMessages() {
            if (!this.active) return;
            const id = this.active.id;
            try {
                const rows = await window.api('/chats/' + id + '/messages');
                if (!this.active || this.active.id !== id) return;
                const grew = rows.length !== this.messages.length;
                this.messages = rows; this.loaded = true;
                if (grew) {
                    this.$nextTick(() => { if (this.$refs.messages) this.$refs.messages.scrollTop = this.$refs.messages.scrollHeight; });
                    await window.api('/chats/' + id + '/read', { method: 'POST' });
                    this.count = (await window.api('{{ route('chats.unread') }}')).count;
                }
            } catch (e) { if (e.status === 404) this.back(); }
        },
        back() { clearInterval(this.timer); this.active = null; this.loadList(); },
        async send() {
            const body = this.text.trim();
            if (!body || !this.active) return;
            this.sending = true;
            try {
                const m = await window.api('/chats/' + this.active.id + '/messages', { method: 'POST', body: { body } });
                if (!this.messages.some((x) => x.id === m.id)) this.messages.push(m);
                this.text = '';
                this.$nextTick(() => { if (this.$refs.messages) this.$refs.messages.scrollTop = this.$refs.messages.scrollHeight; });
            } catch (e) { window.toast(e.message, 'error'); } finally { this.sending = false; }
        },
    }));
});
</script>
@endonce
