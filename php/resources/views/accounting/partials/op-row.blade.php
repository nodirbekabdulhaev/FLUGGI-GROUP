{{-- Сумма операции со знаком: приход зелёный, расход красный --}}
<span @class(['whitespace-nowrap font-medium tabular-nums', 'text-success' => $op->direction === 'IN', 'text-danger' => $op->direction === 'OUT'])>{{ $op->direction === 'IN' ? '+' : '−' }} {{ money($op->amount, $op->account?->currency ?? 'UZS') }}</span>
