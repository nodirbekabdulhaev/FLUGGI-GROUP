@extends('layouts.app')
@section('title', __('Источники лидов'))
@section('actions')@include('reports._bar', ['type' => 'sources'])@endsection
@section('content')@include('partials.period')@include('reports._sources')@endsection
