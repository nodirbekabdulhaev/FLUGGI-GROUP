{{-- Telegram status + one-click personal link. Expects $type (student|guardian|…) and $entity --}}
@can('telegram.manage')
@php $acc = $entity->telegramAccount; @endphp
<div class="mt-3 rounded-lg border border-slate-200 p-3 text-sm dark:border-slate-700">
    <div class="flex items-center justify-between gap-2">
        <span class="font-medium">Telegram</span>
        @if($acc?->isLinked())<x-badge color="emerald">✓ {{ __('Подключён') }}@if($acc->username) · &#64;{{ $acc->username }}@endif</x-badge>
        @else
        <form method="POST" action="{{ route('telegram.link') }}">@csrf<input type="hidden" name="type" value="{{ $type }}"><input type="hidden" name="id" value="{{ $entity->id }}"><button class="btn-secondary btn-sm">{{ __('Получить ссылку') }}</button></form>
        @endif
    </div>
    @if(session('tg_link'))
        <div class="mt-2 rounded bg-emerald-50 p-2 text-xs text-emerald-900 dark:bg-emerald-900/20 dark:text-emerald-200">
            @if(session('tg_link.url'))<a class="break-all underline" href="{{ session('tg_link.url') }}" target="_blank" rel="noopener">{{ session('tg_link.url') }}</a>@else /start {{ session('tg_link.token') }} @endif
        </div>
    @endif
</div>
@endcan
