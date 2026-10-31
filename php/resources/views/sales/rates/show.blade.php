@extends('layouts.app')
@section('title', t('rates.title', ['name' => $user->full_name]))
@section('content')
    <x-page-header :title="t('rates.title', ['name' => $user->full_name])" :back="url()->previous() !== url()->current() ? url()->previous() : null" />
    @include('sales.partials.employee-rates', ['user' => $user, 'rates' => $rates])
@endsection
