@extends('layouts.app')
@section('title', t('nav.dashboard'))
@section('content')
    <x-page-header :title="t('dashboard.greeting', ['name' => $access->user->full_name])" :subtitle="t('roles.'.$access->roleCode)" />
@endsection
