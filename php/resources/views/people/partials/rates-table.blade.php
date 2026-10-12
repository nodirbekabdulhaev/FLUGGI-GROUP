{{-- Сдельные ставки: базовая и личная (пусто — как базовая) --}}
<div class="overflow-x-auto">
    <table class="table">
        <thead><tr><th>{{ t('rates.work') }}</th><th class="text-right">{{ t('rates.base') }}</th><th class="w-56">{{ t('rates.personal') }}</th></tr></thead>
        <tbody>
        @foreach ($rates as $w)
            <tr>
                <td><div class="font-medium">{{ $w->name }}</div><div class="text-xs text-muted-foreground">{{ $w->specialty ? t('specialties.'.$w->specialty) : '—' }} · {{ $w->unit }}</div></td>
                <td class="whitespace-nowrap text-right tabular-nums text-muted-foreground">{{ money($w->default_rate, $w->currency) }}</td>
                <td>
                    @if ($editable)
                        <div class="flex gap-1">
                            <input class="input" name="rates[{{ $w->id }}][rate]" inputmode="decimal" placeholder="{{ t('rates.asBase') }}" aria-label="{{ t('rates.personal') }}: {{ $w->name }}" value="{{ $w->personal_rate !== null ? rtrim(rtrim((string) $w->personal_rate, '0'), '.') : '' }}">
                            <select class="input w-20" name="rates[{{ $w->id }}][currency]" aria-label="{{ t('rates.currency') }}">
                                @foreach (['UZS', 'USD'] as $cur)<option @selected(($w->personal_currency ?? $w->currency) === $cur)>{{ $cur }}</option>@endforeach
                            </select>
                        </div>
                    @elseif ($w->personal_rate !== null)
                        <span class="font-medium tabular-nums">{{ money($w->personal_rate, $w->personal_currency ?? 'UZS') }}</span>
                    @else
                        <span class="text-muted-foreground">{{ t('rates.asBase') }}</span>
                    @endif
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
