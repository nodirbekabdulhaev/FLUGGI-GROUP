{{-- Kanban идей роликов: перетаскивание (компьютер) или «Переместить в» (телефон); правила этапов проверяет сервер --}}
@php
    use App\Services\Smm\SmmIdeaService;
    $cards = $items->mapWithKeys(fn ($i) => [$i->id => [
        'status' => $i->status, 'planned' => $i->planned_publish_date?->format('Y-m-d'), 'url' => $i->publish_url,
        'videographer' => $i->videographer_id,
    ]]);
    $rejected = $items->where('status', 'REJECTED')->sortByDesc('rejected_at');
@endphp
<div class="grid gap-4" x-data="smmKanban(@js(['cards' => $cards, 'now' => \App\Support\Format::toLocalInput(now()), 'ok' => t('smm.moved')]))">
    <p class="text-xs text-muted-foreground">{{ t('smm.kanbanHint') }}@if ($hidden) · {{ t('smm.hiddenOld', ['count' => $hidden]) }}@endif</p>
    <div class="-mx-4 flex gap-3 overflow-x-auto px-4 pb-2 sm:mx-0 sm:px-0" x-hscroll>
        @foreach (SmmIdeaService::KANBAN as $status)
            @php($col = $items->where('status', $status))
            <div class="flex w-72 shrink-0 flex-col rounded-lg bg-muted/60 xl:w-auto xl:min-w-0 xl:flex-1">
                <div class="flex items-center gap-2 border-b border-border/60 p-3">
                    <h3 class="flex-1 truncate text-sm font-semibold" title="{{ t('smm.statusHint.'.$status) }}">{{ t('smm.status.'.$status) }}</h3>
                    <span class="rounded-full bg-surface px-2 text-xs">{{ $col->count() }}</span>
                </div>
                <div class="grid min-h-32 flex-1 content-start gap-2 p-2 transition-colors" data-status="{{ $status }}" data-testid="column-{{ $status }}"
                     :class="over === '{{ $status }}' && 'bg-accent-soft'"
                     @dragover.prevent="over = '{{ $status }}'" @dragleave="over = null" @drop.prevent="drop($event, '{{ $status }}', null)">
                    @foreach ($col as $idea)
                        @include('smm.partials.card', ['idea' => $idea, 'status' => $status])
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>

    @if ($rejected->isNotEmpty())
        <section class="grid gap-2">
            <h3 class="text-sm font-semibold">{{ t('smm.status.REJECTED') }} <span class="text-muted-foreground">({{ $rejected->count() }})</span></h3>
            <ul class="divide-y rounded-lg border bg-surface">
                @foreach ($rejected as $idea)
                    <li class="flex flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2 text-sm">
                        <x-badge color="red">{{ t('smm.status.REJECTED') }}</x-badge>
                        <a href="/smm/{{ $idea->id }}" class="min-w-0 flex-1 truncate hover:underline">{{ SmmIdeaService::code($idea) }} · {{ $idea->title }}</a>
                        <span class="w-full truncate text-xs text-muted-foreground sm:w-auto">{{ $idea->reject_reason }}</span>
                        <span class="text-xs text-muted-foreground">{{ $idea->assignee?->full_name }}</span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    {{-- Дата при переносе в «Запланировано» / «Опубликовано» --}}
    <div x-show="ask" x-cloak class="fixed inset-0 z-50 flex items-end justify-center sm:items-center sm:p-4" role="dialog" aria-modal="true" x-on:keydown.escape.window="ask = null">
        <div class="absolute inset-0 bg-black/40" x-on:click="ask = null"></div>
        <form class="relative grid w-full max-w-md gap-4 rounded-t-xl bg-surface p-4 shadow-xl sm:rounded-xl sm:p-5" x-on:submit.prevent="confirm()">
            <h2 class="font-semibold" x-text="ask && ask.status === 'SHOOTING' ? @js(t('smm.askShooting')) : (ask && ask.status === 'SCHEDULED' ? @js(t('smm.askScheduled')) : @js(t('smm.askPublished')))"></h2>
            <template x-if="ask && ask.status === 'SHOOTING'">
                <div>
                    <label class="label" for="smm-ask-videographer">{{ t('smm.fields.videographer') }}<span class="text-danger"> *</span></label>
                    <select id="smm-ask-videographer" class="input" x-model="ask.videographer" required>
                        <option value="">{{ t('crm.common.select') }}</option>
                        @foreach ($videographers as $v)<option value="{{ $v->id }}">{{ $v->full_name }}</option>@endforeach
                    </select>
                </div>
            </template>
            <template x-if="ask && ask.status === 'SCHEDULED'">
                <div>
                    <label class="label" for="smm-ask-date">{{ t('smm.fields.plannedDate') }}<span class="text-danger"> *</span></label>
                    <input id="smm-ask-date" type="date" class="input" x-model="ask.date" required>
                </div>
            </template>
            <template x-if="ask && ask.status === 'PUBLISHED'">
                <div class="grid gap-4">
                    <div>
                        <label class="label" for="smm-ask-published">{{ t('smm.fields.publishedAt') }}</label>
                        <input id="smm-ask-published" type="datetime-local" class="input" x-model="ask.publishedAt">
                    </div>
                    <div>
                        <label class="label" for="smm-ask-url">{{ t('smm.fields.publishUrl') }}</label>
                        <input id="smm-ask-url" type="url" class="input" x-model="ask.url" placeholder="https://instagram.com/…">
                    </div>
                </div>
            </template>
            <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <button type="button" class="btn btn-outline" x-on:click="ask = null">{{ t('common.cancel') }}</button>
                <button class="btn btn-primary">{{ t('smm.moveSubmit') }}</button>
            </div>
        </form>
    </div>
</div>

@push('scripts')
<script>
    // Kanban SMM: перенос карточки; для «Запланировано» без даты и «Опубликовано» — спросить дату
    window.smmKanban = (cfg) => ({
        over: null,
        dragging: null,
        ask: null,
        drag(e, id) { this.dragging = id; e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', id); },
        drop(e, status, beforeId) {
            this.over = null;
            const id = this.dragging || e.dataTransfer.getData('text/plain');
            this.dragging = null;
            if (!id || id === beforeId) return;
            this.request(id, status, beforeId);
        },
        request(id, status, beforeId = null) {
            const card = cfg.cards[id];
            if (!card) return;
            if (status !== card.status && ((status === 'SCHEDULED' && !card.planned) || status === 'PUBLISHED' || (status === 'SHOOTING' && !card.videographer))) {
                this.ask = { id, status, beforeId, date: card.planned || '', publishedAt: cfg.now, url: card.url || '', videographer: '' };
                return;
            }
            this.send(id, { status, before_id: beforeId });
        },
        confirm() {
            const a = this.ask;
            const body = { status: a.status, before_id: a.beforeId };
            if (a.status === 'SCHEDULED') body.planned_publish_date = a.date;
            if (a.status === 'SHOOTING') body.videographer_id = a.videographer || null;
            if (a.status === 'PUBLISHED') { body.published_at = a.publishedAt || null; if (a.url) body.publish_url = a.url; }
            this.ask = null;
            this.send(a.id, body);
        },
        async send(id, body) {
            try {
                const res = await window.api(`/smm/${id}/move`, { method: 'POST', body });
                window.toast(res?.message || cfg.ok);
                window.location.reload();
            } catch (err) {
                window.toast(err.message, 'error');
            }
        },
    });
</script>
@endpush
