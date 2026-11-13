{{-- Таймлайн (ТЗ §12): $activities --}}
@if ($activities->isEmpty())
    <x-empty :title="t('crm.common.noActivity')" />
@else
    <ol class="relative grid gap-4 border-l pl-5">
        @foreach ($activities as $a)
            <li class="relative text-sm">
                <span class="absolute -left-[1.6rem] top-1.5 size-2.5 rounded-full border-2 border-surface bg-accent" aria-hidden="true"></span>
                <p class="text-xs text-muted-foreground">{{ dt($a->created_at) }} · {{ $a->actor?->full_name ?? t('crm.common.system') }}</p>
                <p class="mt-0.5 break-words">{{ \App\Services\Crm\TimelineService::describe($a) }}</p>
            </li>
        @endforeach
    </ol>
@endif
