@extends('layouts.app')
@section('title', __('Менеджеры — KPI'))
@section('actions')@include('reports._bar', ['type' => 'managers'])@endsection
@section('content')@include('partials.period')@include('reports._managers')@endsection
