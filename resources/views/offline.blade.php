@extends('layouts.guest')
@section('title', t('common.offline'))
@section('content')
    <x-empty icon="globe" :title="t('common.offline')" class="card" />
@endsection
