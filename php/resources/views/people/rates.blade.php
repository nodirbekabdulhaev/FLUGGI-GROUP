@extends('layouts.app')
@section('title', t('employees.myRates'))
@section('content')
    <x-page-header :title="t('employees.myRates')" :subtitle="t('rates.textReadonly')" :back="'/profile'" />
    <x-card :padding="false">@include('people.partials.rates-table', ['editable' => false])</x-card>
@endsection
