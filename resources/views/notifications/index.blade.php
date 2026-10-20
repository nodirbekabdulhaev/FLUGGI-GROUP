@extends('layouts.app')
@section('title', t('notifications.title'))
@section('content')
    <x-page-header :title="t('notifications.title')" :subtitle="t('notifications.subtitle')">
        <form method="POST" action="{{ route('notifications.readAll') }}">@csrf
            <button class="btn btn-outline"><x-icon name="check" /> {{ t('notifications.readAll') }}</button>
        </form>
    </x-page-header>

    <x-card :padding="false">
        @if ($items->isEmpty())
            <x-empty icon="bell" :title="t('notifications.empty')">{{ t('notifications.emptyText') }}</x-empty>
        @else
            <ul class="divide-y">
                @foreach ($items as $n)
                    <li @class(['flex items-start gap-3 px-4 py-3.5 sm:px-5', 'bg-accent-soft/50' => ! $n->read_at])>
                        <span @class(['mt-1.5 size-2 shrink-0 rounded-full', 'bg-accent' => ! $n->read_at, 'bg-transparent' => $n->read_at]) aria-hidden="true"></span>
                        <div class="min-w-0 flex-1">
                            <p class="break-words text-sm font-medium">{{ $n->title }}</p>
                            @if ($n->body)<p class="whitespace-pre-line break-words text-sm text-muted-foreground">{{ $n->body }}</p>@endif
                            <p class="mt-0.5 text-xs text-muted-foreground">{{ dt($n->created_at) }}</p>
                        </div>
                        @if ($n->link)
                            <a href="{{ route('notifications.open', $n->id) }}" class="btn btn-ghost btn-sm shrink-0">{{ t('notifications.open') }}</a>
                        @endif
                    </li>
                @endforeach
            </ul>
            <div class="border-t px-3 py-2">{{ $items->links('vendor.pagination.fluggi') }}</div>
        @endif
    </x-card>

    @php
        $ok = $telegram['botConfigured'] && ! $telegram['problem'] && $telegram['linked'];
        $line = ! $telegram['botConfigured'] ? t('notifications.tgNotConfigured')
            : ($telegram['problem'] ? t('notifications.tgProblem', ['problem' => $telegram['problem']])
            : ($telegram['linked'] ? t('notifications.tgOn', ['username' => $telegram['username'] ? '@'.$telegram['username'] : '']) : t('notifications.tgOff')));
    @endphp
    <p @class(['mt-3 text-xs', 'text-success' => $ok, 'text-muted-foreground' => ! $ok])>
        {{ $line }}
        @unless ($ok)<a href="{{ route('profile') }}" class="link font-medium">{{ t('notifications.tgSetup') }}</a>@endunless
    </p>
@endsection
