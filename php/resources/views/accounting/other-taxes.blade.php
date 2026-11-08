@extends('layouts.app')
@section('title', t('accounting.otherTaxes.title'))
@section('content')
    @php use Brick\Math\BigDecimal; @endphp
    <x-page-header :title="t('accounting.otherTaxes.title')" :subtitle="t('accounting.otherTaxes.subtitle')">
        <a href="/accounting/taxes" class="btn btn-outline">{{ t('accounting.tabs.taxes') }}</a>
    </x-page-header>
    @include('accounting.partials.tabs', ['active' => '/accounting/taxes'])

    @if ($groups->isEmpty())
        <div class="card"><x-empty icon="check" :title="t('accounting.otherTaxes.empty')">{{ t('accounting.otherTaxes.emptyText') }}</x-empty></div>
    @else
        <p class="mb-3 text-sm text-muted-foreground">{{ t('accounting.otherTaxes.how') }}</p>
        <form method="POST" action="/accounting/other-taxes">
            @csrf
            <x-table>
                <thead><tr>
                    <th>{{ t('accounting.otherTaxes.payee') }}</th><th class="text-right">{{ t('accounting.otherTaxes.operations') }}</th><th class="text-right">{{ t('accounting.otherTaxes.total') }}</th>
                    <th>{{ t('accounting.taxes.yearly.year') }}</th><th>{{ t('accounting.otherTaxes.sample') }}</th><th class="min-w-48">{{ t('accounting.otherTaxes.article') }}</th>
                </tr></thead>
                <tbody>
                @foreach ($groups as $i => $g)
                    <tr class="align-top">
                        <td class="max-w-64 break-words font-medium">{{ $g['cp'] !== '' ? $g['cp'] : t('accounting.otherTaxes.noPayee') }}
                            <input type="hidden" name="rows[{{ $i }}][cp]" value="{{ $g['cp'] }}">
                            <span class="block text-xs font-normal text-muted-foreground">{{ \Carbon\CarbonImmutable::parse($g['first'])->format('d.m.Y') }} — {{ \Carbon\CarbonImmutable::parse($g['last'])->format('d.m.Y') }}</span></td>
                        <td class="text-right tabular-nums">{{ $g['count'] }}</td>
                        <td class="whitespace-nowrap text-right font-medium tabular-nums">{{ money((string) $g['total']->toScale(2)) }}</td>
                        <td class="whitespace-nowrap text-xs text-muted-foreground">@foreach ($g['years'] as $y => $sum){{ $y }}: {{ money((string) $sum->toScale(2)) }}<br>@endforeach</td>
                        <td class="max-w-80 break-words text-xs text-muted-foreground">{{ \Illuminate\Support\Str::limit($g['sample'], 160) }}</td>
                        <td>
                            <select name="rows[{{ $i }}][category]" class="input h-9" aria-label="{{ t('accounting.otherTaxes.article') }}">
                                <option value="TAX_OTHER">{{ t('accounting.otherTaxes.keep') }}</option>
                                @foreach ($targets as $cat)
                                    @continue($cat === 'TAX_OTHER')
                                    <option value="{{ $cat }}" @selected($g['suggest'] === $cat)>{{ t('accounting.category.'.$cat) }}@if ($g['suggest'] === $cat) · {{ t('accounting.otherTaxes.suggested') }}@endif</option>
                                @endforeach
                            </select>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </x-table>
            <div class="mt-4 flex justify-end"><button class="btn btn-primary" onclick="return confirm(@js(t('accounting.otherTaxes.confirm')))"><x-icon name="check" /> {{ t('accounting.otherTaxes.apply') }}</button></div>
        </form>
    @endif
@endsection
