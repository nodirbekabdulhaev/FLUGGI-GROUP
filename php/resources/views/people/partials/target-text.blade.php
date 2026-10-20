@php($fmt = fn ($v) => $tg['metric'] === 'REVENUE' ? \App\Support\Format::moneyShort($v, $tg['currency']) : \App\Support\Format::number($v))
{{ t('kpi.metric.'.$tg['metric']) }}: {{ $fmt($tg['fact']) }} / {{ $fmt($tg['target']) }}
