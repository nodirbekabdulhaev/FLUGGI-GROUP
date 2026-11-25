@extends('layouts.app')
@section('title', t('employees.tempPasswordTitle'))
@section('content')
    <x-page-header :title="t('employees.tempPasswordTitle')" :subtitle="$name" />
    <x-card class="max-w-xl">
        <p class="mb-3 text-sm text-muted-foreground">{{ t('employees.tempPasswordText', ['name' => $name]) }}</p>
        <div class="flex items-center gap-2 rounded-md border bg-muted/50 p-3" x-data="{ copied: false }">
            <code class="flex-1 select-all break-all font-mono text-base" data-testid="temp-password">{{ $password }}</code>
            <button type="button" class="btn btn-outline btn-sm" @click="navigator.clipboard.writeText(@js($password)); copied = true"><span x-text="copied ? @js(t('common.copied')) : @js(t('common.copy'))"></span></button>
        </div>
        <p class="mt-3 text-xs text-muted-foreground">{{ t('employees.tempPasswordAfter') }}</p>
        <a href="{{ $back }}" class="btn btn-primary mt-4">{{ t('common.back') }}</a>
    </x-card>
@endsection
