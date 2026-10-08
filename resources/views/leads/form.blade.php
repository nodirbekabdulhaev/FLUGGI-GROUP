@extends('layouts.app')
@section('title', $lead ? __('Лид').' #'.$lead->id : __('Новый лид'))
@section('content')
<form method="POST" action="{{ $lead ? route('leads.update', $lead) : route('leads.store') }}" class="card card-body max-w-4xl">
    @csrf @if($lead) @method('PUT') @endif
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        @foreach($fields as $f)
            <x-field :f="$f" :value="$values[$f['name']] ?? null" />
        @endforeach
    </div>
    <div class="mt-6 flex gap-2">
        <button class="btn-primary">{{ __('Сохранить') }}</button>
        <a href="{{ $lead ? route('leads.show', $lead) : route('leads.index') }}" class="btn-secondary">{{ __('Отмена') }}</a>
    </div>
</form>
@endsection
