@extends('layouts.app')
@section('title', t('accounting.bank.previewTitle'))
@section('content')
    @php
        $currency = $account?->currency ?? 'UZS';
        $colors = ['new' => 'green', 'duplicate' => 'gray', 'possible' => 'yellow', 'error' => 'red'];
        $importable = $account && ($totals['new'] + $totals['possible']) > 0;
    @endphp
    <x-page-header :title="t('accounting.bank.previewTitle')" :subtitle="$data['file']">
        <form method="POST" action="/accounting/bank-import/{{ $token }}/cancel">@csrf<button class="btn btn-outline">{{ t('common.cancel') }}</button></form>
    </x-page-header>
    @include('accounting.partials.tabs', ['active' => '/accounting/operations'])

    <div class="mb-4 grid gap-4 lg:grid-cols-3">
        <x-card :title="t('accounting.bank.statement')">
            <dl class="space-y-1 text-sm">
                <div class="flex justify-between gap-3"><dt class="text-muted-foreground">{{ t('accounting.bank.format') }}</dt><dd>{{ t('accounting.bank.formats.'.$data['format']) }}</dd></div>
                @if ($data['from'])<div class="flex justify-between gap-3"><dt class="text-muted-foreground">{{ t('accounting.bank.period') }}</dt><dd class="whitespace-nowrap">{{ d($data['from']) }} — {{ d($data['to']) }}</dd></div>@endif
                @if ($data['accountNumber'])<div class="flex justify-between gap-3"><dt class="text-muted-foreground">{{ t('accounting.bank.accountNumber') }}</dt><dd class="break-all tabular-nums">{{ $data['accountNumber'] }}</dd></div>@endif
                @if ($data['closing'] !== null)<div class="flex justify-between gap-3"><dt class="text-muted-foreground">{{ t('accounting.bank.closing') }}</dt><dd class="tabular-nums">{{ money($data['closing'], $currency) }}</dd></div>@endif
            </dl>
        </x-card>
        <x-card :title="t('accounting.fields.account')" class="lg:col-span-2">
            <form method="POST" action="/accounting/bank-import/{{ $token }}/account" class="flex flex-col gap-3 sm:flex-row sm:items-end">
                @csrf
                <x-select name="account_id" class="min-w-0 flex-1" :label="t('accounting.bank.importTo')" :placeholder="$account ? null : '—'"
                          :options="$accounts->mapWithKeys(fn ($a) => [$a->id => $a->name.' · '.$a->currency.($a->account_number ? ' · '.$a->account_number : '')])->all()" :value="$account?->id" required />
                <button class="btn btn-outline">{{ t('accounting.bank.changeAccount') }}</button>
            </form>
            @if ($matched)
                <p class="mt-2 text-sm text-success">{{ t('accounting.bank.matched') }}</p>
            @elseif (! $account)
                <p class="mt-2 flex items-center gap-2 text-sm text-warning"><x-icon name="alert" />{{ $data['accountNumber'] ? t('accounting.bank.noMatch', ['number' => $data['accountNumber']]) : t('accounting.bank.chooseAccount') }}</p>
            @elseif ($data['accountNumber'] && $account->account_number !== $data['accountNumber'])
                <p class="mt-2 flex items-center gap-2 text-sm text-warning"><x-icon name="alert" />{{ t('accounting.bank.otherNumber', ['number' => $data['accountNumber']]) }}</p>
            @endif
        </x-card>
    </div>

    <div class="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
        @foreach (['new', 'possible', 'duplicate', 'error'] as $s)
            <x-stat :label="t('accounting.bank.status.'.$s)" :value="$totals[$s]" />
        @endforeach
        <x-stat :label="t('accounting.bank.notCompleted')" :value="$data['notCompleted']" />
        <x-stat :label="t('accounting.bank.unreadable')" :value="count($data['errors'])" />
    </div>
    @if ($totals['possible'] > 0)<p class="mb-3 text-sm text-muted-foreground">{{ t('accounting.bank.possibleHint') }}</p>@endif
    @if ($data['errors'])
        <details class="mb-4 text-sm"><summary class="cursor-pointer text-warning">{{ t('accounting.bank.unreadableRows', ['count' => count($data['errors'])]) }}</summary>
            <ul class="mt-2 space-y-1 text-muted-foreground">@foreach ($data['errors'] as $e)<li>{{ t('accounting.bank.row', ['n' => $e['n']]) }}: {{ $e['message'] }}</li>@endforeach</ul>
        </details>
    @endif

    <form method="POST" action="/accounting/bank-import/{{ $token }}/confirm"
          x-data="{
              bases: @js((object) $bases), bulk: '',
              needsPeriod(c) { const b = this.bases[c] ?? c; return b === 'SALARY' || b.startsWith('TAX_') },
              toggleAll(on) { this.$root.querySelectorAll('[data-on]:not(:disabled)').forEach(el => el.checked = on) },
              apply() {
                  if (! this.bulk) return;
                  const [dir, val] = this.bulk.split(':');
                  this.$root.querySelectorAll('[data-row]').forEach(row => {
                      const on = row.querySelector('[data-on]');
                      const sel = row.querySelector('[data-cat]');
                      if (row.dataset.dir === dir && on && on.checked && sel && ! sel.disabled) { sel.value = val; sel.dispatchEvent(new Event('change')) }
                  });
              },
              collect() {
                  const out = {};
                  this.$root.querySelectorAll('[data-row]').forEach(row => {
                      const on = row.querySelector('[data-on]');
                      if (! on || on.disabled) return;
                      out[row.dataset.idx] = { on: on.checked, category: row.querySelector('[data-cat]').value, period: row.querySelector('[data-period]').value };
                  });
                  this.$refs.payload.value = JSON.stringify(out);
              },
          }" @submit="collect()">
        @csrf
        <input type="hidden" name="payload" x-ref="payload">
        @if ($importable)
            <div class="mb-3 flex flex-col gap-2 sm:flex-row sm:items-end">
                <div class="min-w-0 flex-1">
                    <label class="label" for="bank-bulk">{{ t('accounting.bank.bulk') }}</label>
                    <select id="bank-bulk" class="input" x-model="bulk">
                        <option value="">—</option>
                        @foreach ($groups as $dir => $dirGroups)
                            @foreach ($dirGroups as $label => $opts)
                                <optgroup label="{{ t('accounting.direction.'.$dir) }}: {{ $label }}">
                                    @foreach ($opts as $v => $text)<option value="{{ $dir }}:{{ $v }}">{{ $text }}</option>@endforeach
                                </optgroup>
                            @endforeach
                        @endforeach
                    </select>
                </div>
                <button type="button" class="btn btn-outline" @click="apply()">{{ t('accounting.bank.bulkApply') }}</button>
            </div>
        @endif

        <x-table>
            <thead><tr>
                <th class="w-8"><input type="checkbox" aria-label="{{ t('accounting.bank.selectAll') }}" @change="toggleAll($event.target.checked)" @disabled(! $importable)></th>
                <th>{{ t('accounting.fields.date') }}</th>
                <th class="text-right">{{ t('accounting.fields.amount') }}</th>
                <th>{{ t('accounting.fields.counterparty') }}</th>
                <th class="min-w-[14rem]">{{ t('accounting.fields.category') }}</th>
                <th>{{ t('accounting.bank.statusCol') }}</th>
            </tr></thead>
            <tbody>
            @foreach ($rows as $r)
                @php $can = $account && in_array($r['status'], \App\Services\Accounting\BankImportService::IMPORTABLE, true); @endphp
                <tr data-row data-idx="{{ $r['idx'] }}" data-dir="{{ $r['direction'] }}" x-data="{ c: @js($r['category']) }" @class(['opacity-60' => ! $can])>
                    <td><input type="checkbox" data-on aria-label="{{ t('accounting.bank.include') }}" @checked($can && $r['status'] === 'new') @disabled(! $can)></td>
                    <td class="whitespace-nowrap">{{ d($r['date']) }}<span class="block text-xs text-muted-foreground">{{ t('accounting.bank.row', ['n' => $r['n']]) }}</span></td>
                    <td @class(['whitespace-nowrap text-right font-medium tabular-nums', 'text-success' => $r['direction'] === 'IN', 'text-danger' => $r['direction'] === 'OUT'])>{{ $r['direction'] === 'OUT' ? '−' : '+' }}{{ money($r['amount'], $currency) }}</td>
                    <td class="max-w-xs">
                        <span class="block truncate" title="{{ $r['counterparty'] }}">{{ $r['counterparty'] ?? '—' }}</span>
                        @if ($r['purpose'])<span class="block truncate text-xs text-muted-foreground" title="{{ $r['purpose'] }}">{{ $r['purpose'] }}</span>@endif
                    </td>
                    <td>
                        <select data-cat class="input" x-model="c" aria-label="{{ t('accounting.fields.category') }}" @disabled(! $can)>
                            @foreach ($groups[$r['direction']] as $label => $opts)
                                <optgroup label="{{ $label }}">
                                    @foreach ($opts as $v => $text)<option value="{{ $v }}" @selected($r['category'] === $v)>{{ $text }}</option>@endforeach
                                </optgroup>
                            @endforeach
                        </select>
                        <input type="month" data-period class="input mt-1" value="{{ $r['period'] }}" aria-label="{{ t('accounting.fields.period') }}" x-show="needsPeriod(c)" @disabled(! $can)>
                    </td>
                    <td><x-badge :color="$colors[$r['status']]">{{ t('accounting.bank.status.'.$r['status']) }}</x-badge>
                        @if ($r['message'])<span class="block text-xs text-danger">{{ $r['message'] }}</span>@endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </x-table>
        <p class="mt-2 text-xs text-muted-foreground">{{ t('accounting.bank.categoryHint') }}</p>
        <div class="mt-4 flex justify-end gap-2">
            <button class="btn btn-primary" @disabled(! $importable)><x-icon name="check" /> {{ t('accounting.bank.confirm') }}</button>
        </div>
    </form>
@endsection
