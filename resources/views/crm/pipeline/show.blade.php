@extends('layouts.app')
@section('title', t('pipeline.title'))
@section('content')
    @include('crm.partials.errors')
    <x-page-header :title="t('pipeline.title')" :subtitle="t('pipeline.subtitle')">
        <form method="GET" class="grid w-full grid-cols-2 gap-2 sm:flex sm:w-auto">
            <select name="service_id" class="input sm:w-44" aria-label="{{ t('crm.fields.service') }}" onchange="this.form.submit()">
                <option value="">{{ t('crm.fields.service') }}: {{ t('crm.common.all') }}</option>
                @foreach ($services as $id => $name)<option value="{{ $id }}" @selected(($f['service_id'] ?? '') === $id)>{{ $name }}</option>@endforeach
            </select>
            <select name="source_id" class="input sm:w-44" aria-label="{{ t('crm.fields.source') }}" onchange="this.form.submit()">
                <option value="">{{ t('crm.fields.source') }}: {{ t('crm.common.all') }}</option>
                @foreach ($sources as $id => $name)<option value="{{ $id }}" @selected(($f['source_id'] ?? '') === $id)>{{ $name }}</option>@endforeach
            </select>
            @if ($owners)
                <select name="owner_id" class="input col-span-2 sm:w-48" aria-label="{{ t('crm.fields.owner') }}" onchange="this.form.submit()">
                    <option value="">{{ t('crm.fields.owner') }}: {{ t('crm.common.all') }}</option>
                    @foreach ($owners as $id => $name)<option value="{{ $id }}" @selected(($f['owner_id'] ?? '') === $id)>{{ $name }}</option>@endforeach
                </select>
            @endif
        </form>
    </x-page-header>

    @include('crm.partials.board', ['board' => $board, 'totals' => true, 'cfg' => [
        'data' => route('crm.pipeline.data', array_filter($f)),
        'lead' => url('/sales/leads/__ID__/stage'),
        'deal' => url('/sales/deals/__ID__/stage'),
        'canLead' => can('lead.update'),
        'canDeal' => can('deal.change_stage'),
        // «+» в колонке — форма создания на странице лидов/сделок с этим этапом
        'create' => [
            'LEAD' => ['url' => route('crm.leads.index', ['create' => '__STAGE__'])],
            'DEAL' => ['url' => route('crm.deals.index', ['create' => '__STAGE__'])],
        ],
    ]])
@endsection
