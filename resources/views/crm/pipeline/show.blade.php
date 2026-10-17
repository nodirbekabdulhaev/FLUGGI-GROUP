@extends('layouts.app')
@section('title', t('pipeline.title'))
@section('content')
    @include('crm.partials.errors')
    <x-page-header :title="t('pipeline.title')" :subtitle="t('pipeline.subtitle')">
        <form method="GET" class="grid w-full grid-cols-2 gap-2 sm:flex sm:w-auto">
            <select name="service_id" class="input sm:w-44" aria-label="{{ t('crm.fields.service') }}" onchange="this.form.submit()">
                <option value="">{{ t('crm.fields.service') }}: {{ t('crm.common.all') }}</option>
                @foreach ($services as $id => $name)<option value="{{ $id }}" @selected(($f['service_id'] ?? '') === $id)>{{ $name }}</option>@endforeach
            </select>
            <select name="source_id" class="input sm:w-44" aria-label="{{ t('crm.fields.source') }}" onchange="this.form.submit()">
                <option value="">{{ t('crm.fields.source') }}: {{ t('crm.common.all') }}</option>
                @foreach ($sources as $id => $name)<option value="{{ $id }}" @selected(($f['source_id'] ?? '') === $id)>{{ $name }}</option>@endforeach
            </select>
            @if ($owners)
                <select name="owner_id" class="input col-span-2 sm:w-48" aria-label="{{ t('crm.fields.owner') }}" onchange="this.form.submit()">
                    <option value="">{{ t('crm.fields.owner') }}: {{ t('crm.common.all') }}</option>
                    @foreach ($owners as $id => $name)<option value="{{ $id }}" @selected(($f['owner_id'] ?? '') === $id)>{{ $name }}</option>@endforeach
                </select>
            @endif
        </form>
    </x-page-header>

    <div x-data="pipelineBoard(@js($board), @js([
            'data' => route('crm.pipeline.data', array_filter($f)),
            'lead' => url('/sales/leads/__ID__/stage'),
            'deal' => url('/sales/deals/__ID__/stage'),
            'canLead' => can('lead.update'),
            'canDeal' => can('deal.change_stage'),
            'convertFirst' => t('pipeline.convertFirst'),
            'wrongBoard' => t('pipeline.wrongBoard'),
            'more' => t('pipeline.more', ['count' => '__N__']),
        ]))">
        <div class="mb-4 grid grid-cols-2 gap-3 sm:max-w-md">
            <div class="card p-4"><p class="text-xs text-muted-foreground">{{ t('pipeline.total') }}</p><p class="text-lg font-semibold" x-text="board.pipeline"></p></div>
            <div class="card p-4"><p class="text-xs text-muted-foreground">{{ t('pipeline.weighted') }}</p><p class="text-lg font-semibold" x-text="board.weighted"></p></div>
        </div>
        {{-- Доска прокручивается внутри своего блока — страница на телефоне не прокручивается вбок --}}
        <div class="overflow-x-auto pb-4">
            <div class="flex gap-3">
                @foreach (['LEAD' => t('pipeline.leadsPart'), 'DEAL' => t('pipeline.dealsPart')] as $entity => $part)
                    <div x-show="board.columns.some((c) => c.entity === '{{ $entity }}')">
                        <p class="mb-2 text-xs font-medium uppercase tracking-wide text-muted-foreground">{{ $part }}</p>
                        <div class="flex gap-3">
                            <template x-for="col in board.columns.filter((c) => c.entity === '{{ $entity }}')" :key="col.code">
                                <div class="flex w-72 shrink-0 flex-col rounded-lg bg-muted/60">
                                    <div class="border-b p-3">
                                        <div class="flex items-center gap-2">
                                            <span class="size-2 rounded-full" :style="'background-color:' + col.color" aria-hidden="true"></span>
                                            <h3 class="flex-1 truncate text-sm font-semibold" x-text="col.name"></h3>
                                            <span class="rounded-full bg-surface px-2 text-xs" x-text="col.count"></span>
                                        </div>
                                        <p class="mt-1 text-xs text-muted-foreground" x-text="col.total + ' · ' + col.probability + '%'"></p>
                                    </div>
                                    <div class="grid min-h-24 flex-1 content-start gap-2 p-2 transition-colors" :class="over === col.code && 'bg-accent-soft'"
                                         x-on:dragover.prevent="over = col.code" x-on:dragleave="over = null" x-on:drop.prevent="drop(col)">
                                        <p x-show="col.items.length === 0" class="py-6 text-center text-xs text-muted-foreground">{{ t('pipeline.empty') }}</p>
                                        <template x-for="card in col.items" :key="card.id">
                                            <div class="rounded-md border bg-surface p-3 text-sm shadow-sm" :draggable="canMove(card)" :class="canMove(card) && 'cursor-grab'"
                                                 x-on:dragstart="drag = { card, from: col }" x-on:dragend="drag = null; over = null">
                                                <div class="mb-1 flex items-start justify-between gap-2">
                                                    <a :href="card.href" class="font-medium leading-snug hover:underline" x-text="card.title"></a>
                                                    <span x-show="card.scoreLevel" class="badge" :class="{ LOW: 'badge-gray', MEDIUM: 'badge-blue', HIGH: 'badge-yellow', HOT: 'badge-red' }[card.scoreLevel]" x-text="card.scoreLabel"></span>
                                                </div>
                                                <p x-show="card.subtitle" class="truncate text-xs text-muted-foreground" x-text="card.subtitle"></p>
                                                <div class="mt-2 flex items-center justify-between gap-2 text-xs">
                                                    <span class="font-medium" x-text="card.amount"></span>
                                                    <span class="truncate text-muted-foreground" x-text="card.owner"></span>
                                                </div>
                                                <p x-show="card.nextContactAt" class="mt-1 text-xs text-muted-foreground" x-text="card.nextContactAt"></p>
                                                {{-- На телефоне перетаскивание недоступно — перенос через список этапов --}}
                                                <select x-show="canMove(card)" class="input mt-2 h-8 text-xs lg:hidden" aria-label="{{ t('pipeline.moveTo') }}"
                                                        x-on:change="move(card, col, board.columns.find((c) => c.code === $event.target.value)); $event.target.value = ''">
                                                    <option value="">{{ t('pipeline.moveTo') }}…</option>
                                                    <template x-for="c in board.columns.filter((c) => c.entity === col.entity && c.code !== col.code && c.code !== 'PAID')" :key="c.code">
                                                        <option :value="c.code" x-text="c.name"></option>
                                                    </template>
                                                </select>
                                            </div>
                                        </template>
                                        <p x-show="col.count > col.items.length" class="text-center text-xs text-muted-foreground" x-text="t.more.replace('__N__', col.count - col.items.length)"></p>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    @push('scripts')
        <script>
            // Kanban воронки: перетаскивание (компьютер) или список этапов (телефон) → POST …/stage, при ошибке — откат
            window.pipelineBoard = (initial, t) => ({
                board: initial, drag: null, over: null, t,
                canMove(card) { return card.kind === 'lead' ? t.canLead : t.canDeal; },
                drop(to) {
                    this.over = null;
                    if (!this.drag) return;
                    const { card, from } = this.drag;
                    this.drag = null;
                    this.move(card, from, to);
                },
                async move(card, from, to) {
                    if (!to || from.code === to.code || !this.canMove(card)) return;
                    if (card.kind === 'lead' && to.entity === 'DEAL') return window.toast(t.convertFirst);
                    if (card.kind === 'deal' && to.entity === 'LEAD') return window.toast(t.wrongBoard, 'error');
                    const prev = JSON.parse(JSON.stringify(this.board));
                    from.items = from.items.filter((i) => i.id !== card.id); from.count--;
                    to.items.unshift(card); to.count++;
                    try {
                        await window.api(t[card.kind].replace('__ID__', card.id), { method: 'POST', body: { stage: to.code } });
                        window.toast(card.title + ' → ' + to.name);
                    } catch (e) {
                        this.board = prev;
                        window.toast(e.message, 'error');
                    }
                    try { this.board = await window.api(t.data); } catch (e) {}
                },
            });
        </script>
    @endpush
@endsection
