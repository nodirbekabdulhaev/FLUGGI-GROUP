@php($tone = ['LOW' => 'text-muted-foreground', 'MEDIUM' => 'text-muted-foreground', 'HIGH' => 'text-warning', 'URGENT' => 'text-danger'][$priority] ?? '')
<span class="text-xs {{ $tone }}">{{ t('crm.priority.'.$priority) }}</span>
