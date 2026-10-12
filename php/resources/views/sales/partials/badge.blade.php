{{-- Статус документа продаж: @include('sales.partials.badge', ['kind' => 'proposalStatus', 'status' => $p->status]) --}}
@php($tone = [
    'DRAFT' => 'gray', 'SENT' => 'blue', 'VIEWED' => 'blue', 'IN_APPROVAL' => 'yellow', 'ACCEPTED' => 'green',
    'REJECTED' => 'red', 'EXPIRED' => 'red', 'SIGNED' => 'green', 'CANCELLED' => 'red', 'PENDING' => 'yellow',
    'PAID' => 'green', 'ACCRUED' => 'blue', 'APPROVED' => 'green',
][$status] ?? 'gray')
<x-badge :color="$tone">{{ t('sales.'.$kind.'.'.$status) }}</x-badge>
