{{-- title + export buttons; expects $type, $from, $to, $key --}}
@php $q = ['period' => $key, 'from' => $from->toDateString(), 'to' => $to->toDateString()]; @endphp
<div class="flex gap-2">
    <a class="btn-secondary" href="{{ route('reports.export', ['type' => $type] + $q + ['format' => 'csv']) }}"><x-icon name="download" class="h-4 w-4" /> CSV</a>
    <a class="btn-secondary" href="{{ route('reports.export', ['type' => $type] + $q + ['format' => 'pdf']) }}">PDF</a>
</div>
