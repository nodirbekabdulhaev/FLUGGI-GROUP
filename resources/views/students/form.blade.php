@extends('layouts.app')
@section('title', $student ? __('Изменить').': '.$student->full_name : __('Новый ученик'))
@section('content')
<form method="POST" enctype="multipart/form-data" action="{{ $student ? route('students.update', $student) : route('students.store') }}" class="card card-body max-w-4xl">
    @csrf @if($student) @method('PUT') @endif
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        @foreach($fields as $f)<x-field :f="$f" :value="$values[$f['name']] ?? null" />@endforeach
    </div>
    <div class="mt-6 flex gap-2">
        <button class="btn-primary">{{ __('Сохранить') }}</button>
        <a href="{{ $student ? route('students.show', $student) : route('students.index') }}" class="btn-secondary">{{ __('Отмена') }}</a>
    </div>
</form>
@endsection
