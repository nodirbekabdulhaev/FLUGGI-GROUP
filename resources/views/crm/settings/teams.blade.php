@extends('layouts.app')
@section('title', t('settings.tabTeams'))
@section('content')
    @include('crm.partials.errors')
    @php $canEdit = can('employee.manage', 'ALL'); @endphp
    <x-page-header :title="t('settings.teamsTitle')" :subtitle="t('settings.teamsText')" back="/settings">
        @if ($canEdit)
            <button type="button" class="btn btn-primary" x-data x-on:click="$dispatch('team-edit', { url: @js(route('crm.teams.store')), method: 'POST', name: '', head_id: '' }); $dispatch('open-modal', 'team')"><x-icon name="plus" /> {{ t('settings.addTeam') }}</button>
        @endif
    </x-page-header>
    <div class="card">
        @if ($teams->isEmpty())
            <x-empty :title="t('settings.teamsEmpty')">{{ t('settings.teamsEmptyText') }}</x-empty>
        @else
            <ul class="divide-y">
                @foreach ($teams as $team)
                    <li class="flex items-center gap-3 px-4 py-4 sm:px-5">
                        <div class="min-w-0 flex-1">
                            <p class="font-medium">{{ $team->name }}</p>
                            <p class="text-sm text-muted-foreground">{{ $team->head?->full_name ?? t('dashboard.noHead') }} · {{ t('dashboard.members', ['count' => $team->members_count]) }}</p>
                        </div>
                        @if ($canEdit)
                            <button type="button" class="btn btn-ghost btn-icon" aria-label="{{ t('common.edit') }}" x-data
                                    x-on:click="$dispatch('team-edit', @js(['url' => route('crm.teams.update', $team->id), 'method' => 'PUT', 'name' => $team->name, 'head_id' => $team->head_id ?? ''])); $dispatch('open-modal', 'team')"><x-icon name="edit" /></button>
                            <form method="POST" action="{{ route('crm.teams.destroy', $team->id) }}" data-confirm="{{ t('settings.teamDeleteConfirm', ['name' => $team->name]) }}">@csrf @method('DELETE')
                                <button class="btn btn-ghost btn-icon" aria-label="{{ t('common.delete') }}"><x-icon name="trash" /></button>
                            </form>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
    @if ($canEdit)
        <x-modal name="team" :title="t('crm.teams.team')">
            <form method="POST" class="grid gap-4" x-data="{ team: { url: @js(route('crm.teams.store')), method: 'POST', name: '', head_id: '' } }" x-on:team-edit.window="team = $event.detail" :action="team.url">
                @csrf <input type="hidden" name="_method" :value="team.method">
                <div><label class="label" for="team-name">{{ t('settings.teamName') }} <span class="text-danger">*</span></label>
                    <input id="team-name" name="name" class="input" required minlength="2" maxlength="120" x-model="team.name">
                    @error('name')<p class="error">{{ $message }}</p>@enderror</div>
                <div><label class="label" for="team-head">{{ t('settings.teamHead') }}</label>
                    <select id="team-head" name="head_id" class="input" x-model="team.head_id">
                        <option value="">{{ t('settings.noHead') }}</option>
                        @foreach ($rops as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                    </select>
                    @error('head_id')<p class="error">{{ $message }}</p>@enderror</div>
                <div class="flex justify-end gap-2">
                    <button type="button" class="btn btn-outline" x-on:click="$dispatch('close-modal', 'team')">{{ t('common.cancel') }}</button>
                    <button type="submit" class="btn btn-primary">{{ t('common.save') }}</button>
                </div>
            </form>
        </x-modal>
    @endif
@endsection
