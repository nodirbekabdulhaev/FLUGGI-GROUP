@extends('layouts.app')
@section('title', t('meetings.title'))
@section('content')
    @include('crm.partials.errors')
    <x-page-header :title="t('meetings.title')" :subtitle="t('meetings.subtitle')" />
    <div class="mb-4 flex flex-wrap gap-2">
        @foreach (['upcoming', 'done', 'all'] as $v)
            <a href="{{ route('crm.meetings.index', ['view' => $v]) }}" @class(['btn btn-sm', 'btn-primary' => $view === $v, 'btn-outline' => $view !== $v])>{{ t('meetings.'.$v) }}</a>
        @endforeach
    </div>
    <div class="card">
        @if ($meetings->isEmpty())
            <x-empty :title="t('meetings.emptyTitle')">{{ t('meetings.emptyText') }}</x-empty>
        @else
            <div class="p-2 sm:p-3">@include('crm.partials.meetings', ['meetings' => $meetings->getCollection(), 'target' => null, 'canCreate' => false])</div>
            <div class="border-t px-3 py-2">{{ $meetings->links('vendor.pagination.fluggi') }}</div>
        @endif
    </div>
@endsection
