{{-- Оценка лида (ТЗ §10) --}}
@php $tone = ['LOW' => 'gray', 'MEDIUM' => 'blue', 'HIGH' => 'yellow', 'HOT' => 'red'][$level] ?? 'gray'; @endphp
<x-badge :color="$tone" :title="isset($score) ? $score.'/100' : null">{{ t('crm.score.'.$level) }}@isset($score)<span class="opacity-70">&nbsp;· {{ $score }}</span>@endisset</x-badge>
