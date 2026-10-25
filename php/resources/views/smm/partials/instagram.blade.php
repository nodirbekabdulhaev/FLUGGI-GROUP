{{-- Автопубликация в Instagram (SmmPublicationService, cron — InstagramPublisher) --}}
@php
    use App\Services\Smm\SmmMedia;
    use App\Services\Smm\SmmPublicationService;
    use App\Support\Format;
    $editable = $canPublish && (! $p || in_array($p->status, SmmPublicationService::EDITABLE, true));
    $approved = in_array($idea->status, SmmPublicationService::SCHEDULABLE, true);
    $fileThere = $p && $p->media_path && SmmMedia::exists($p->media_path);
    $coverThere = $p && $p->cover_path && SmmMedia::exists($p->cover_path);
@endphp
<x-card :title="t('smm.ig.title')" id="instagram">
    <div class="grid gap-4 text-sm">
        <div class="flex flex-wrap items-center gap-2">
            <x-icon name="instagram" class="size-4" />
            @if ($p)
                <x-badge :color="SmmPublicationService::COLORS[$p->status]" data-testid="ig-status">{{ t('smm.ig.status.'.$p->status) }}</x-badge>
                @if ($p->status === 'SCHEDULED' && $p->scheduled_at)<span class="text-muted-foreground">{{ t('smm.ig.willPublish', ['at' => dt($p->scheduled_at)]) }}</span>@endif
                @if ($p->status === 'PUBLISHING')<span class="text-muted-foreground">{{ t('smm.ig.publishingHint') }}</span>@endif
                @if ($p->status === 'PUBLISHED')<span class="text-muted-foreground">{{ dt($p->published_at) }}</span>@endif
            @else
                <span class="text-muted-foreground">{{ t('smm.ig.none') }}</span>
            @endif
        </div>

        @if ($p && $p->status === 'FAILED' && $p->error)
            <div class="rounded-md bg-danger-soft p-3">
                <p class="font-medium text-danger">{{ t('smm.ig.failedTitle') }}</p>
                <p class="mt-1 whitespace-pre-wrap break-words" data-testid="ig-error">{{ $p->error }}</p>
                @if ($fileThere)<p class="mt-1 text-xs text-muted-foreground">{{ t('smm.ig.failedKeep', ['days' => (int) config('fluggi.smm_media.failed_keep_days', 7)]) }}</p>@endif
            </div>
        @endif
        @if ($p && $p->permalink)
            <p class="min-w-0 truncate"><a href="{{ $p->permalink }}" class="link" target="_blank" rel="noopener noreferrer">{{ $p->permalink }}</a></p>
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
            </div>
        @endif

        @if ($editable)
            {{-- Загрузка частями: большие файлы с iPhone проходят при маленьком лимите загрузки хостинга --}}
            <div x-data="igUpload(@js(['start' => route('smm.publication.upload', $idea->id), 'base' => url('/smm/'.$idea->id.'/publication/uploads')]))" class="grid gap-2">
                <div class="flex flex-wrap gap-2">
                    <label class="btn btn-outline btn-sm cursor-pointer" :class="busy && 'pointer-events-none opacity-50'">
                        <x-icon name="upload" /> {{ $p && $p->media_name ? t('smm.ig.replaceMedia') : t('smm.ig.uploadMedia') }}
                        <input type="file" class="sr-only" accept="video/mp4,video/quicktime,.mp4,.mov,image/jpeg,image/png,.jpg,.jpeg,.png" @change="upload($event, 'media')">
                    </label>
                    @if ($p && $p->kind === 'REELS' && $fileThere)
                        <label class="btn btn-ghost btn-sm cursor-pointer" :class="busy && 'pointer-events-none opacity-50'">
                            <x-icon name="paperclip" /> {{ t('smm.ig.uploadCover') }}
                            <input type="file" class="sr-only" accept="image/jpeg,image/png,.jpg,.jpeg,.png" @change="upload($event, 'cover')">
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
                    @unless ($approved)<p class="text-xs text-warning">{{ t('smm.ig.errors.notApproved') }}</p>@endunless
                    <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                        <button name="intent" value="draft" class="btn btn-outline">{{ $p->status === 'SCHEDULED' ? t('smm.ig.unschedule') : t('smm.ig.saveDraft') }}</button>
                        <button name="intent" value="schedule" class="btn btn-primary" @disabled(! $approved || ! $fileThere)><x-icon name="calendar" /> {{ t('smm.ig.schedule') }}</button>
                    </div>
                </form>
                <div class="flex flex-wrap gap-2">
                    @if ($p->status === 'FAILED' && $fileThere && $approved)
                        <form method="POST" action="{{ route('smm.publication.retry', $idea->id) }}">@csrf<button class="btn btn-primary btn-sm"><x-icon name="refresh" /> {{ t('smm.ig.retry') }}</button></form>
                    @endif
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
