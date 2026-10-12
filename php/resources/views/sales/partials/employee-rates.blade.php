{{-- Личные сдельные ставки сотрудника ($user): свои — каждый видит, менять — только CEO (payroll.manage ALL). --}}
@php
    $editable = can('payroll.manage', 'ALL');
    $rates = $rates ?? \App\Services\Sales\CatalogService::employeeRates($user->id);
@endphp
<div class="grid gap-4">
    <p class="text-sm text-muted-foreground">{{ t($editable ? 'rates.text' : 'rates.textReadonly') }}</p>
    <form method="POST" action="{{ route('sales.rates.update', $user->id) }}" class="grid gap-4">
        @csrf @method('PUT')
        <x-table>
            <thead><tr><th>{{ t('rates.work') }}</th><th class="text-right">{{ t('rates.base') }}</th><th class="min-w-[14rem]">{{ t('rates.personal') }}</th></tr></thead>
            <tbody>
                @forelse ($rates as $i => $r)
                    <tr>
                        <td>
                            <div class="font-medium">{{ $r->workItem->name }}</div>
                            <div class="text-xs text-muted-foreground">{{ $r->workItem->specialty ? t('specialties.'.$r->workItem->specialty) : '—' }} · {{ $r->workItem->unit }}</div>
                        </td>
                        <td class="whitespace-nowrap text-right tabular-nums text-muted-foreground">{{ money($r->workItem->default_rate, $r->workItem->currency) }}</td>
                        <td>
                            @if ($editable)
                                <input type="hidden" name="rates[{{ $i }}][work_item_id]" value="{{ $r->workItem->id }}">
                                <div class="flex gap-1">
                                    <input class="input" name="rates[{{ $i }}][rate]" inputmode="decimal" placeholder="{{ t('rates.asBase') }}" aria-label="{{ t('rates.personal') }}: {{ $r->workItem->name }}" value="{{ $r->rate !== null ? \App\Services\Sales\Numbers::rate($r->rate) : '' }}">
                                    <select class="input w-20" name="rates[{{ $i }}][currency]" aria-label="{{ t('rates.currency') }}">
                                        @foreach (['UZS', 'USD'] as $c)<option value="{{ $c }}" @selected(($r->currency ?? $r->workItem->currency) === $c)>{{ $c }}</option>@endforeach
                                    </select>
                                </div>
                            @elseif ($r->rate !== null)
                                <span class="font-medium tabular-nums">{{ money($r->rate, $r->currency ?? 'UZS') }}</span>
                            @else
                                <span class="text-muted-foreground">{{ t('rates.asBase') }}</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="3"><x-empty :title="t('rates.empty')" /></td></tr>
                @endforelse
            </tbody>
        </x-table>
        @if ($editable && $rates->isNotEmpty())
            <div class="flex justify-end"><button class="btn btn-primary">{{ t('rates.save') }}</button></div>
        @endif
    </form>
</div>
