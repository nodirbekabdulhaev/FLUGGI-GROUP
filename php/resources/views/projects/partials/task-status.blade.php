@php($tone = ['TODO' => 'gray', 'IN_PROGRESS' => 'blue', 'REVIEW' => 'yellow', 'DONE' => 'green', 'BLOCKED' => 'red', 'CANCELLED' => 'gray'][$status] ?? 'gray')
<x-badge :color="$tone">{{ t('tasks.status.'.$status) }}</x-badge>
