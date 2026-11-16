{{-- Выполнение задач: ['c' => ['total'=>, 'done'=>, 'overdue'=>]] --}}
@php($pct = $c['total'] > 0 ? (int) round($c['done'] / $c['total'] * 100) : 0)
<div class="grid min-w-28 gap-1 {{ $class ?? '' }}">
    <div class="h-1.5 overflow-hidden rounded-full bg-muted" role="progressbar" aria-valuenow="{{ $pct }}" aria-valuemin="0" aria-valuemax="100">
        <div class="h-full rounded-full bg-success" style="width: {{ $pct }}%"></div>
    </div>
    <p class="text-xs text-muted-foreground">{{ t('projects.progress', ['done' => $c['done'], 'total' => $c['total']]) }}@if ($c['overdue'])<span class="text-danger"> · {{ t('projects.overdueTasks', ['count' => $c['overdue']]) }}</span>@endif</p>
</div>
