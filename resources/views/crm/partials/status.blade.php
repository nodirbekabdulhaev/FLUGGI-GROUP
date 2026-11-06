{{-- Статус лида/сделки: $status, $kind = lead|deal --}}
@php $tone = ['OPEN' => 'blue', 'CONVERTED' => 'green', 'WON' => 'green', 'LOST' => 'red', 'REJECTED' => 'red', 'PAUSED' => 'yellow', 'NO_RESPONSE' => 'yellow'][$status] ?? 'gray'; @endphp
<x-badge :color="$tone">{{ t('crm.status.'.$kind.'.'.$status) }}</x-badge>
