@php
    $tg = \App\Services\Telegram\TelegramService::status(access()->user);
    $tgSettings = \App\Services\Notifications\NotificationService::settings(access()->id(), access()->roleCode);
@endphp
{{-- Подключение Telegram-бота (ТЗ §53): одноразовый код → /start в боте; затем личные настройки каналов --}}
<x-card :title="t('telegram.title')" :subtitle="t('telegram.text')">
    <div x-data="telegramCard(@js($tg['linked']))">
        @if (! $tg['botConfigured'])
            <p class="text-sm text-muted-foreground" data-testid="telegram-not-configured">{{ t('telegram.notConfigured') }}</p>
        @elseif ($tg['problem'])
            <div role="alert" class="rounded-md bg-danger-soft px-3 py-2 text-sm text-danger">
                <p class="font-medium">{{ t('telegram.problem') }}</p>
                <p class="mt-1">{{ $tg['problem'] }}</p>
            </div>
        @elseif ($tg['linked'])
            <div class="grid gap-4">
                <p class="flex flex-wrap items-center gap-2 text-sm">
                    <x-badge color="green">{{ t('telegram.linked') }}</x-badge>
                    @if ($tg['username']){{ '@'.$tg['username'] }}@endif
                    @if ($tg['botUsername'])<span class="text-muted-foreground">· {{ t('telegram.bot', ['name' => $tg['botUsername']]) }}</span>@endif
                </p>
                <div class="flex flex-wrap gap-2">
                    <form method="POST" action="{{ route('telegram.test') }}">@csrf<button class="btn btn-outline">{{ t('telegram.test') }}</button></form>
                    <form method="POST" action="{{ route('telegram.unlink') }}">@csrf @method('DELETE')<button class="btn btn-outline">{{ t('telegram.unlink') }}</button></form>
                </div>
            </div>
        @else
            <div x-show="!link"><button type="button" class="btn btn-primary" :disabled="busy" @click="create()">{{ t('telegram.connect') }}</button></div>
            <div x-show="link" x-cloak class="grid gap-3 text-sm">
                <p>{{ t('telegram.step1') }}</p>
                <div><a :href="link?.deepLink" target="_blank" rel="noreferrer" class="btn btn-primary"><x-icon name="send" /> {{ t('telegram.open') }}</a></div>
                <p class="text-muted-foreground">{{ t('telegram.step2') }}</p>
                <div class="flex items-center gap-2">
                    <code class="min-w-0 flex-1 truncate rounded-md bg-muted px-3 py-2 text-xs" x-text="link?.command"></code>
                    <button type="button" class="btn btn-outline btn-sm" aria-label="{{ t('telegram.copy') }}" @click="navigator.clipboard.writeText(link.command).then(() => window.toast(@js(t('telegram.copied'))))"><x-icon name="copy" /></button>
                </div>
                <p class="text-xs text-muted-foreground">{{ t('telegram.expires') }}</p>
            </div>
        @endif
    </div>
</x-card>

<x-card :title="t('telegram.settingsTitle')" :subtitle="t('telegram.settingsText')" :padding="false">
    <div class="overflow-x-auto" x-data="notificationSettings">
        <table class="table">
            <thead><tr><th>{{ t('telegram.event') }}</th><th class="w-24 text-center">Telegram</th></tr></thead>
            <tbody>
                @foreach ($tgSettings as $s)
                    <tr>
                        <td>{{ $s['label'] }}</td>
                        <td class="text-center">
                            <input type="checkbox" class="size-4 accent-[#4f46e5]" @checked($s['telegram'])
                                   aria-label="{{ $s['label'] }}: Telegram"
                                   @change="toggle(@js($s['type']), 'TELEGRAM', $event)">
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <p class="px-4 py-3 text-xs text-muted-foreground">{{ t('telegram.inAppAlwaysOn') }}</p>
    </div>
</x-card>

@once
<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('telegramCard', (linked) => ({
        link: null, busy: false, timer: null,
        async create() {
            this.busy = true;
            try {
                this.link = await window.api('{{ route('telegram.link') }}', { method: 'POST' });
                // Пока код показан — проверяем, не подключился ли пользователь в боте
                this.timer = setInterval(async () => {
                    try {
                        if ((await window.api('{{ route('telegram.status') }}')).linked) {
                            clearInterval(this.timer);
                            window.toast(@js(t('telegram.linkedToast')));
                            setTimeout(() => window.location.reload(), 800);
                        }
                    } catch (e) {}
                }, 3000);
            } catch (e) { window.toast(e.message, 'error'); } finally { this.busy = false; }
        },
    }));
    Alpine.data('notificationSettings', () => ({
        async toggle(eventType, channel, e) {
            const enabled = e.target.checked;
            try {
                await window.api('{{ route('notifications.settings') }}', { method: 'PUT', body: { settings: [{ eventType, channel, enabled }] } });
            } catch (err) { e.target.checked = !enabled; window.toast(err.message, 'error'); }
        },
    }));
});
</script>
@endonce
