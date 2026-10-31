{{-- Автопубликация: Instagram и Telegram-каналы (SmmPublicationService, cron — SmmPublisher) --}}
@php
    use App\Services\Smm\InstagramApi;
    use App\Services\Smm\SmmMedia;
    use App\Services\Smm\SmmPublicationService;
    use App\Services\Smm\TelegramPublisher;
    use App\Support\Format;
    $editable = $canPublish && (! $p || in_array($p->status, SmmPublicationService::EDITABLE, true));
    $approved = in_array($idea->status, SmmPublicationService::PUBLISHABLE, true);
    $fileThere = $p && $p->media_path && SmmMedia::exists($p->media_path);
    $coverThere = $p && $p->cover_path && SmmMedia::exists($p->cover_path);
    $tgThere = $p && $p->tg_media_path && SmmMedia::exists($p->tg_media_path);
    $targets = $p ? $p->targets->sortBy(fn ($t) => ($t->kind === 'TELEGRAM' ? '1' : '0').$t->created_at)->values() : collect();
    $published = $targets->where('status', 'PUBLISHED')->pluck('target_key')->all();
    $hasTelegram = collect(array_keys($options))->contains(fn ($k) => str_starts_with($k, 'tg:')) || $targets->contains('kind', 'TELEGRAM');
    // Отмечено: сохранённые места; новая публикация — Instagram, если он настроен
    $checked = old('targets', $targets->isNotEmpty() ? $targets->pluck('target_key')->all() : (InstagramApi::token() ? [SmmPublicationService::INSTAGRAM] : []));
    $tgTooLarge = $p && $p->kind === 'REELS' && ! $p->tg_media_path && (int) $p->media_size > TelegramPublisher::limitBytes('REELS');
@endphp
<x-card :title="t('smm.ig.title')" id="instagram">
    <div class="grid gap-4 text-sm">
        <div class="flex flex-wrap items-center gap-2">
            @if ($p)
                <x-badge :color="SmmPublicationService::COLORS[$p->status]" data-testid="ig-status">{{ t('smm.ig.status.'.$p->status) }}</x-badge>
                @if ($p->status === 'SCHEDULED' && $p->scheduled_at)<span class="text-muted-foreground">{{ t('smm.ig.willPublish', ['at' => dt($p->scheduled_at)]) }}</span>@endif
            @else
                <span class="text-muted-foreground">{{ t('smm.ig.none') }}</span>
            @endif
        </div>

        {{-- Места публикации: у каждого свой статус, ошибка и ссылка --}}
        @if ($targets->isNotEmpty())
            <ul class="divide-y rounded-md border" data-testid="pub-targets">
                @foreach ($targets as $t)
                    <li class="grid gap-1 p-3">
                        <p class="flex flex-wrap items-center gap-2">
                            <x-icon :name="$t->kind === 'INSTAGRAM' ? 'instagram' : 'send'" class="size-4" />
                            <span class="min-w-0 break-all font-medium">{{ $t->kind === 'INSTAGRAM' ? 'Instagram' : $t->channel?->title }}</span>
                            @if ($t->kind === 'TELEGRAM' && $t->channel)<span class="text-muted-foreground">{{ $t->channel->handle() }}</span>@endif
                            <x-badge :color="SmmPublicationService::COLORS[$t->status]">{{ t('smm.ig.status.'.$t->status) }}</x-badge>
                            @if ($t->status === 'SCHEDULED' && $t->scheduled_at)<span class="text-muted-foreground">{{ t('smm.ig.willPublish', ['at' => dt($t->scheduled_at)]) }}</span>@endif
                            @if ($t->status === 'PUBLISHING')<span class="text-muted-foreground">{{ $t->kind === 'INSTAGRAM' ? t('smm.ig.publishingHint') : t('smm.tg.publishingHint') }}</span>@endif
                            @if ($t->status === 'PUBLISHED')<span class="text-muted-foreground">{{ dt($t->published_at) }}</span>@endif
                        </p>
                        @if ($t->status === 'FAILED' && $t->error)
                            <div class="rounded-md bg-danger-soft p-3">
                                <p class="font-medium text-danger">{{ t('smm.ig.failedTitle') }}</p>
                                <p class="mt-1 whitespace-pre-wrap break-words" data-testid="ig-error">{{ $t->error }}</p>
                                @if ($fileThere)<p class="mt-1 text-xs text-muted-foreground">{{ t('smm.ig.failedKeep', ['days' => (int) config('fluggi.smm_media.failed_keep_days', 7)]) }}</p>@endif
                                @if ($editable && $approved && SmmPublicationService::fileReady($p, $t))
                                    <form method="POST" action="{{ route('smm.publication.target.retry', [$idea->id, $t->id]) }}" class="mt-2">@csrf
                                        <button class="btn btn-primary btn-sm"><x-icon name="refresh" /> {{ t('smm.ig.retry') }}</button></form>
                                @endif
                            </div>
                        @elseif ($t->status === 'SCHEDULED' && $t->error)
                            <p class="text-xs text-muted-foreground">{{ t('smm.tg.postponed', ['error' => $t->error]) }}</p>
                        @endif
                        @if ($t->permalink)
                            <p class="min-w-0 truncate"><a href="{{ $t->permalink }}" class="link" target="_blank" rel="noopener noreferrer">{{ $t->permalink }}</a></p>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif

        {{-- Файл --}}
        @if ($p && $p->media_name)
            <div class="rounded-md border p-3">
                <p class="flex flex-wrap items-center gap-2"><x-icon name="file" class="size-4" />
                    <span class="min-w-0 break-all font-medium">{{ $p->media_name }}</span>
                    <span class="text-muted-foreground">{{ t('smm.ig.kind.'.$p->kind) }} · {{ SmmMedia::human($p->media_size) }}</span></p>
                @if (! $fileThere)
                    <p class="mt-1 text-xs text-muted-foreground" data-testid="ig-file-deleted">{{ $p->status === 'PUBLISHED' ? t('smm.ig.fileDeletedPublished') : t('smm.ig.fileDeleted') }}</p>
                @endif
                @if ($p->kind === 'REELS' && $p->cover_name)
                    <p class="mt-1 text-xs text-muted-foreground">{{ t('smm.ig.cover') }}: {{ $p->cover_name }} · {{ SmmMedia::human($p->cover_size) }}@unless ($coverThere) · {{ t('smm.ig.deletedShort') }}@endunless</p>
                @endif
                @if ($p->tg_media_name)
                    <div class="mt-1 flex flex-wrap items-center gap-2 text-xs text-muted-foreground" data-testid="tg-version">
                        <span>{{ t('smm.tg.version') }}: {{ $p->tg_media_name }} · {{ SmmMedia::human($p->tg_media_size) }}@unless ($tgThere) · {{ t('smm.ig.deletedShort') }}@endunless</span>
                        @if ($editable && $tgThere)
                            <form method="POST" action="{{ route('smm.publication.telegram-file', $idea->id) }}">@csrf @method('DELETE')
                                <button class="link text-danger">{{ t('smm.tg.versionRemove') }}</button></form>
                        @endif
                    </div>
                @endif
            </div>
        @endif

        @if ($editable)
            {{-- Загрузка частями: большие файлы с iPhone проходят при маленьком лимите загрузки хостинга --}}
            <div x-data="igUpload(@js(['start' => route('smm.publication.upload', $idea->id), 'base' => url('/smm/'.$idea->id.'/publication/uploads')]))" class="grid gap-2">
                <div class="flex flex-wrap gap-2">
                    @if ($published === [])
                        <label class="btn btn-outline btn-sm cursor-pointer" :class="busy && 'pointer-events-none opacity-50'">
                            <x-icon name="upload" /> {{ $p && $p->media_name ? t('smm.ig.replaceMedia') : t('smm.ig.uploadMedia') }}
                            <input type="file" class="sr-only" accept="video/mp4,video/quicktime,.mp4,.mov,image/jpeg,image/png,.jpg,.jpeg,.png" @change="upload($event, 'media')">
                        </label>
                    @endif
                    @if ($p && $p->kind === 'REELS' && $fileThere)
                        <label class="btn btn-ghost btn-sm cursor-pointer" :class="busy && 'pointer-events-none opacity-50'">
                            <x-icon name="paperclip" /> {{ t('smm.ig.uploadCover') }}
                            <input type="file" class="sr-only" accept="image/jpeg,image/png,.jpg,.jpeg,.png" @change="upload($event, 'cover')">
                        </label>
                    @endif
                    @if ($p && $p->kind === 'REELS' && $fileThere && $hasTelegram)
                        <label class="btn btn-ghost btn-sm cursor-pointer" :class="busy && 'pointer-events-none opacity-50'">
                            <x-icon name="send" /> {{ $p->tg_media_name ? t('smm.tg.versionReplace') : t('smm.tg.versionUpload', ['mb' => (int) (TelegramPublisher::limitBytes('REELS') / 1024 / 1024)]) }}
                            <input type="file" class="sr-only" accept="video/mp4,video/quicktime,.mp4,.mov" @change="upload($event, 'telegram')">
                        </label>
                    @endif
                </div>
                <div x-show="busy" x-cloak class="grid gap-1">
                    <div class="h-2 overflow-hidden rounded-full bg-muted"><div class="h-full bg-accent transition-all" :style="`width: ${progress}%`"></div></div>
                    <p class="text-xs text-muted-foreground" x-text="status"></p>
                </div>
                <p x-show="error" x-cloak class="text-xs text-danger" x-text="error"></p>
                <details class="text-xs text-muted-foreground">
                    <summary class="cursor-pointer">{{ t('smm.ig.requirementsTitle') }}</summary>
                    <ul class="mt-1 list-disc space-y-0.5 pl-5">
                        @foreach (['req1', 'req2', 'req3', 'req4', 'req5'] as $k)<li>{{ t('smm.ig.'.$k, ['mb' => (int) config('fluggi.smm_media.max_video_mb')]) }}</li>@endforeach
                    </ul>
                </details>
            </div>

            @if ($p)
                <form method="POST" action="{{ route('smm.publication.save', $idea->id) }}" class="grid gap-3" x-data="{ caption: @js(old('caption', $p->caption ?? '')) }">
                    @csrf @method('PUT')
                    {{-- Куда публиковать: Instagram и каждый настроенный Telegram-канал --}}
                    <fieldset class="grid gap-1">
                        <legend class="label">{{ t('smm.pub.targets') }}</legend>
                        <input type="hidden" name="targets_sent" value="1">
                        @foreach ($options as $key => $label)
                            @php $isPublished = in_array($key, $published, true); @endphp
                            <label class="flex items-center gap-2">
                                <input type="checkbox" name="targets[]" value="{{ $key }}" @checked($isPublished || in_array($key, (array) $checked, true)) @disabled($isPublished)>
                                @if ($isPublished)<input type="hidden" name="targets[]" value="{{ $key }}">@endif
                                <x-icon :name="$key === SmmPublicationService::INSTAGRAM ? 'instagram' : 'send'" class="size-4" /> {{ $label }}
                                @if ($isPublished)<span class="text-xs text-muted-foreground">· {{ t('smm.ig.status.PUBLISHED') }}</span>@endif
                            </label>
                        @endforeach
                        @if (! $hasTelegram)
                            <p class="text-xs text-muted-foreground">{{ t('smm.tg.noChannelsHint') }}</p>
                        @elseif ($tgTooLarge)
                            <p class="text-xs text-warning" data-testid="tg-too-large">{{ TelegramPublisher::tooLargeMessage('REELS') }}</p>
                        @endif
                        @error('targets')<p class="error">{{ $message }}</p>@enderror
                    </fieldset>
                    <div>
                        <label class="label" for="ig-caption">{{ t('smm.ig.caption') }}</label>
                        <textarea id="ig-caption" name="caption" rows="6" class="input" maxlength="{{ SmmPublicationService::CAPTION_MAX }}" x-model="caption"></textarea>
                        <p class="mt-1 flex justify-between text-xs text-muted-foreground"><span>{{ t('smm.ig.captionHint') }}</span><span x-text="caption.length + ' / {{ SmmPublicationService::CAPTION_MAX }}'"></span></p>
                        @error('caption')<p class="error">{{ $message }}</p>@enderror
                    </div>
                    @if ($p->kind === 'REELS')
                        <label class="flex items-center gap-2"><input type="hidden" name="share_to_feed" value="0"><input type="checkbox" name="share_to_feed" value="1" @checked(old('share_to_feed', $p->share_to_feed))> {{ t('smm.ig.shareToFeed') }}</label>
                        <x-field name="thumb_offset" type="number" step="0.1" min="0" max="900" :label="t('smm.ig.thumbOffset')" :hint="t('smm.ig.thumbOffsetHint')"
                                 :value="$p->thumb_offset_ms !== null ? rtrim(rtrim(number_format($p->thumb_offset_ms / 1000, 1, '.', ''), '0'), '.') : ''" />
                    @else
                        <input type="hidden" name="share_to_feed" value="1">
                    @endif
                    <x-field name="scheduled_at" type="datetime-local" :label="t('smm.ig.scheduledAt')" :hint="t('smm.ig.scheduledHint')"
                             :value="old('scheduled_at', Format::toLocalInput($p->scheduled_at))" />
                    <p class="text-xs text-muted-foreground">{{ t('smm.tg.captionHint') }}</p>
                    @unless ($approved)<p class="text-xs text-warning">{{ t('smm.ig.errors.notApproved') }}</p>@endunless
                    <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                        <button name="intent" value="draft" class="btn btn-outline">{{ $p->status === 'SCHEDULED' ? t('smm.ig.unschedule') : t('smm.ig.saveDraft') }}</button>
                        <button name="intent" value="schedule" class="btn btn-primary" @disabled(! $approved || ! $fileThere)><x-icon name="calendar" /> {{ t('smm.ig.schedule') }}</button>
                    </div>
                </form>
                <div class="flex flex-wrap gap-2">
                    <form method="POST" action="{{ route('smm.publication.cancel', $idea->id) }}" data-confirm="{{ t('smm.ig.cancelConfirm') }}">
                        @csrf @method('DELETE')<button class="btn btn-ghost btn-sm text-danger"><x-icon name="trash" /> {{ t('smm.ig.cancel') }}</button>
                    </form>
                </div>
            @endif
        @elseif ($p && $p->caption)
            <p class="whitespace-pre-wrap text-muted-foreground">{{ $p->caption }}</p>
        @endif
        <p class="text-xs text-muted-foreground">{{ t('smm.ig.qualityNote') }}</p>
    </div>
</x-card>

@once
<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('igUpload', (urls) => ({
        busy: false, progress: 0, status: '', error: '',
        async upload(e, purpose) {
            const file = e.target.files?.[0];
            e.target.value = '';
            if (!file) return;
            this.busy = true; this.progress = 0; this.error = '';
            try {
                const s = await window.api(urls.start, { method: 'POST', body: { purpose, name: file.name, size: file.size } });
                const total = Math.ceil(file.size / s.chunkSize);
                for (let i = 0; i < total; i++) {
                    const blob = file.slice(i * s.chunkSize, Math.min(file.size, (i + 1) * s.chunkSize));
                    let tries = 0;
                    for (;;) {
                        try {
                            await window.api(`${urls.base}/${s.uploadId}/chunk?index=${i}`, { method: 'POST', body: blob, headers: { 'Content-Type': 'application/octet-stream' } });
                            break;
                        } catch (err) {
                            // Сеть мобильного телефона — до 3 повторов той же части (повтор безопасен)
                            if (++tries > 3 || (err.status && err.status < 500)) throw err;
                            await new Promise((r) => setTimeout(r, 1500 * tries));
                        }
                    }
                    this.progress = Math.round(((i + 1) / total) * 100);
                    this.status = @js(t('smm.ig.uploading', ['p' => '{p}'])).replace('{p}', this.progress);
                }
                this.status = @js(t('smm.ig.processing'));
                const r = await window.api(`${urls.base}/${s.uploadId}/finish`, { method: 'POST' });
                window.toast(r.message);
                window.location.hash = 'instagram';
                window.location.reload();
            } catch (err) {
                this.error = err.message;
                this.busy = false;
            }
        },
    }));
});
</script>
@endonce
