{{-- История этапов: $history --}}
@if ($history->isEmpty())
    <x-empty :title="t('crm.common.noHistory')" />
@else
    <ul class="divide-y rounded-lg border">
        @foreach ($history as $h)
            <li class="flex flex-wrap items-center gap-2 px-4 py-3 text-sm">
                <span class="w-32 shrink-0 text-xs text-muted-foreground">{{ dt($h->created_at) }}</span>
                @if ($h->fromStage)<span class="text-muted-foreground">{{ $h->fromStage->label() }}</span><span class="text-muted-foreground">→</span>@endif
                <span class="font-medium">{{ $h->toStage->label() }}</span>
                <span class="text-xs text-muted-foreground">· {{ $h->changedBy?->full_name }}@if ($h->duration_sec) · {{ t('crm.common.durationOnStage', ['d' => \App\Services\Crm\TimelineService::duration($h->duration_sec)]) }}@endif</span>
            </li>
        @endforeach
    </ul>
@endif
