{{-- Статус проекта: @include('projects.partials.project-status', ['status' => $p->status]) --}}
@php($tone = ['NEW' => 'blue', 'PLANNING' => 'blue', 'IN_PROGRESS' => 'blue', 'REVIEW' => 'yellow', 'WAITING_CLIENT' => 'yellow', 'PAUSED' => 'gray', 'COMPLETED' => 'green', 'CANCELLED' => 'red'][$status] ?? 'gray')
<x-badge :color="$tone">{{ t('sales.projectStatus.'.$status) }}</x-badge>
