{{-- Поля оплаты (создание и исправление): $payment — null для новой, $prefix — для id полей, $types, $methods, $contractOptions, $panel. --}}
@php
    $mine = old('_form') === ($payment ? 'payment-edit-'.$payment->id : 'payment-new');
    $val = fn (string $k, $default) => $mine ? old($k, $default) : $default;
    $signed = $panel['signed'] ?? null;
    $err = fn (string $k) => $mine ? $errors->first($k) : null;
@endphp
<div class="grid gap-4 sm:grid-cols-[1fr_6rem]">
    <div>
        <label class="label" for="{{ $prefix }}-amount">{{ t('sales.payments.amount') }}<span class="text-danger"> *</span></label>
        <input id="{{ $prefix }}-amount" name="amount" class="input" inputmode="decimal" required value="{{ $val('amount', $payment ? \App\Services\Sales\Numbers::rate($payment->amount) : '') }}">
        @if ($err('amount'))<p class="error">{{ $err('amount') }}</p>@endif
    </div>
    <div>
        <label class="label" for="{{ $prefix }}-cur">{{ t('sales.currency') }}</label>
        @php($cur = $val('currency', $payment?->currency ?? ($signed?->currency ?? $deal->currency)))
        <select id="{{ $prefix }}-cur" name="currency" class="input">
            @foreach (['UZS', 'USD'] as $c)<option value="{{ $c }}" @selected($cur === $c)>{{ $c }}</option>@endforeach
        </select>
    </div>
</div>
<div class="grid gap-4 sm:grid-cols-2">
    <div>
        <label class="label" for="{{ $prefix }}-type">{{ t('sales.payments.type') }}</label>
        @php($type = $val('type', $payment?->type ?? 'PREPAYMENT'))
        <select id="{{ $prefix }}-type" name="type" class="input">
            @foreach ($types as $k => $label)<option value="{{ $k }}" @selected($type === $k)>{{ $label }}</option>@endforeach
        </select>
    </div>
    <div>
        <label class="label" for="{{ $prefix }}-method">{{ t('sales.payments.method') }}</label>
        @php($method = $val('method', $payment?->method ?? 'BANK'))
        <select id="{{ $prefix }}-method" name="method" class="input">
            @foreach ($methods as $k => $label)<option value="{{ $k }}" @selected($method === $k)>{{ $label }}</option>@endforeach
        </select>
    </div>
    <div>
        <label class="label" for="{{ $prefix }}-contract">{{ t('sales.payments.contract') }}</label>
        @php($contract = (string) $val('contract_id', $payment ? $payment->contract_id : $signed?->id))
        <select id="{{ $prefix }}-contract" name="contract_id" class="input">
            <option value="">—</option>
            @foreach ($contractOptions as $k => $label)<option value="{{ $k }}" @selected($contract === (string) $k)>{{ $label }}</option>@endforeach
        </select>
        @if ($err('contract_id'))<p class="error">{{ $err('contract_id') }}</p>@endif
    </div>
    <div>
        <label class="label" for="{{ $prefix }}-due">{{ t('sales.payments.dueDate') }}</label>
        <input id="{{ $prefix }}-due" type="date" name="due_date" class="input" value="{{ $val('due_date', $payment?->due_date?->format('Y-m-d')) }}">
        @if ($err('due_date'))<p class="error">{{ $err('due_date') }}</p>@endif
    </div>
</div>
<div>
    <label class="label" for="{{ $prefix }}-comment">{{ t('sales.comment') }}</label>
    <textarea id="{{ $prefix }}-comment" name="comment" rows="2" class="input">{{ $val('comment', $payment?->comment) }}</textarea>
</div>
