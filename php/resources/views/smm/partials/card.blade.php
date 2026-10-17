{{-- Карточка идеи на Kanban: $idea, $status --}}
@php
    use App\Services\Smm\SmmIdeaService;
    $draggable = $idea->can_move;
@endphp
<div @class(['rounded-md border bg-surface p-3 text-sm shadow-sm', 'cursor-grab' => $draggable])
     @if ($draggable) draggable="true" @dragstart="drag($event, '{{ $idea->id }}')" @endif
     @dragover.prevent.stop="over = '{{ $status }}'" @drop.prevent.stop="drop($event, '{{ $status }}', '{{ $idea->id }}')">
    <a href="/smm/{{ $idea->id }}" class="font-medium leading-snug hover:underline">{{ $idea->title }}</a>
    <p class="mt-0.5 text-xs text-muted-foreground">{{ SmmIdeaService::code($idea) }}</p>
    <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground">
        <span class="flex items-center gap-1"><x-icon name="user" class="size-3" />{{ $idea->assignee?->full_name }}</span>
        @if ($status === 'PUBLISHED' && $idea->published_at)
            <span class="flex items-center gap-1"><x-icon name="play" class="size-3" /> {{ dt($idea->published_at) }}</span>
        @elseif ($idea->planned_publish_date)
            <span class="flex items-center gap-1"><x-icon name="calendar" class="size-3" /> {{ d($idea->planned_publish_date) }}</span>
        @endif
        @if ($idea->comments_count)<span class="flex items-center gap-1"><x-icon name="message" class="size-3" /> {{ $idea->comments_count }}</span>@endif
    </div>
    @if ($status === 'IDEA')
        <div class="mt-2 flex flex-wrap gap-1">
            <x-badge :color="$idea->pm_approved_by_id ? 'green' : 'gray'">{{ t('smm.pmShort') }} {{ $idea->pm_approved_by_id ? '✓' : '…' }}</x-badge>
            <x-badge :color="$idea->ceo_approved_by_id ? 'green' : 'gray'">CEO {{ $idea->ceo_approved_by_id ? '✓' : '…' }}</x-badge>
        </div>
    @endif
    @if ($draggable && $status !== 'IDEA')
        {{-- Телефон: перенос выбором этапа --}}
        <select class="input mt-2 h-8 py-0 text-xs xl:hidden" aria-label="{{ t('smm.moveTo') }}"
                x-on:change="request('{{ $idea->id }}', $event.target.value); $event.target.value = '{{ $status }}'">
            @foreach (['APPROVED', 'SHOOTING', 'EDITING', 'SCHEDULED', 'PUBLISHED'] as $to)
                <option value="{{ $to }}" @selected($to === $status)>{{ $to === $status ? t('smm.moveTo').': '.t('smm.status.'.$to) : t('smm.status.'.$to) }}</option>
            @endforeach
        </select>
    @endif
</div>
