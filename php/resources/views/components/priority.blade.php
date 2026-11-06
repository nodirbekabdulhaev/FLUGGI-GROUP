@props(['level' => null])
{{-- Приоритет «таблеткой»: Высокий/Срочный — красный, Средний — оранжевый, Низкий — зелёный --}}
@if ($level)
    @php($tone = ['LOW' => 'badge-green', 'MEDIUM' => 'badge-yellow', 'HIGH' => 'badge-red', 'URGENT' => 'badge-red'][$level] ?? 'badge-gray')
    <span {{ $attributes->merge(['class' => 'badge '.$tone]) }}>{{ t('crm.priority.'.$level) }}</span>
@endif
