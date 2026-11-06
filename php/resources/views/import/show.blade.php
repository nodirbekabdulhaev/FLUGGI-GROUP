@extends('layouts.app')
@section('title', t('import.title', ['entity' => t('import.entities.'.$entity)]))
@section('content')
    <x-page-header :title="t('import.title', ['entity' => t('import.entities.'.$entity)])" :subtitle="t('import.subtitle')" :back="$back" />

    <div class="grid gap-4 lg:grid-cols-2">
        <div class="grid content-start gap-4">
            <x-card :title="t('import.step1')" :subtitle="t('import.step1Hint')">
                <a href="{{ route('import.'.$entity.'.template') }}" class="btn btn-outline"><x-icon name="download" /> {{ t('import.downloadTemplate') }}</a>
            </x-card>

            <x-card :title="t('import.step2')" :subtitle="t('import.step2Hint', ['rows' => \App\Services\Import\ImportFile::MAX_ROWS, 'mb' => 5])">
                <form method="POST" action="{{ route('import.'.$entity.'.upload') }}" enctype="multipart/form-data" class="grid gap-4"
                      x-data="{ busy: false }" x-on:submit="busy = true">
                    @csrf
                    <div>
                        <label for="import-file" class="label">{{ t('import.file') }}<span class="text-danger"> *</span></label>
                        <input id="import-file" type="file" name="file" required accept=".xlsx,.csv" class="block w-full max-w-full text-sm">
                        @error('file')<p class="error">{{ $message }}</p>@enderror
                    </div>
                    @foreach ($importer->options() as $option)
                        <label class="flex items-start gap-2 text-sm">
                            <input type="checkbox" name="options[{{ $option }}]" value="1" class="mt-0.5" @checked(old('options.'.$option))>
                            <span>{{ t('import.options.'.$option) }}<span class="block text-xs text-muted-foreground">{{ t('import.options.'.$option.'Hint') }}</span></span>
                        </label>
                    @endforeach
                    <div>
                        <button class="btn btn-primary" x-bind:disabled="busy"><x-icon name="upload" /> {{ t('import.check') }}</button>
                    </div>
                </form>
            </x-card>
        </div>

        <x-card :title="t('import.columnsTitle')" :subtitle="t('import.columnsHint')">
            <ul class="grid gap-1 text-sm">
                @foreach ($importer->keys() as $key)
                    <li>{{ $importer->col($key) }}@if ($importer->required($key))<span class="text-danger"> *</span>@endif</li>
                @endforeach
            </ul>
            @if ($importer->rules())
                <h3 class="mt-4 text-sm font-semibold">{{ t('import.template.rules') }}</h3>
                <ul class="mt-1 list-disc space-y-1 pl-5 text-sm text-muted-foreground">
                    @foreach ($importer->rules() as $rule)<li>{{ $rule }}</li>@endforeach
                </ul>
            @endif
        </x-card>
    </div>
@endsection
