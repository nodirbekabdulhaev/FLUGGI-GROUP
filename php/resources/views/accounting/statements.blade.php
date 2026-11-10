@extends('layouts.app')
@section('title', t('accounting.statements.title'))
@section('content')
    @php
        use Brick\Math\BigDecimal;
        use Brick\Math\RoundingMode;
        $k = $unit === 'k';
        $fmt = fn (BigDecimal $v) => number_format((float) (string) ($k ? $v->dividedBy(1000, 2, RoundingMode::HalfUp) : $v->toScale(2)), 2, ',', ' ');
        $q = fn (array $extra = []) => '/accounting/statements?'.http_build_query(array_merge(['year' => $year, 'form' => $form, 'unit' => $unit], $extra));
        $sums = array_keys($form === '1' ? \App\Services\Accounting\Statements::B_SUM : \App\Services\Accounting\Statements::F_SUM);
        $editable = $form === '1' ? $bInput : $fInput;
    @endphp
    <x-page-header :title="t('accounting.statements.title')" :subtitle="t('accounting.statements.subtitle', ['date' => \Carbon\CarbonImmutable::parse($asOf)->format('d.m.Y')])">
        <form method="GET" class="flex items-center gap-2">
            <input type="hidden" name="form" value="{{ $form }}"><input type="hidden" name="unit" value="{{ $unit }}">
            <input type="number" name="year" value="{{ $year }}" min="2000" max="2100" class="input w-28" aria-label="{{ t('accounting.taxes.yearly.year') }}" onchange="this.form.submit()">
        </form>
        @if (access()->can('export.run'))<a href="/accounting/annual-report?year={{ $year }}" class="btn btn-outline"><x-icon name="download" /> {{ t('accounting.annual.button') }}</a>@endif
    </x-page-header>
    @include('accounting.partials.tabs', ['active' => '/accounting/statements'])

    <div class="mb-4 flex flex-wrap items-center gap-2">
        <a href="{{ $q(['form' => '1']) }}" @class(['btn btn-sm', $form === '1' ? 'btn-primary' : 'btn-outline'])>{{ t('accounting.statements.form1') }}</a>
        <a href="{{ $q(['form' => '2']) }}" @class(['btn btn-sm', $form === '2' ? 'btn-primary' : 'btn-outline'])>{{ t('accounting.statements.form2') }}</a>
        <span class="ml-auto"></span>
        <a href="{{ $q(['unit' => 'u']) }}" @class(['btn btn-sm', ! $k ? 'btn-primary' : 'btn-outline'])>{{ t('accounting.statements.unitUzs') }}</a>
        <a href="{{ $q(['unit' => 'k']) }}" @class(['btn btn-sm', $k ? 'btn-primary' : 'btn-outline'])>{{ t('accounting.statements.unitThousand') }}</a>
    </div>

    @if ($canManage)
        <x-card class="mb-4" :title="t('accounting.statements.uploadTitle')">
            <form method="POST" action="/accounting/statements/import" enctype="multipart/form-data" class="grid gap-3 sm:grid-cols-[1fr_auto] sm:items-end">
                @csrf<input type="hidden" name="year" value="{{ $year }}">
                <div>
                    <input type="file" name="files[]" class="input" accept=".xltx,.xlsx" multiple required aria-label="{{ t('accounting.statements.uploadTitle') }}">
                    @error('files.*')<p class="error">{{ $message }}</p>@else<p class="hint">{{ t('accounting.statements.uploadHint') }}</p>@enderror
                </div>
                <button class="btn btn-primary"><x-icon name="upload" /> {{ t('accounting.statements.upload') }}</button>
            </form>
        </x-card>
    @endif

    <div x-data="{ f: { code: '', name: '', amount: '', note: '', auto: '' } }">
    @if ($form === '1')
        <div class="mb-4 grid gap-3 sm:grid-cols-3">
            <x-stat :label="t('accounting.statements.assets')" :value="$fmt($close['400']['v'])" :hint="t('accounting.statements.atStart').': '.$fmt($open['400']['v'])" />
            <x-stat :label="t('accounting.statements.equity')" :value="$fmt($close['480']['v'])" :hint="t('accounting.statements.liabilities').': '.$fmt($close['770']['v'])" />
            <x-stat :label="t('accounting.statements.gap')" :value="$fmt($checks['gap'])" :hint="t('accounting.statements.gapHint', ['equity' => $fmt($checks['equity']), 'profit' => $fmt($checks['profit'])])" />
        </div>
        <x-table>
            <thead><tr>
                <th class="w-14">{{ t('accounting.statements.code') }}</th><th>{{ t('accounting.statements.lineName') }}</th>
                <th class="text-right">{{ t('accounting.statements.start') }}</th><th class="text-right">{{ t('accounting.statements.end') }}</th><th>{{ t('accounting.statements.source') }}</th>@if ($canManage)<th class="w-10"></th>@endif
            </tr></thead>
            <tbody>
            @foreach ($bOrder as $code)
                @php $l = $close[$code]; $isSum = in_array($code, $sums, true); @endphp
                <tr @class(['bg-white/40 font-semibold' => $isSum])>
                    <td class="tabular-nums text-muted-foreground">{{ $code }}</td>
                    <td>{{ t('accounting.statements.line.B'.$code) }}</td>
                    <td class="whitespace-nowrap text-right tabular-nums text-muted-foreground">{{ $fmt($open[$code]['v']) }}</td>
                    <td class="whitespace-nowrap text-right tabular-nums">{{ $fmt($l['v']) }}
                        @if ($l['auto'] !== null && ! in_array($l['src'], ['auto', 'balance'], true) && ! $l['v']->isEqualTo($l['auto']))<span class="block text-xs font-normal text-muted-foreground">{{ t('accounting.statements.inSystem') }}: {{ $fmt($l['auto']) }}</span>@endif</td>
                    <td class="text-xs"><span class="badge {{ ['auto' => 'badge-blue', 'manual' => 'badge-yellow', 'file' => 'badge-green', 'carry' => 'badge-gray', 'balance' => 'badge-blue', 'sum' => 'badge-gray', 'empty' => 'badge-gray'][$l['src']] ?? 'badge-gray' }}">{{ t('accounting.statements.src.'.$l['src']) }}</span>
                        @if (in_array($l['src'], ['auto', 'balance'], true) && \App\Support\Lang::has('accounting.statements.how.B'.$code))<span class="block text-muted-foreground">{{ t('accounting.statements.how.B'.$code) }}</span>
                        @elseif ($l['note'])<span class="block text-muted-foreground">{{ $l['note'] }}</span>@endif</td>
                    @if ($canManage)
                        <td>@if (in_array($code, $editable, true))<button type="button" class="btn btn-ghost btn-icon" aria-label="{{ t('common.edit') }}"
                            @click="f = { code: @js($code), name: @js(t('accounting.statements.line.B'.$code)), amount: @js(rtrim(rtrim((string) $l['v']->toScale(2), '0'), '.')), note: @js((string) $l['note']), auto: @js($l['auto'] !== null ? $fmt($l['auto']) : '') }; $dispatch('open-modal', 'stmt-edit')"><x-icon name="edit" /></button>@endif</td>
                    @endif
                </tr>
            @endforeach
            </tbody>
        </x-table>
    @else
        <x-table>
            <thead><tr>
                <th class="w-14">{{ t('accounting.statements.code') }}</th><th>{{ t('accounting.statements.lineName') }}</th>
                <th class="text-right">{{ $year - 1 }}</th><th class="text-right">{{ $year }}</th><th>{{ t('accounting.statements.source') }}</th>@if ($canManage)<th class="w-10"></th>@endif
            </tr></thead>
            <tbody>
            @foreach ($fOrder as $code)
                @php $l = $cur[$code]; $isSum = in_array($code, $sums, true); @endphp
                <tr @class(['bg-white/40 font-semibold' => $isSum])>
                    <td class="tabular-nums text-muted-foreground">{{ $code }}</td>
                    <td>{{ t('accounting.statements.line.F'.$code) }}</td>
                    <td class="whitespace-nowrap text-right tabular-nums text-muted-foreground">{{ $fmt($prior[$code]['v']) }}</td>
                    <td class="whitespace-nowrap text-right tabular-nums">{{ $fmt($l['v']) }}
                        @if ($l['auto'] !== null && $l['src'] !== 'auto' && ! $l['v']->isEqualTo($l['auto']))<span class="block text-xs font-normal text-muted-foreground">{{ t('accounting.statements.inSystem') }}: {{ $fmt($l['auto']) }}</span>@endif</td>
                    <td class="text-xs"><span class="badge {{ ['auto' => 'badge-blue', 'manual' => 'badge-yellow', 'file' => 'badge-green', 'sum' => 'badge-gray', 'empty' => 'badge-gray'][$l['src']] ?? 'badge-gray' }}">{{ t('accounting.statements.src.'.$l['src']) }}</span>
                        @if ($l['src'] === 'auto' && \App\Support\Lang::has('accounting.statements.how.F'.$code))<span class="block text-muted-foreground">{{ t('accounting.statements.how.F'.$code) }}</span>
                        @elseif ($l['note'])<span class="block text-muted-foreground">{{ $l['note'] }}</span>@endif</td>
                    @if ($canManage)
                        <td>@if (in_array($code, $editable, true))<button type="button" class="btn btn-ghost btn-icon" aria-label="{{ t('common.edit') }}"
                            @click="f = { code: @js($code), name: @js(t('accounting.statements.line.F'.$code)), amount: @js(rtrim(rtrim((string) $l['v']->toScale(2), '0'), '.')), note: @js((string) $l['note']), auto: @js($l['auto'] !== null ? $fmt($l['auto']) : '') }; $dispatch('open-modal', 'stmt-edit')"><x-icon name="edit" /></button>@endif</td>
                    @endif
                </tr>
            @endforeach
            </tbody>
        </x-table>
    @endif
    <p class="mt-3 text-xs text-muted-foreground">{{ t($form === '1' ? 'accounting.statements.explain1' : 'accounting.statements.explain2', ['y' => $year - 1]) }}</p>

    @if ($canManage)
        <x-modal name="stmt-edit" :title="t('accounting.statements.edit')">
            <form method="POST" action="/accounting/statements/line" class="space-y-4">
                @csrf
                <input type="hidden" name="year" value="{{ $year }}"><input type="hidden" name="form" value="{{ $form === '1' ? 'B' : 'F' }}"><input type="hidden" name="code" :value="f.code">
                <p class="text-sm font-medium"><span x-text="f.code"></span> · <span x-text="f.name"></span></p>
                <div><label class="label" for="stmt-amount">{{ t('accounting.statements.amount') }}</label>
                    <input id="stmt-amount" name="amount" inputmode="decimal" data-money data-allow-negative class="input" x-model="f.amount">
                    <p class="hint" x-show="f.auto" x-text="@js(t('accounting.statements.inSystem')) + ': ' + f.auto"></p></div>
                <div><label class="label" for="stmt-note">{{ t('accounting.statements.note') }}</label>
                    <textarea id="stmt-note" name="note" rows="2" class="input" maxlength="500" x-model="f.note"></textarea></div>
                <div class="flex flex-wrap justify-end gap-2">
                    <button type="button" class="btn btn-outline" @click="$dispatch('close-modal', 'stmt-edit')">{{ t('common.cancel') }}</button>
                    <button name="reset" value="1" class="btn btn-outline" formnovalidate>{{ t('accounting.statements.reset') }}</button>
                    <button class="btn btn-primary">{{ t('common.save') }}</button>
                </div>
            </form>
        </x-modal>
    @endif
    </div>
@endsection
