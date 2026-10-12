@extends('layouts.app')
@php($titleKey = ['MANAGER' => 'titleManagers', 'ROP' => 'titleRops', 'EXECUTOR' => 'titleExecutors'][$fixedRole] ?? 'title')
@section('title', t('employees.'.$titleKey))
@section('content')
    @php
        $me = access();
        $filtered = ! empty($filters['q']) || (! $fixedRole && ! empty($filters['role'])) || ! empty($filters['team']) || ! empty($filters['status']);
        $base = '/team/'.$slug;
        $keep = http_build_query(array_filter($filters));
        $link = fn (string $param, string $id) => $base.'?'.($keep ? $keep.'&' : '').$param.'='.$id;
    @endphp
    <x-page-header :title="t('employees.'.$titleKey)" :subtitle="t('employees.subtitle')">
        @if ($canManage)
            <button type="button" class="btn btn-primary" @click="$dispatch('open-modal', 'emp-new')"><x-icon name="plus" /> {{ t('employees.add') }}</button>
        @endif
    </x-page-header>
    <x-tabs :items="['/team/employees' => t('nav.employees'), '/team/managers' => t('nav.managers'), '/team/rop' => t('nav.rops'), '/team/executors' => t('nav.executors')]" :active="$base" />

    <div class="card">
        <form method="GET" class="flex flex-col gap-3 border-b p-4 md:flex-row md:items-center">
            <input type="search" name="q" value="{{ $filters['q'] ?? '' }}" class="input md:flex-1" placeholder="{{ t('employees.searchPlaceholder') }}" aria-label="{{ t('common.search') }}">
            <div class="grid grid-cols-2 gap-3 sm:flex">
                @unless ($fixedRole)
                    <select name="role" class="input sm:w-44" aria-label="{{ t('employees.filterRole') }}" onchange="this.form.submit()">
                        <option value="">{{ t('employees.filterRole') }}: {{ t('common.all') }}</option>
                        @foreach (\App\Services\People\UserService::ROLES as $r)<option value="{{ $r }}" @selected(($filters['role'] ?? '') === $r)>{{ t('roles.'.$r) }}</option>@endforeach
                    </select>
                @endunless
                @if ($fixedRole !== 'EXECUTOR')
                    <select name="team" class="input sm:w-44" aria-label="{{ t('employees.filterTeam') }}" onchange="this.form.submit()">
                        <option value="">{{ t('employees.filterTeam') }}: {{ t('common.all') }}</option>
                        @foreach ($teams as $tid => $tname)<option value="{{ $tid }}" @selected(($filters['team'] ?? '') === $tid)>{{ $tname }}</option>@endforeach
                    </select>
                @endif
                <select name="status" class="input sm:w-40" aria-label="{{ t('employees.filterStatus') }}" onchange="this.form.submit()">
                    <option value="">{{ t('employees.filterStatus') }}: {{ t('common.all') }}</option>
                    @foreach (['ACTIVE', 'BLOCKED'] as $st)<option value="{{ $st }}" @selected(($filters['status'] ?? '') === $st)>{{ t('userStatus.'.$st) }}</option>@endforeach
                </select>
                <button class="btn btn-outline">{{ t('common.filter') }}</button>
            </div>
        </form>

        @if ($users->isEmpty())
            @if ($filtered)
                <x-empty :title="t('employees.emptyFiltered')" />
            @else
                <x-empty icon="users" :title="t('employees.emptyTitle')">{{ t('employees.emptyText') }}</x-empty>
            @endif
        @else
            <ul class="divide-y">
                @foreach ($users as $u)
                    @php
                        $sub = $u->employee?->specialty ? t('specialties.'.$u->employee->specialty) : $u->employee?->position;
                        $sub = $sub && $sub !== t('roles.'.$u->role->code) ? $sub : null;
                        $self = $u->id === $me->id();
                        $actions = ($canManage || $canRates) && ! ($u->role->code === 'CEO' && $me->roleCode !== 'CEO');
                    @endphp
                    <li class="flex flex-wrap items-start gap-3 px-4 py-3 sm:flex-nowrap sm:items-center sm:px-5">
                        <span class="flex size-9 shrink-0 items-center justify-center rounded-full bg-muted text-xs font-semibold">{{ mb_strtoupper(mb_substr($u->full_name, 0, 1)) }}</span>
                        <div class="min-w-0 flex-1">
                            <p class="flex items-center gap-1.5 truncate font-medium">{{ $u->full_name }}@if ($u->telegram_chat_id)<x-icon name="send" class="size-3 text-accent" aria-label="{{ t('employees.telegram') }}" />@endif</p>
                            <p class="truncate text-xs text-muted-foreground">{{ $u->email }}</p>
                        </div>
                        <div class="min-w-0 text-sm sm:w-44">
                            <p>{{ t('roles.'.$u->role->code) }}</p>
                            @if ($sub)<p class="text-xs text-muted-foreground">{{ $sub }}</p>@endif
                            @if ($u->directions->isNotEmpty())<p class="text-xs text-muted-foreground">{{ $u->directions->map(fn ($dd) => $dd->direction?->name)->filter()->implode(', ') }}</p>@endif
                        </div>
                        <div class="text-sm text-muted-foreground sm:w-36">{{ $u->team?->name ?? '—' }}</div>
                        <div class="sm:w-28"><x-badge :color="$u->status === 'ACTIVE' ? 'green' : 'red'">{{ t('userStatus.'.$u->status) }}</x-badge></div>
                        <div class="hidden whitespace-nowrap text-xs text-muted-foreground lg:block lg:w-32">{{ $u->last_login_at ? dt($u->last_login_at) : t('common.never') }}</div>
                        <div class="ml-auto">
                            @if ($actions)
                                <div class="relative" x-data="{ open: false }" @click.outside="open = false">
                                    <button type="button" class="btn btn-ghost btn-icon" @click="open = !open" aria-label="{{ t('common.actions') }}"><x-icon name="more" /></button>
                                    <div x-show="open" x-cloak class="absolute right-0 z-20 mt-1 w-56 rounded-md border bg-surface py-1 text-sm shadow-lg">
                                        @if ($canManage)<a href="{{ $link('edit', $u->id) }}" class="flex items-center gap-2 px-3 py-2 hover:bg-muted"><x-icon name="edit" /> {{ t('common.edit') }}</a>@endif
                                        @if ($canRates)<a href="{{ $link('rates', $u->id) }}" class="flex items-center gap-2 px-3 py-2 hover:bg-muted"><x-icon name="dollar" /> {{ t('employees.rates') }}</a>@endif
                                        @if (! $self && $canManage)
                                            <form method="POST" action="/team/employees/{{ $u->id }}/reset-password" data-confirm="{{ t('employees.resetConfirm', ['name' => $u->full_name]) }}">@csrf
                                                <button class="flex w-full items-center gap-2 px-3 py-2 text-left hover:bg-muted"><x-icon name="lock" /> {{ t('employees.resetPassword') }}</button></form>
                                            @if ($u->status === 'ACTIVE')
                                                <form method="POST" action="/team/employees/{{ $u->id }}/block" data-confirm="{{ t('employees.blockConfirm', ['name' => $u->full_name]) }}">@csrf
                                                    <button class="flex w-full items-center gap-2 px-3 py-2 text-left text-danger hover:bg-muted"><x-icon name="lock" /> {{ t('employees.block') }}</button></form>
                                            @else
                                                <form method="POST" action="/team/employees/{{ $u->id }}/unblock">@csrf
                                                    <button class="flex w-full items-center gap-2 px-3 py-2 text-left hover:bg-muted"><x-icon name="refresh" /> {{ t('employees.unblock') }}</button></form>
                                            @endif
                                            <a href="{{ $link('delete', $u->id) }}" class="flex items-center gap-2 px-3 py-2 text-danger hover:bg-muted"><x-icon name="trash" /> {{ t('employees.delete') }}</a>
                                        @endif
                                    </div>
                                </div>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
            <div class="border-t px-3 py-2">{{ $users->links('vendor.pagination.fluggi') }}</div>
        @endif
    </div>

    @if ($canManage)
        <x-modal name="emp-new" :title="t('employees.createTitle')" :open="$errors->any() && ! isset($editing)">
            <p class="mb-4 text-sm text-muted-foreground">{{ t('employees.createDescription') }}</p>
            @include('people.partials.employee-form', ['user' => null])
        </x-modal>
    @endif
    @isset($editing)
        <x-modal name="emp-edit" :title="t('employees.editTitle')" :open="true">@include('people.partials.employee-form', ['user' => $editing])</x-modal>
    @endisset
    @isset($deleting)
        <x-modal name="emp-delete" :title="t('employees.deleteTitle', ['name' => $deleting->full_name])" :open="true">
            <form method="POST" action="/team/employees/{{ $deleting->id }}" class="grid gap-4 text-sm">
                @csrf @method('DELETE')
                <input type="hidden" name="return_to" value="{{ $base }}">
                <p class="text-muted-foreground">{{ t('employees.deleteText') }}</p>
                @if ($workload['total'] > 0)
                    <ul class="grid grid-cols-2 gap-x-4 gap-y-1 rounded-md bg-muted/50 p-3">
                        @foreach (['leads', 'deals', 'clients', 'projects', 'tasks', 'todos', 'threads'] as $k)
                            @if ($workload[$k] > 0)<li class="flex justify-between gap-2"><span class="text-muted-foreground">{{ t('employees.work.'.$k) }}</span><span class="font-medium tabular-nums">{{ $workload[$k] }}</span></li>@endif
                        @endforeach
                    </ul>
                    <x-select name="transfer_to_id" :label="t('employees.transferTo')" :hint="t('employees.transferHint')" placeholder="—" required
                              :options="$transferTo->mapWithKeys(fn ($tu) => [$tu->id => $tu->full_name.' · '.t('roles.'.$tu->role->code)])->all()" />
                @else
                    <p>{{ t('employees.noOpenWork') }}</p>
                @endif
                <div class="flex justify-end gap-2">
                    <a href="{{ $base }}" class="btn btn-outline">{{ t('employees.cancel') }}</a>
                    <button class="btn btn-danger">{{ t('employees.deleteConfirmButton') }}</button>
                </div>
            </form>
        </x-modal>
    @endisset
    @isset($ratesFor)
        <x-modal name="emp-rates" :title="t('rates.title', ['name' => $ratesFor->full_name])" size="max-w-2xl" :open="true">
            <p class="mb-4 text-sm text-muted-foreground">{{ t($canRates ? 'rates.text' : 'rates.textReadonly') }}</p>
            <form method="POST" action="/team/employees/{{ $ratesFor->id }}/rates" class="grid gap-4">
                @csrf @method('PUT')
                <input type="hidden" name="return_to" value="{{ $base }}">
                @include('people.partials.rates-table', ['editable' => $canRates])
                @if ($canRates)
                    <div class="flex justify-end gap-2"><a href="{{ $base }}" class="btn btn-outline">{{ t('rates.cancel') }}</a><button class="btn btn-primary">{{ t('rates.save') }}</button></div>
                @endif
            </form>
        </x-modal>
    @endisset
    @if (session('tempPassword'))
        @php($tp = session('tempPassword'))
        @if ($tp['password'])
            <x-modal name="temp-password" :title="t('employees.tempPasswordTitle')" :open="true">
                <p class="mb-3 text-sm text-muted-foreground">{{ t('employees.tempPasswordText', ['name' => $tp['name']]) }}</p>
                <div class="flex items-center gap-2 rounded-md border bg-muted/50 p-3" x-data="{ copied: false }">
                    <code class="flex-1 select-all break-all font-mono text-base" data-testid="temp-password">{{ $tp['password'] }}</code>
                    <button type="button" class="btn btn-outline btn-sm" @click="navigator.clipboard.writeText(@js($tp['password'])); copied = true"><span x-text="copied ? @js(t('common.copied')) : @js(t('common.copy'))"></span></button>
                </div>
            </x-modal>
        @endif
    @endif
@endsection
