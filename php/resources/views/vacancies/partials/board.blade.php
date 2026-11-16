{{-- Kanban откликов: перетаскивание карточек между статусами (компьютер) или выбор статуса в карточке (телефон) --}}
@php
    $cols = ['NEW' => '#3b82f6', 'VIEWED' => '#94a3b8', 'INTERVIEW' => '#f59e0b', 'HIRED' => '#16a34a', 'REJECTED' => '#ef4444'];
    $tone = ['excellent' => 'badge-green', 'strong' => 'badge-blue', 'medium' => 'badge-yellow', 'weak' => 'badge-red'];
@endphp
<script>
    window.vacBoard = () => ({
        over: null, dragId: null,
        drag(e, id) { this.dragId = id; e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', id); },
        drop(status) { const id = this.dragId; this.over = null; this.dragId = null; if (id) this.move(id, status); },
        async move(id, status) {
            const card = this.$root.querySelector('[data-card="' + id + '"]'), body = this.$root.querySelector('[data-status="' + status + '"]');
            if (!card || !body || card.closest('[data-status]') === body) return;
            const from = card.closest('[data-status]');
            body.prepend(card);
            const sel = card.querySelector('select'); if (sel) sel.value = status;
            this.count();
            try {
                const r = await fetch('/vacancies/' + id, { method: 'PATCH', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content }, body: JSON.stringify({ status }) });
                if (!r.ok) throw new Error(r.status);
            } catch (e) { from.prepend(card); if (sel) sel.value = from.dataset.status; this.count(); alert(@js(t('vacancies.moveFailed'))); }
        },
        count() { this.$root.querySelectorAll('[data-status]').forEach(b => { const c = b.closest('section').querySelector('[data-count]'); if (c) c.textContent = b.querySelectorAll('[data-card]').length; }); },
    });
</script>
<div class="board" data-board x-data="vacBoard()">
    <p class="mb-2 text-xs text-muted-foreground">{{ t('vacancies.kanbanHint') }}</p>
    <div class="board-scroll" x-hscroll>
      <div class="flex w-max items-start gap-3">
        @foreach ($cols as $status => $color)
            @php $col = $board[$status] ?? collect(); @endphp
            <section class="kanban-col" style="--col: {{ $color }}">
                <header class="kanban-head">
                    <span class="kanban-count" data-count>{{ $col->count() }}</span>
                    <h3 class="kanban-title">{{ t('vacancies.statuses.'.$status) }}</h3>
                </header>
                <div class="kanban-body min-h-32" data-status="{{ $status }}" :class="over === '{{ $status }}' && 'is-over'"
                     @dragover.prevent="over = '{{ $status }}'" @dragleave="over = null" @drop.prevent="drop('{{ $status }}')">
                    @foreach ($col as $a)
                        <article class="kanban-card {{ $canManage ? 'cursor-grab' : '' }}" data-card="{{ $a->id }}" @if ($canManage) draggable="true" @dragstart="drag($event, '{{ $a->id }}')" @endif>
                            <div class="flex items-start gap-3">
                                @if (! empty($a->profile['photo_url']))
                                    <img src="{{ $a->profile['photo_url'] }}" alt="" referrerpolicy="no-referrer" loading="lazy" draggable="false" class="size-10 shrink-0 rounded-full object-cover">
                                @else
                                    <x-avatar :name="$a->name" class="!size-10 !text-sm" />
                                @endif
                                <div class="min-w-0 flex-1">
                                    <a href="/vacancies/{{ $a->id }}" class="block truncate font-semibold leading-snug text-foreground hover:underline">{{ $a->name }}</a>
                                    <p class="truncate text-xs text-muted-foreground">{{ $a->vacancy_name ?: t('vacancies.noVacancy') }}</p>
                                </div>
                                @if ($a->score !== null)<span class="badge {{ $tone[$a->score_level] ?? 'badge-gray' }} tabular-nums" title="{{ t('vacancies.levels.'.$a->score_level) }}">{{ $a->score }}</span>@endif
                            </div>
                            <div class="mt-2 flex items-center justify-between gap-2 text-xs text-muted-foreground">
                                <span>{{ d($a->created_at) }}</span>
                                <a class="link inline-flex items-center gap-1" href="tel:{{ $a->phone }}"><x-icon name="phone" class="size-3.5" />{{ $a->phone }}</a>
                            </div>
                            @if (! empty($a->score_parts['flags']))<p class="mt-1 flex items-center gap-1 text-xs text-danger"><x-icon name="alert" class="size-3.5" />{{ t('vacancies.redFlags') }}</p>@endif
                            @if ($canManage)
                                <select class="input mt-2 h-8 rounded-full py-0 text-xs xl:hidden" aria-label="{{ t('vacancies.status') }}" @change="move('{{ $a->id }}', $event.target.value)">
                                    @foreach ($cols as $to => $_)<option value="{{ $to }}" @selected($to === $status)>{{ t('vacancies.statuses.'.$to) }}</option>@endforeach
                                </select>
                            @endif
                        </article>
                    @endforeach
                </div>
            </section>
        @endforeach
      </div>
    </div>
</div>
