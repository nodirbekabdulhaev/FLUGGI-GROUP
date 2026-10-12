{{-- LTV и здоровье клиента в карточке клиента (ТЗ §43). Подключение: @include('analytics.client-insight', ['clientId' => $client->id]) --}}
@php
    $insight = \App\Services\Analytics\ClientInsights::one($clientId);
    $short = fn ($v) => \App\Support\Format::moneyShort((string) $v);
@endphp
<x-card class="mb-6" data-testid="client-insight">
    <div class="grid gap-4">
        <div class="flex flex-wrap items-center gap-2">
            <span class="text-sm font-medium">{{ t('analytics.health.title') }}:</span>
            @include('analytics.health-badge', ['level' => $insight['health']['level'], 'score' => $insight['health']['score']])
            @if ($insight['health']['reasons'])<span class="text-xs text-muted-foreground">{{ implode(' · ', \App\Services\Analytics\ClientInsights::reasonTexts($insight['health']['reasons'])) }}</span>@endif
        </div>
        <dl class="grid grid-cols-2 gap-4 sm:grid-cols-4">
            <div><dt class="text-xs text-muted-foreground">LTV</dt><dd class="text-lg font-semibold tabular-nums">{{ $short($insight['ltv']) }}</dd></div>
            <div><dt class="text-xs text-muted-foreground">{{ t('analytics.paidDeals') }}</dt><dd class="text-lg font-semibold tabular-nums">{{ $insight['paidDeals'] }}</dd></div>
            <div><dt class="text-xs text-muted-foreground">{{ t('analytics.avgCheck') }}</dt><dd class="text-lg font-semibold tabular-nums">{{ $insight['paidDeals'] ? $short($insight['avgCheck']) : '—' }}</dd></div>
            <div><dt class="text-xs text-muted-foreground">{{ t('analytics.lastContact') }}</dt><dd class="text-lg font-semibold">{{ $insight['lastContactAt'] ? $insight['lastContactAt']->locale(app()->getLocale() === 'uz' ? 'uz_Latn' : 'ru')->diffForHumans() : '—' }}</dd></div>
        </dl>
    </div>
</x-card>
