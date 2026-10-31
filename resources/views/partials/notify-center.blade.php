{{-- Уведомления со звуком (resources/js/notify.js): опрос, звук, всплывающее окно, Web Push.
     Отключить звук или push в CRM нельзя — только в настройках браузера (требование владельца). --}}
@php($summary = \App\Services\Notifications\NotificationService::unreadSummary(access()->id()))
<script>
    window.fluggiNotify = @js([
        'unreadUrl' => route('notifications.unread'),
        'subscribeUrl' => route('push.subscribe'),
        'vapidKey' => \App\Services\Push\WebPush::publicKey(),
        'sound' => asset('sounds/notify.wav'),
        'initial' => $summary,
        'userId' => access()->id(),
        'texts' => ['pushOn' => t('notifications.push.enabled')],
    ]);
</script>
<div x-data="notifyCenter" class="pointer-events-none fixed inset-x-0 top-16 z-[45] flex flex-col items-center gap-2 px-3 sm:top-[4.5rem] lg:left-64">
    {{-- Звук ещё не разрешён браузером: любой клик/клавиша на странице включает его --}}
    <button type="button" x-show="soundLocked" x-cloak @click="enableSound()"
            class="pointer-events-auto flex max-w-md items-center gap-2 rounded-full border bg-surface px-4 py-2 text-sm shadow-md hover:bg-muted">
        <x-icon name="bell" class="size-4 shrink-0 text-accent" />
        <span>{{ t('notifications.sound.unlock') }}</span>
    </button>

    {{-- Push: разрешение запрашивается только по нажатию кнопки --}}
    <div x-show="pushAvailable && permission === 'default'" x-cloak
         class="pointer-events-auto flex w-full max-w-md flex-col gap-2 rounded-lg border bg-surface p-3 text-sm shadow-md sm:flex-row sm:items-center">
        <p class="flex-1">{{ t('notifications.push.prompt') }}</p>
        <button type="button" class="btn btn-primary btn-sm shrink-0" @click="enablePush()"><x-icon name="bell" /> {{ t('notifications.push.enable') }}</button>
    </div>
    <div x-show="pushAvailable && permission === 'denied'" x-cloak
         class="pointer-events-auto w-full max-w-md rounded-lg border border-warning/40 bg-surface p-3 text-sm shadow-md">
        <p class="font-medium">{{ t('notifications.push.deniedTitle') }}</p>
        <p class="mt-1 text-xs text-muted-foreground">{{ t('notifications.push.deniedHelp') }}</p>
    </div>
    <div x-show="iosHint" x-cloak class="pointer-events-auto w-full max-w-md rounded-lg border bg-surface p-3 text-xs text-muted-foreground shadow-md">
        {{ t('notifications.push.iosHint') }}
    </div>

    {{-- Новое уведомление --}}
    <template x-if="popup">
        <div class="pointer-events-auto flex w-full max-w-md items-start gap-3 rounded-lg border bg-surface p-3 shadow-lg" role="status" aria-live="polite">
            <x-icon name="bell" class="mt-0.5 size-5 shrink-0 text-accent" />
            <a :href="popup.url" class="min-w-0 flex-1">
                <span class="block text-sm font-medium" x-text="popup.title"></span>
                <span x-show="popup.body" class="mt-0.5 line-clamp-2 block text-xs text-muted-foreground" x-text="popup.body"></span>
                <span class="mt-1 block text-xs text-accent">{{ t('notifications.open') }}</span>
            </a>
            <button type="button" class="btn btn-ghost btn-icon -mr-1 -mt-1 shrink-0" @click="popup = null" aria-label="{{ t('common.close') }}"><x-icon name="x" /></button>
        </div>
    </template>
</div>
