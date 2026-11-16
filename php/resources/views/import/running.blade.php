@extends('layouts.app')
@section('title', t('import.resultTitle'))
@section('content')
    <x-page-header :title="t('import.resultTitle')" :back="$back" />
    <x-card>
        <x-empty icon="clock" :title="t('import.inProgress')">
            <a href="{{ route('import.'.$entity.'.result', ['token' => $token]) }}" class="link">{{ t('import.refresh') }}</a>
        </x-empty>
    </x-card>
@endsection
