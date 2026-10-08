@extends('layouts.app')
@section('title', $group ? __('Изменить').': '.$group->name : __('Новая группа'))
@section('content')
<form method="POST" action="{{ $group ? route('groups.update', $group) : route('groups.store') }}" class="card card-body max-w-4xl">
    @csrf @if($group) @method('PUT') @endif
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">@foreach($fields as $f)<x-field :f="$f" :value="$values[$f['name']] ?? null" />@endforeach</div>
    <div class="mt-6 flex gap-2"><button class="btn-primary">{{ __('Сохранить') }}</button><a href="{{ $group ? route('groups.show', $group) : route('groups.index') }}" class="btn-secondary">{{ __('Отмена') }}</a></div>
</form>
@endsection
