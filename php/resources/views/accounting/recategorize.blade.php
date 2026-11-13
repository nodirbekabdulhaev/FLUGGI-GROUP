@extends('layouts.app')
@section('title', t('accounting.recategorize.title'))
@section('content')
    <x-page-header :title="t('accounting.recategorize.title')" :subtitle="t('accounting.recategorize.subtitle')">
        <a href="/accounting/operations" class="btn btn-outline">{{ t('accounting.tabs.operations') }}</a>
    </x-page-header>
    @include('accounting.partials.tabs', ['active' => '/accounting/operations'])

    <div class="mb-3 flex flex-wrap items-center gap-2">
        <a href="/accounting/recategorize?direction=OUT&category=TAX_OTHER" @class(['btn btn-sm', $direction === 'OUT' ? 'btn-primary' : 'btn-outline'])>{{ t('accounting.direction.OUT') }}</a>
        <a href="/accounting/recategorize?direction=IN&category=OTHER_INCOME" @class(['btn btn-sm', $direction === 'IN' ? 'btn-primary' : 'btn-outline'])>{{ t('accounting.direction.IN') }}</a>
    </div>
    <div class="mb-4 flex flex-wrap gap-2">
        @foreach ($sources as $cat => $n)
            <a href="/accounting/recategorize?direction={{ $direction }}&category={{ $cat }}" @class(['btn btn-sm', $cat === $category ? 'btn-primary' : 'btn-outline'])>{{ t('accounting.category.'.$cat) }} <span class="text-muted-foreground">{{ $n }}</span></a>
        @endforeach
    </div>

    @if ($direction === 'IN')<p class="mb-3 flex items-start gap-2 text-sm text-warning"><x-icon name="alert" class="mt-0.5 shrink-0" />{{ t('accounting.recategorize.inHint') }}</p>@endif
    @if ($groups->isEmpty())
        <div class="card"><x-empty icon="check" :title="t('accounting.recategorize.empty')">{{ t('accounting.recategorize.emptyText') }}</x-empty></div>
    @else
        <p class="mb-3 text-sm text-muted-foreground">{{ t('accounting.recategorize.how') }}</p>
        <form method="POST" action="/accounting/recategorize">
            @csrf
            <input type="hidden" name="direction" value="{{ $direction }}"><input type="hidden" name="category" value="{{ $category }}">
            <x-table>
                <thead><tr>
                    <th>{{ t('accounting.recategorize.payee') }}</th><th class="text-right">{{ t('accounting.recategorize.operations') }}</th><th class="text-right">{{ t('accounting.recategorize.total') }}</th>
                    <th>{{ t('accounting.taxes.yearly.year') }}</th><th>{{ t('accounting.recategorize.sample') }}</th><th class="min-w-48">{{ t('accounting.recategorize.article') }}</th>
                </tr></thead>
                <tbody>
                @foreach ($groups as $i => $g)
                    <tr class="align-top">
                        <td class="max-w-64 break-words font-medium">{{ $g['cp'] !== '' ? $g['cp'] : t('accounting.recategorize.noPayee') }}
                            <input type="hidden" name="rows[{{ $i }}][key]" value="{{ $g['key'] }}">
                            <span class="block text-xs font-normal text-muted-foreground">{{ \Carbon\CarbonImmutable::parse($g['first'])->format('d.m.Y') }} — {{ \Carbon\CarbonImmutable::parse($g['last'])->format('d.m.Y') }}</span></td>
                        <td class="text-right tabular-nums">{{ $g['count'] }}</td>
                        <td class="whitespace-nowrap text-right font-medium tabular-nums">{{ money((string) $g['total']->toScale(2)) }}</td>
                        <td class="whitespace-nowrap text-xs text-muted-foreground">@foreach ($g['years'] as $y => $sum){{ $y }}: {{ money((string) $sum->toScale(2)) }}<br>@endforeach</td>
                        <td class="max-w-96 break-words text-xs text-muted-foreground">@foreach ($g['samples'] as $s)<span class="block">{{ \Illuminate\Support\Str::limit($s, 170) }}</span>@endforeach</td>
                        <td>
                            <select name="rows[{{ $i }}][category]" class="input h-9" aria-label="{{ t('accounting.recategorize.article') }}">
                                <option value="{{ $category }}">{{ t('accounting.recategorize.keep') }}</option>
                                @foreach ($targets as $cat)
                                    @continue($cat === $category)
                                    <option value="{{ $cat }}" @selected($g['suggest'] === $cat)>{{ t('accounting.category.'.$cat) }}@if ($g['suggest'] === $cat) · {{ t('accounting.recategorize.suggested') }}@endif</option>
                                @endforeach
                            </select>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </x-table>
            <div class="mt-4 flex justify-end"><button class="btn btn-primary" onclick="return confirm(@js(t('accounting.recategorize.confirm')))"><x-icon name="check" /> {{ t('accounting.recategorize.apply') }}</button></div>
        </form>
    @endif
@endsection
