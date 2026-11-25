{{-- «Мой день»: встречи, задачи, дела, follow-up, сделки, SMM, оплаты — День | Неделя | Месяц | Год (resources/js/agenda.js) --}}
@php
    $kindTone = ['meeting' => 'bg-sky-100 text-sky-600', 'task' => 'bg-indigo-100 text-indigo-600', 'todo' => 'bg-amber-100 text-amber-700', 'followUp' => 'bg-emerald-100 text-emerald-700',
        'deal' => 'bg-violet-100 text-violet-600', 'smm' => 'bg-pink-100 text-pink-600', 'payment' => 'bg-teal-100 text-teal-700'];
    $labels = collect(['meeting', 'task', 'todo', 'followUp', 'deal', 'smm', 'payment'])->mapWithKeys(fn ($k) => [$k => t('home.agenda.kinds.'.$k)])->all();
    $monthNames = array_map(fn ($m) => t('analytics.months.'.$m), range(1, 12));
@endphp
<div x-data="agenda(@js(['initial' => $agenda, 'url' => route('dashboard.agenda'), 'months' => $monthNames, 'labels' => $labels, 'kindTone' => $kindTone]))" class="min-w-0" data-testid="agenda">
    <x-card :padding="false" class="h-full">
        <div class="flex flex-wrap items-start justify-between gap-3 px-4 pt-4 sm:px-5">
            <div class="min-w-0">
                <h2 class="font-semibold">{{ t('home.agenda.title') }}</h2>
                <p class="text-xs text-muted-foreground">{{ t('home.agenda.subtitle') }}</p>
            </div>
            <div class="flex rounded-full bg-muted p-1 text-xs font-medium" role="group" aria-label="{{ t('home.agenda.rangeLabel') }}">
                @foreach (\App\Services\Dashboard\AgendaService::RANGES as $r)
                    <button type="button" class="rounded-full px-3 py-1.5 transition" @click="setRange('{{ $r }}')"
                        :class="range === '{{ $r }}' ? 'bg-surface text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground'"
                        :aria-pressed="range === '{{ $r }}'">{{ t('home.agenda.ranges.'.$r) }}</button>
                @endforeach
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2 px-4 pb-2 pt-3 sm:px-5">
            <button type="button" class="btn btn-ghost btn-sm px-2" @click="prev()" aria-label="{{ t('home.agenda.prev') }}"><x-icon name="chevron-left" class="size-4" /></button>
            <p class="min-w-0 flex-1 truncate text-sm font-medium first-letter:uppercase sm:flex-none" x-text="title"></p>
            <button type="button" class="btn btn-ghost btn-sm px-2" @click="next()" aria-label="{{ t('home.agenda.next') }}"><x-icon name="chevron-right" class="size-4" /></button>
            <button type="button" class="btn btn-outline btn-sm" x-show="!isCurrent" @click="goToday()">{{ t('home.agenda.today') }}</button>
            <template x-if="data.canSeeAll">
                <div class="ml-auto flex rounded-full border p-0.5 text-xs" role="group" aria-label="{{ t('home.agenda.whoLabel') }}">
                    @foreach (['me', 'all'] as $w)
                        <button type="button" class="rounded-full px-2.5 py-1" @click="setWho('{{ $w }}')" :class="who === '{{ $w }}' ? 'bg-foreground text-background' : 'text-muted-foreground'"
                            :aria-pressed="who === '{{ $w }}'">{{ t('home.agenda.who.'.$w) }}</button>
                    @endforeach
                </div>
            </template>
        </div>

        {{-- Сколько записей каждого вида --}}
        <div class="flex flex-wrap gap-1.5 px-4 pb-3 sm:px-5" x-show="data.total">
            <template x-for="k in data.kinds" :key="k">
                <span x-show="data.counts[k]" class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium" :class="kindTone[k]">
                    <span x-text="labels[k]"></span><span class="tabular-nums" x-text="data.counts[k]"></span>
                </span>
            </template>
        </div>

        <div class="relative border-t px-2 py-2 sm:px-3" :class="loading && 'opacity-60'" :aria-busy="loading">
            <p x-show="error" class="flex items-center gap-2 px-2 py-3 text-sm text-danger">{{ t('home.agenda.error') }}
                <button type="button" class="link" @click="load()">{{ t('home.agenda.retry') }}</button></p>

            {{-- Просроченное — свернуто, раскрывается --}}
            <template x-if="data.overdue.length">
                <div class="mb-2 rounded-xl border border-danger/20 bg-danger-soft/40">
                    <button type="button" class="flex w-full items-center justify-between gap-2 px-3 py-2 text-sm font-medium text-danger" @click="showOverdue = !showOverdue" :aria-expanded="showOverdue">
                        <span class="flex items-center gap-2"><x-icon name="alert" class="size-4" /><span x-text="@js(t('home.agenda.overdue')) + ': ' + data.overdue.length"></span></span>
                        <x-icon name="chevron-down" class="size-4 transition" x-bind:class="showOverdue && 'rotate-180'" />
                    </button>
                    <ul x-show="showOverdue" x-collapse class="grid grid-cols-1 gap-0.5 px-1 pb-2">
                        <template x-for="it in data.overdue" :key="'o' + it.kind + it.id">
                            <div class="contents">
                                <p class="px-2 pt-1 text-xs text-muted-foreground" x-text="dayLabel(it.date)"></p>
                                @include('dashboard.partials.agenda-item')
                            </div>
                        </template>
                    </ul>
                </div>
            </template>

            {{-- День и неделя: по дням --}}
            <template x-if="(data.range === 'day' || data.range === 'week') && data.total">
                <div class="grid grid-cols-1 gap-2">
                    <template x-for="g in days" :key="g.date">
                        <section>
                            <h3 x-show="data.range === 'week'" class="flex items-center gap-2 px-2 pb-1 pt-2 text-xs font-semibold uppercase tracking-wide"
                                :class="g.today ? 'text-accent' : 'text-muted-foreground'">
                                <span x-text="dayLabel(g.date)"></span><span class="rounded-full bg-muted px-1.5 tabular-nums" x-text="g.count"></span>
                            </h3>
                            <ul class="grid grid-cols-1 gap-0.5"><template x-for="it in g.items" :key="it.kind + it.id">@include('dashboard.partials.agenda-item')</template></ul>
                        </section>
                    </template>
                </div>
            </template>

            {{-- Месяц: сетка на широком экране + сводка по дням --}}
            <template x-if="data.range === 'month' && data.total">
                <div class="grid grid-cols-1 gap-3">
                    <div class="hidden grid-cols-7 gap-1 px-1 pt-1 md:grid">
                        <template x-for="w in weekdays" :key="w"><p class="pb-1 text-center text-xs font-medium uppercase text-muted-foreground" x-text="w"></p></template>
                        <template x-for="c in grid" :key="c.key">
                            <button type="button" class="flex h-14 flex-col items-start justify-between rounded-lg border p-1.5 text-left text-xs transition hover:border-accent/50"
                                :class="[c.inMonth ? 'bg-surface' : 'border-transparent bg-transparent text-muted-foreground/50', c.today && 'ring-2 ring-sky-300']"
                                @click="openDay(c.key)" :aria-label="dayLabel(c.key) + ': ' + c.count">
                                <span class="tabular-nums" x-text="c.day"></span>
                                <span x-show="c.count" class="rounded-full bg-sky-100 px-1.5 font-semibold tabular-nums text-sky-700" x-text="c.count"></span>
                            </button>
                        </template>
                    </div>
                    <ul class="grid grid-cols-1 gap-1">
                        <template x-for="g in days" :key="g.date">
                            <li class="rounded-xl" :class="g.today && 'bg-sky-50'">
                                <button type="button" class="flex w-full items-center gap-2 px-2 py-2 text-left" @click="open[g.date] = !open[g.date]" :aria-expanded="!!open[g.date]">
                                    <span class="w-24 shrink-0 text-sm font-medium first-letter:uppercase" :class="g.past && !g.today && 'text-muted-foreground'" x-text="dayLabel(g.date)"></span>
                                    <span class="flex min-w-0 flex-1 flex-wrap gap-1">
                                        <template x-for="(n, k) in kindCounts(g.items)" :key="k"><span class="rounded-full px-2 py-0.5 text-xs" :class="kindTone[k]" x-text="labels[k] + ' ' + n"></span></template>
                                    </span>
                                    <x-icon name="chevron-down" class="size-4 shrink-0 text-muted-foreground transition" x-bind:class="open[g.date] && 'rotate-180'" />
                                </button>
                                <ul x-show="open[g.date]" x-collapse class="grid grid-cols-1 gap-0.5 pb-1"><template x-for="it in g.items" :key="it.kind + it.id">@include('dashboard.partials.agenda-item')</template></ul>
                            </li>
                        </template>
                    </ul>
                </div>
            </template>

            {{-- Год: сводка по месяцам --}}
            <template x-if="data.range === 'year' && data.total">
                <ul class="grid grid-cols-2 gap-1.5 sm:grid-cols-3 xl:grid-cols-4">
                    <template x-for="m in monthsSummary" :key="m.key">
                        <li>
                            <button type="button" class="flex h-full w-full items-start gap-2 rounded-xl border p-2.5 text-left transition hover:border-accent/50" :class="m.current ? 'border-sky-300 bg-sky-50' : 'bg-surface'"
                                @click="openMonth(m.key)" :aria-label="m.label + ': ' + m.count">
                                <span class="min-w-0 flex-1">
                                    <span class="block text-sm font-medium" x-text="m.label"></span>
                                    <span class="mt-1 flex flex-wrap gap-1">
                                        <template x-for="(n, k) in m.kinds" :key="k"><span class="rounded-full px-1.5 py-0.5 text-[11px]" :class="kindTone[k]" x-text="labels[k] + ' ' + n"></span></template>
                                    </span>
                                </span>
                                <span class="text-lg font-semibold tabular-nums" :class="!m.count && 'text-muted-foreground/50'" x-text="m.count"></span>
                            </button>
                        </li>
                    </template>
                </ul>
            </template>

            <template x-if="!data.total && !error">
                <div class="flex flex-col items-center gap-1 px-4 py-10 text-center">
                    <x-icon name="calendar" class="size-8 text-muted-foreground/50" />
                    <p class="font-medium">{{ t('home.agenda.empty') }}</p>
                    <p class="text-sm text-muted-foreground">{{ t('home.agenda.emptyText') }}</p>
                </div>
            </template>
        </div>

        <div class="flex flex-wrap gap-2 border-t px-4 py-3 sm:px-5">
            @if ($access->can('meeting.read'))
                <a href="/sales/meetings" class="btn btn-outline btn-sm"><x-icon name="calendar" class="size-4" />{{ t('home.agenda.allMeetings') }}</a>
                <a href="/sales/meetings/board" class="btn btn-outline btn-sm"><x-icon name="kanban" class="size-4" />{{ t('home.agenda.slots') }}</a>
            @endif
            <a href="/todos" class="btn btn-ghost btn-sm"><x-icon name="list" class="size-4" />{{ t('home.agenda.allTodos') }}</a>
        </div>
    </x-card>
</div>
