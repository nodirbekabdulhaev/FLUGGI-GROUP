{{-- «Кошельки и счета» (только с accounting.read — $w собирает App\Services\Dashboard\WalletsService) --}}
@php($kindIcon = ['BANK' => 'briefcase', 'CASH' => 'wallet', 'CARD' => 'dollar'])
@php($kindTone = ['BANK' => 'bg-sky-100 text-sky-600', 'CASH' => 'bg-emerald-100 text-emerald-600', 'CARD' => 'bg-pink-100 text-pink-600'])
<x-card :title="t('home.wallets.title')" :subtitle="t('home.wallets.subtitle')" data-testid="wallets">
    <x-slot:actions><a href="/accounting" class="btn btn-ghost btn-sm">{{ t('home.wallets.open') }} <x-icon name="chevron-right" class="size-4" /></a></x-slot:actions>
    <div class="grid grid-cols-1 gap-4">
        <div class="rounded-2xl bg-gradient-to-br from-sky-100 via-indigo-50 to-pink-100 p-4">
            <p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">{{ t('home.wallets.total') }}</p>
            <p class="mt-1 text-2xl font-semibold tracking-tight tabular-nums sm:text-3xl" data-testid="wallets-total">{{ money($w['totalUzs']) }}</p>
            @if (count($w['byCurrency']) > 1)
                <p class="mt-1 text-xs text-muted-foreground tabular-nums">@foreach ($w['byCurrency'] as $cur => $sum){{ $loop->first ? '' : ' · ' }}{{ money($sum, $cur) }}@endforeach</p>
            @endif
            @if ($w['missingRate'])<p class="mt-1 text-xs text-warning">{{ t('home.wallets.missingRate') }}</p>@endif
        </div>
        @if (! $w['accounts'])
            <p class="text-sm text-muted-foreground">{{ t('home.wallets.empty') }}</p>
        @else
            <ul class="grid grid-cols-1 gap-1">
                @foreach ($w['accounts'] as $acc)
                    <li class="flex min-w-0 items-center gap-3 rounded-xl px-1 py-1.5">
                        <span class="grid size-9 shrink-0 place-items-center rounded-xl {{ $kindTone[$acc['kind']] ?? 'bg-muted' }}"><x-icon :name="$kindIcon[$acc['kind']] ?? 'wallet'" class="size-4" /></span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm font-medium">{{ $acc['name'] }}</span>
                            <span class="block truncate text-xs text-muted-foreground">{{ t('home.wallets.kinds.'.$acc['kind']) }}{{ $acc['bank'] ? ' · '.$acc['bank'] : '' }}</span>
                        </span>
                        <span class="shrink-0 text-right">
                            <span @class(['block whitespace-nowrap text-sm font-semibold tabular-nums', 'text-danger' => str_starts_with($acc['balance'], '-')])>{{ money($acc['balance'], $acc['currency']) }}</span>
                            @if ($acc['currency'] !== 'UZS' && $acc['balanceUzs'] !== null)<span class="block whitespace-nowrap text-xs text-muted-foreground tabular-nums">≈ {{ \App\Support\Format::moneyShort($acc['balanceUzs']) }}</span>@endif
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif
        <div class="flex items-center justify-between gap-3 border-t pt-3 text-sm">
            <span><span class="block text-muted-foreground">{{ t('home.wallets.ytd') }}</span><span class="text-xs text-muted-foreground">{{ t('home.wallets.ytdHint', ['from' => d($w['ytd']['from']), 'to' => d($w['ytd']['to'])]) }}</span></span>
            <span class="whitespace-nowrap font-semibold tabular-nums text-success" data-testid="wallets-ytd">{{ money($w['ytd']['income']) }}</span>
        </div>
    </div>
</x-card>
