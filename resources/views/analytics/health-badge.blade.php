{{-- Здоровье клиента: цвет статуса всегда с подписью. @include('analytics.health-badge', ['level' => 'RISK', 'score' => 30]) --}}
<x-badge :color="['HEALTHY' => 'green', 'ATTENTION' => 'yellow', 'RISK' => 'red', 'LOST' => 'gray'][$level]">{{ t('analytics.health.'.$level) }}@if (isset($score) && $level !== 'LOST') · {{ $score }}@endif</x-badge>
