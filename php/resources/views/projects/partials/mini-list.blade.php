{{-- Короткий список проектов (карточки сделки и клиента) --}}
@if ($projects->isEmpty())
    <x-empty icon="gauge" :title="t('projects.empty')">{{ t('projects.emptyText') }}</x-empty>
@else
    <ul class="divide-y">
        @foreach ($projects as $p)
            <li>
                <a href="/projects/{{ $p->id }}" class="flex flex-wrap items-center gap-3 px-4 py-3 hover:bg-muted/40 sm:px-5">
                    <div class="min-w-0 flex-1">
                        <p class="font-medium">{{ \App\Support\Format::code('P', $p->number) }} · {{ $p->name }}</p>
                        <p class="text-xs text-muted-foreground">{{ $p->rop?->full_name }} · {{ t('projects.fields.deadline') }}: {{ d($p->deadline) }}</p>
                    </div>
                    @include('projects.partials.overdue', ['days' => $p->overdue_days])
                    @include('projects.partials.project-status', ['status' => $p->status])
                    @include('projects.partials.progress', ['c' => $p->task_counts])
                </a>
            </li>
        @endforeach
    </ul>
@endif
