@extends('layouts.guest')
@section('title', t('common.offline'))
@section('content')
    <div class="mb-6 flex flex-col items-center text-center">
        <img src="{{ brand()->logo() }}" alt="" class="mb-3 size-14 rounded-xl">
        <p class="text-xl font-semibold">{{ brand()->name() }}</p>
    </div>
    <x-empty icon="globe" :title="t('common.offline')" class="card" />
@endsection
