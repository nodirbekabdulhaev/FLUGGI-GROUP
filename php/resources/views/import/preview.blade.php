@extends('layouts.app')
@section('title', t('import.previewTitle'))
@section('content')
    @php($ready = $totals['create'] + $totals['update'])
    <x-page-header :title="t('import.previewTitle')" :subtitle="t('import.previewSubtitle', ['file' => $data['filename'], 'entity' => t('import.entities.'.$entity)])" :back="route('import.'.$entity)" />

    @include('import.partials.totals', [
        'kinds' => ['create' => ['import.totals.create', 'good'], 'update' => ['import.totals.update', 'good'], 'duplicate' => ['import.totals.duplicate', 'warn'], 'error' => ['import.totals.error', 'bad']],
        'base' => route('import.'.$entity.'.preview', ['token' => $token]),
    ])

    @if (! empty($data['unknown']))
        <div class="mb-4 rounded-md border border-warning/30 bg-warning-soft p-3 text-sm" role="status">
            {{ t('import.unknownColumns', ['columns' => implode(', ', $data['unknown'])]) }}
        </div>
    @endif
    @php($chosen = array_keys(array_filter($data['options'] ?? [])))
    @if ($chosen)
        <p class="mb-4 text-sm text-muted-foreground">{{ t('import.chosenOptions') }}: {{ collect($chosen)->map(fn ($o) => t('import.options.'.$o))->implode('; ') }}</p>
    @endif

    <div class="mb-4 flex flex-wrap items-center gap-2">
        <form method="POST" action="{{ route('import.'.$entity.'.confirm', ['token' => $token]) }}" x-data="{ busy: false }" x-on:submit="if (busy) { $event.preventDefault(); return; } busy = true">
            @csrf
            <button class="btn btn-primary" @disabled($ready === 0) x-bind:disabled="busy || {{ $ready === 0 ? 'true' : 'false' }}">
                <x-icon name="check" /> <span x-show="!busy">{{ t('import.confirm', ['count' => $ready]) }}</span><span x-show="busy" x-cloak>{{ t('import.running') }}</span>
            </button>
        </form>
        <form method="POST" action="{{ route('import.'.$entity.'.cancel', ['token' => $token]) }}">
            @csrf @method('DELETE')
            <button class="btn btn-ghost">{{ t('import.cancel') }}</button>
        </form>
        <p class="text-xs text-muted-foreground">{{ t('import.confirmHint') }}</p>
    </div>

    @include('import.partials.rows')
@endsection
