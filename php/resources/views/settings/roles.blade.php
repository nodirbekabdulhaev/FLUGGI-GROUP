@extends('layouts.app')
@section('title', t('settings.rolesTitle'))
@section('content')
    <x-page-header :title="t('settings.title')" />
    @include('settings.partials.tabs')
    @php($locked = $role->code === 'CEO')
    <form method="POST" action="/settings/roles/{{ $role->id }}" class="card" x-data="{ dirty: false }" @change="dirty = true">
        @csrf @method('PUT')
        <header class="border-b px-4 py-4 sm:px-5">
            <h2 class="font-semibold">{{ t('settings.rolesTitle') }}</h2>
            <p class="text-sm text-muted-foreground">{{ t('settings.rolesText') }}</p>
            <div class="mt-3 flex gap-1 overflow-x-auto">
                @foreach ($roles as $r)
                    <a href="/settings/roles?role={{ $r->id }}" @class(['whitespace-nowrap rounded-md px-3 py-1.5 text-sm', 'bg-primary text-primary-foreground' => $r->id === $role->id, 'bg-muted text-muted-foreground hover:text-foreground' => $r->id !== $role->id])>
                        {{ t('roles.'.$r->code) }} <span class="opacity-60">· {{ $r->users_count }}</span>
                    </a>
                @endforeach
            </div>
            @if ($locked)<p class="mt-3 text-xs text-muted-foreground">{{ t('settings.ceoLockedHint') }}</p>@endif
        </header>
        <div class="divide-y">
            @foreach ($modules as $module => $codes)
                <div class="px-4 py-3 sm:px-5">
                    <p class="mb-2 text-xs font-medium uppercase tracking-wide text-muted-foreground">{{ \App\Support\Lang::has('permissionModules.'.$module) ? t('permissionModules.'.$module) : $module }}</p>
                    <div class="grid gap-2 sm:grid-cols-2">
                        @foreach ($codes as $code)
                            @continue($role->code !== 'CEO' && in_array($code, \App\Support\Permissions::CEO_ONLY, true)) {{-- удаление лидов, клиентов, проектов — только CEO --}}
                            <label class="flex items-center justify-between gap-3 text-sm">
                                <span class="min-w-0 truncate" title="{{ $code }}">{{ \App\Support\Lang::has('settings.permissions.'.str_replace('.', '_', $code)) ? t('settings.permissions.'.str_replace('.', '_', $code)) : \App\Support\Permissions::ALL[$code] }}</span>
                                <select name="permissions[{{ $code }}]" class="input h-8 w-28 shrink-0 text-xs" @disabled($locked)>
                                    <option value="">{{ t('scopes.none') }}</option>
                                    @foreach (\App\Support\Permissions::SCOPES as $s)
                                        <option value="{{ $s }}" @selected(($role->permission_map[$code] ?? null) === $s)>{{ t('scopes.'.$s) }}</option>
                                    @endforeach
                                </select>
                            </label>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
        @unless ($locked)
            <div class="sticky bottom-0 flex items-center justify-end gap-3 border-t bg-surface px-5 py-3">
                <span x-show="dirty" x-cloak class="text-sm text-warning">{{ t('settings.rolesUnsaved') }}</span>
                <a href="/settings/roles?role={{ $role->id }}" class="btn btn-outline">{{ t('common.cancel') }}</a>
                <button class="btn btn-primary">{{ t('common.save') }}</button>
            </div>
        @endunless
    </form>
@endsection
