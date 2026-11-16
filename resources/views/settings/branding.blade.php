@extends('layouts.app')
@section('title', t('branding.title'))
@section('content')
    <x-page-header :title="t('settings.title')" />
    @include('settings.partials.tabs')
    <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr),20rem]">
        <x-card :title="t('branding.title')" :subtitle="t('branding.subtitle')">
            <form method="POST" action="{{ route('branding.save') }}" enctype="multipart/form-data" class="space-y-5"
                  x-data="{ color: @js((string) old('themeColor', \App\Support\Brand::settings()['themeColor'] ?? '')), preview: null }">
                @csrf
                <x-field name="name" :label="t('branding.name')" :hint="t('branding.nameHint')" :value="$brand->name()" maxlength="60" required />
                <x-field name="shortName" :label="t('branding.shortName')" :hint="t('branding.shortNameHint')" :value="$brand->shortName()" maxlength="20" required />

                <div>
                    <label for="b-logo" class="label">{{ t('branding.logo') }}</label>
                    <div class="flex items-center gap-4">
                        <img src="{{ $brand->logo() }}" :src="preview || @js($brand->logo())" alt="" class="size-16 shrink-0 rounded-lg border object-cover">
                        <input id="b-logo" type="file" name="logo" accept="image/png,image/jpeg,image/webp" class="min-w-0 text-sm" @disabled(! $gd)
                               @change="const f = $event.target.files[0]; preview = f ? URL.createObjectURL(f) : null">
                    </div>
                    @error('logo')<p class="error">{{ $message }}</p>@else<p class="hint">{{ t('branding.logoHint', ['min' => \App\Services\Branding\LogoProcessor::MIN_SIDE]) }}</p>@enderror
                    @unless ($gd)<p class="error">{{ t('branding.errors.noGd') }}</p>@endunless
                </div>

                <div>
                    <label for="b-color" class="label">{{ t('branding.themeColor') }}</label>
                    <div class="flex items-center gap-2">
                        <input type="color" class="h-9 w-12 shrink-0 cursor-pointer rounded-md border bg-transparent p-1" :value="color || @js(\App\Support\Brand::DEFAULT_THEME)" @input="color = $event.target.value" aria-label="{{ t('branding.themeColor') }}">
                        <input id="b-color" name="themeColor" class="input w-32" x-model="color" placeholder="{{ \App\Support\Brand::DEFAULT_THEME }}" maxlength="7">
                        <button type="button" class="btn btn-ghost btn-sm" x-show="color" @click="color = ''">{{ t('branding.colorDefault') }}</button>
                    </div>
                    @error('themeColor')<p class="error">{{ $message }}</p>@else<p class="hint">{{ t('branding.themeColorHint') }}</p>@enderror
                </div>

                <button class="btn btn-primary">{{ t('branding.save') }}</button>
            </form>
        </x-card>

        <div class="space-y-6">
            <x-card :title="t('branding.previewTitle')">
                <div class="flex items-center gap-3">
                    <img src="{{ $brand->logo() }}" alt="" class="size-12 rounded-lg">
                    <div class="min-w-0">
                        <p class="truncate font-semibold">{{ $brand->name() }}</p>
                        <p class="truncate text-sm text-muted-foreground">{{ t('branding.homeScreen', ['name' => $brand->shortName()]) }}</p>
                    </div>
                </div>
                <p class="mt-3 text-xs text-muted-foreground">{{ $brand->custom() ? t('branding.customLogo') : t('branding.defaultLogo') }}</p>
            </x-card>
            <x-card :title="t('branding.resetTitle')" :subtitle="t('branding.resetText')">
                <form method="POST" action="{{ route('branding.reset') }}" data-confirm="{{ t('branding.resetConfirm') }}">
                    @csrf
                    <button class="btn btn-outline">{{ t('branding.reset') }}</button>
                </form>
            </x-card>
        </div>
    </div>
@endsection
