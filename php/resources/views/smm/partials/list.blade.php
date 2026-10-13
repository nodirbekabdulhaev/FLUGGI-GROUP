{{-- Список идей роликов (фильтры — статус, ответственный, месяц) --}}
@php use App\Services\Smm\SmmIdeaService; @endphp
<div class="card">
    @if ($items->isEmpty())
        <x-empty icon="play" :title="t('smm.empty')">{{ t('smm.emptyText') }}</x-empty>
    @else
        <div class="hidden overflow-x-auto md:block"><table class="table">
            <thead><tr>
                <th>{{ t('smm.fields.title') }}</th><th>{{ t('smm.fields.status') }}</th><th>{{ t('smm.fields.assignee') }}</th>
                <th>{{ t('smm.fields.plannedDate') }}</th><th>{{ t('smm.fields.publishedAt') }}</th><th>{{ t('smm.fields.createdAt') }}</th>
            </tr></thead>
            <tbody>
            @foreach ($items as $idea)
                <tr>
                    <td><a href="/smm/{{ $idea->id }}" class="font-medium hover:underline">{{ $idea->title }}</a><span class="block text-xs text-muted-foreground">{{ SmmIdeaService::code($idea) }} · {{ $idea->author?->full_name }}</span></td>
                    <td><x-badge :color="SmmIdeaService::COLORS[$idea->status]">{{ t('smm.status.'.$idea->status) }}</x-badge></td>
                    <td class="text-muted-foreground">{{ $idea->assignee?->full_name }}</td>
                    <td class="whitespace-nowrap">{{ d($idea->planned_publish_date) }}</td>
                    <td class="whitespace-nowrap">{{ dt($idea->published_at) }}</td>
                    <td class="whitespace-nowrap text-muted-foreground">{{ dt($idea->created_at) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
        <ul class="divide-y md:hidden">
            @foreach ($items as $idea)
                <li><a href="/smm/{{ $idea->id }}" class="grid gap-1.5 p-4 active:bg-muted/50">
                    <div class="flex items-start justify-between gap-3"><p class="font-medium">{{ $idea->title }}</p><x-badge :color="SmmIdeaService::COLORS[$idea->status]">{{ t('smm.status.'.$idea->status) }}</x-badge></div>
                    <p class="text-sm text-muted-foreground">{{ SmmIdeaService::code($idea) }} · {{ $idea->assignee?->full_name }}@if ($idea->published_at) · {{ dt($idea->published_at) }}@elseif ($idea->planned_publish_date) · {{ d($idea->planned_publish_date) }}@endif</p>
                </a></li>
            @endforeach
        </ul>
        @if ($items->count() >= 500)<p class="border-t px-4 py-2 text-xs text-muted-foreground">{{ t('smm.limitHint') }}</p>@endif
    @endif
</div>
