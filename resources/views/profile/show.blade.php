@extends('layouts.app')
@section('title', t('profile.title'))
@section('content')
    <x-page-header :title="t('profile.title')" :subtitle="t('profile.subtitle')" />
    <div class="grid gap-6 lg:grid-cols-2">
        <x-card :title="t('profile.info')">
            <dl class="mb-5 grid grid-cols-[auto,1fr] gap-x-4 gap-y-1.5 text-sm">
                <dt class="muted">{{ t('profile.email') }}</dt><dd>{{ $user->email }}</dd>
                <dt class="muted">{{ t('profile.role') }}</dt><dd>{{ t('roles.'.$access->roleCode) }}</dd>
                @if ($user->team)<dt class="muted">{{ t('profile.team') }}</dt><dd>{{ $user->team->name }}</dd>@endif
            </dl>
            <form method="POST" action="{{ route('profile.update') }}" class="space-y-4">
                @csrf @method('PUT')
                <x-field name="full_name" :label="t('profile.fullName')" :value="$user->full_name" required />
                <x-field name="phone" :label="t('profile.phone')" :value="$user->phone" />
                <x-select name="locale" :label="t('profile.language')" :value="$user->locale" :options="collect(\App\Support\Lang::LOCALES)->mapWithKeys(fn ($l) => [$l => t('common.languages.'.$l)])->all()" />
                <button class="btn btn-primary">{{ t('common.save') }}</button>
            </form>
        </x-card>

        <x-card :title="t('profile.changePassword')">
            <form method="POST" action="{{ route('profile.password') }}" class="space-y-4">
                @csrf @method('PUT')
                <x-field name="current_password" type="password" :label="t('profile.currentPassword')" autocomplete="current-password" required />
                <x-field name="new_password" type="password" :label="t('profile.newPassword')" :hint="t('profile.passwordHint')" autocomplete="new-password" required />
                <x-field name="new_password_confirmation" type="password" :label="t('profile.confirmPassword')" autocomplete="new-password" required />
                <button class="btn btn-primary">{{ t('profile.changePassword') }}</button>
            </form>
        </x-card>

        @includeIf('profile.telegram')

        <x-card :title="t('profile.sessions')" :subtitle="t('profile.sessionsText')" class="lg:col-span-2" :padding="false">
            <ul class="divide-y">
                @foreach ($sessions as $s)
                    <li class="flex items-center justify-between gap-3 px-4 py-3 sm:px-5">
                        <div class="min-w-0 text-sm">
                            <p class="truncate font-medium">{{ \Illuminate\Support\Str::limit($s->user_agent ?? '—', 80) }}</p>
                            <p class="text-xs text-muted-foreground">{{ $s->ip }} · {{ t('profile.lastSeen', ['date' => dt($s->last_seen_at)]) }}</p>
                        </div>
                        @if ($s->id === $access->sessionId)
                            <x-badge color="green">{{ t('profile.thisDevice') }}</x-badge>
                        @else
                            <form method="POST" action="{{ route('profile.sessions.revoke', $s->id) }}">@csrf @method('DELETE')
                                <button class="btn btn-outline btn-sm">{{ t('profile.revoke') }}</button>
                            </form>
                        @endif
                    </li>
                @endforeach
            </ul>
        </x-card>
    </div>
@endsection
