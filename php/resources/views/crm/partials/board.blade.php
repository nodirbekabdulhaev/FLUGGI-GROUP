{{--
  Kanban воронки (Воронка, Лиды, Сделки). $board — PipelineService::board(); $cfg — адреса и права (см. pipelineBoard
  в resources/js/kanban.js); $parts — [entity => подпись группы] (подпись null — без заголовка группы).
  Колонка окрашена в светлый оттенок цвета этапа (Настройки → Справочники).
--}}
@php $parts ??= ['LEAD' => t('pipeline.leadsPart'), 'DEAL' => t('pipeline.dealsPart')]; @endphp
<div x-data="pipelineBoard(@js($board), @js($cfg + [
        'convertFirst' => t('pipeline.convertFirst'),
        'wrongBoard' => t('pipeline.wrongBoard'),
        'more' => t('pipeline.more', ['count' => '__N__']),
        'addTo' => t('ui.kanban.addTo', ['name' => '__NAME__']),
    ]))" data-testid="kanban">
    @if ($totals ?? false)
        <div class="mb-4 grid grid-cols-2 gap-3 sm:max-w-md">
            <div class="card p-4"><p class="text-xs text-muted-foreground">{{ t('pipeline.total') }}</p><p class="text-lg font-semibold tabular-nums" x-text="board.pipeline"></p></div>
            <div class="card p-4"><p class="text-xs text-muted-foreground">{{ t('pipeline.weighted') }}</p><p class="text-lg font-semibold tabular-nums" x-text="board.weighted"></p></div>
        </div>
    @endif
    {{-- Доска прокручивается внутри своего блока — страница на телефоне не прокручивается вбок --}}
    <div class="-mx-4 overflow-x-auto px-4 pb-2 sm:mx-0 sm:px-0" x-hscroll>
        <div class="flex gap-3">
            @foreach ($parts as $entity => $part)
                <div x-show="board.columns.some((c) => c.entity === '{{ $entity }}')">
                    @if ($part)<p class="mb-2 px-1 text-xs font-medium uppercase tracking-wide text-muted-foreground">{{ $part }}</p>@endif
                    <div class="flex items-start gap-3">
                        <template x-for="col in board.columns.filter((c) => c.entity === '{{ $entity }}')" :key="col.code">
                            <section class="kanban-col" :style="'--col:' + col.color" :data-stage="col.code">
                                <header class="kanban-head">
                                    <span class="kanban-count" x-text="col.count"></span>
                                    <h3 class="kanban-title" x-text="col.name" :title="col.name"></h3>
                                    <button type="button" x-show="canCreate(col)" class="kanban-add-icon" x-on:click="create(col)"
                                            :aria-label="t.addTo.replace('__NAME__', col.name)" :title="t.addTo.replace('__NAME__', col.name)"><x-icon name="plus" class="size-4" /></button>
                                </header>
                                <p class="kanban-sub" x-text="col.total + ' · ' + col.probability + '%'"></p>
                                <div class="kanban-body" :class="over === col.code && 'is-over'"
                                     x-on:dragover.prevent="over = col.code" x-on:dragleave="over = null" x-on:drop.prevent="drop(col)">
                                    <p x-show="col.items.length === 0" class="kanban-empty">{{ t('pipeline.empty') }}</p>
                                    <template x-for="card in col.items" :key="card.id">
                                        <article class="kanban-card" :draggable="canMove(card)" :class="canMove(card) && 'cursor-grab'"
                                                 x-on:dragstart="drag = { card, from: col }" x-on:dragend="drag = null; over = null">
                                            <div class="flex items-start justify-between gap-2">
                                                <a :href="card.href" class="min-w-0 break-words font-semibold leading-snug text-foreground hover:underline" x-text="card.title"></a>
                                                <span x-show="card.scoreLevel" class="badge shrink-0" :class="{ LOW: 'badge-gray', MEDIUM: 'badge-blue', HIGH: 'badge-yellow', HOT: 'badge-red' }[card.scoreLevel]" x-text="card.scoreLabel"></span>
                                            </div>
                                            <p x-show="card.subtitle" class="mt-0.5 truncate text-xs text-muted-foreground" x-text="card.subtitle"></p>
                                            <div class="mt-3 flex items-center gap-2 text-xs">
                                                <span class="flex size-6 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-sky-soft to-periwinkle-soft text-[10px] font-semibold text-periwinkle-strong ring-2 ring-white" x-text="card.initials || '·'" aria-hidden="true"></span>
                                                <span class="min-w-0 flex-1 truncate text-muted-foreground" x-text="card.owner"></span>
                                                <span class="shrink-0 font-semibold tabular-nums" x-text="card.amount"></span>
                                            </div>
                                            <div x-show="card.nextContactAt || card.priority" class="mt-2 flex items-center gap-2 text-xs text-muted-foreground">
                                                <span x-show="card.nextContactAt" class="flex min-w-0 items-center gap-1"><x-icon name="clock" class="size-3.5 shrink-0" /><span class="truncate" x-text="card.nextContactAt"></span></span>
                                                <span x-show="card.priority" class="badge ml-auto" :class="priorityClass(card.priority)" x-text="card.priorityLabel"></span>
                                            </div>
                                            {{-- На телефоне перетаскивание недоступно — перенос через список этапов --}}
                                            <select x-show="canMove(card)" class="input mt-2 h-8 rounded-full text-xs lg:hidden" aria-label="{{ t('pipeline.moveTo') }}"
                                                    x-on:change="move(card, col, board.columns.find((c) => c.code === $event.target.value)); $event.target.value = ''">
                                                <option value="">{{ t('pipeline.moveTo') }}…</option>
                                                <template x-for="c in board.columns.filter((c) => c.entity === col.entity && c.code !== col.code && c.code !== 'PAID')" :key="c.code">
                                                    <option :value="c.code" x-text="c.name"></option>
                                                </template>
                                            </select>
                                        </article>
                                    </template>
                                    <template x-if="col.count > col.items.length">
                                        <a x-show="moreHref(col)" :href="moreHref(col)" class="kanban-empty block py-1 hover:underline" x-text="t.more.replace('__N__', col.count - col.items.length)"></a>
                                    </template>
                                    <p x-show="col.count > col.items.length && !moreHref(col)" class="kanban-empty py-1" x-text="t.more.replace('__N__', col.count - col.items.length)"></p>
                                </div>
                                <button type="button" x-show="canCreate(col)" class="kanban-add" x-on:click="create(col)"><x-icon name="plus" class="size-4" /> {{ t('ui.kanban.add') }}</button>
                            </section>
                        </template>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</div>
