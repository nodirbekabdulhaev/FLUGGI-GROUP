@extends('layouts.app')
@section('title', $model ? __('Изменить').': '.__($cfg['singular']) : __($cfg['add'] ?? 'Добавить').' — '.__($cfg['singular']))
@section('content')
<form method="POST" enctype="multipart/form-data" action="{{ $model ? route($cfg['route'].'.update', $model->id) : route($cfg['route'].'.store') }}" class="card card-body max-w-3xl">
    @csrf @if($model) @method('PUT') @endif
    <div class="grid gap-4 sm:grid-cols-2">
        @foreach($cfg['fields'] as $f)
            <x-field :f="$f" :value="$values[$f['name']] ?? null" />
        @endforeach
    </div>
    @isset($cfg['form_extra']) @include($cfg['form_extra'], ['model' => $model]) @endisset
    <div class="mt-6 flex gap-2">
        <button class="btn-primary">{{ __('Сохранить') }}</button>
        <a href="{{ route($cfg['route'].'.index') }}" class="btn-secondary">{{ __('Отмена') }}</a>
    </div>
</form>
@endsection
