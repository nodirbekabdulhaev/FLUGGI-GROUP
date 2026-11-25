@extends('layouts.app')
@section('title', t('import.resultTitle'))
@section('content')
    <x-page-header :title="t('import.resultTitle')" :subtitle="t('import.resultSubtitle', ['file' => $result['filename'], 'entity' => t('import.entities.'.$entity), 'date' => dt($result['finished_at'])])" :back="$back">
        <a href="{{ route('import.'.$entity) }}" class="btn btn-outline btn-sm"><x-icon name="upload" /> {{ t('import.another') }}</a>
        <a href="{{ $back }}" class="btn btn-primary btn-sm">{{ t('import.toList') }}</a>
    </x-page-header>

    @include('import.partials.totals', [
        'kinds' => ['created' => ['import.totals.created', 'good'], 'updated' => ['import.totals.updated', 'good'], 'duplicate' => ['import.totals.skipped', 'warn'], 'error' => ['import.totals.error', 'bad']],
        'base' => route('import.'.$entity.'.result', ['token' => $token]),
    ])

    @include('import.partials.rows')
@endsection
