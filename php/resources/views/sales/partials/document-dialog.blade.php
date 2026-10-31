{{--
  Предпросмотр документа (КП или договор) и скачивание: Word, PDF, печать, копирование текста, TXT.
  Открыть: $dispatch('open-doc', {kind: 'proposals', id: '…', title: '…'}). Документ формирует сервер с проверкой прав.
--}}
@once
<div x-data="salesDocument()" x-on:open-doc.window="open($event.detail)" x-on:keydown.escape.window="show = false"
     x-show="show" x-cloak class="fixed inset-0 z-50 flex items-end justify-center sm:items-center sm:p-4" role="dialog" aria-modal="true">
    <div class="absolute inset-0 bg-black/40" x-on:click="show = false"></div>
    <div class="relative flex max-h-[96dvh] w-full max-w-5xl flex-col overflow-hidden rounded-t-xl bg-surface shadow-xl sm:rounded-xl">
        <header class="flex items-center justify-between gap-2 border-b px-4 py-3 sm:px-5">
            <h2 class="min-w-0 truncate font-semibold" x-text="title"></h2>
            <button type="button" class="btn btn-ghost btn-icon" x-on:click="show = false" aria-label="{{ t('common.close') }}"><x-icon name="x" /></button>
        </header>
        <div class="flex flex-col gap-3 overflow-y-auto p-4 sm:p-5">
            <div class="flex flex-wrap gap-2">
                <a class="btn btn-primary btn-sm" :href="url('docx', true)"><x-icon name="download" /> {{ t('documents.word') }}</a>
                <a class="btn btn-outline btn-sm" :href="url('pdf', true)"><x-icon name="download" /> {{ t('documents.pdf') }}</a>
                <button type="button" class="btn btn-outline btn-sm" x-on:click="$refs.frame.contentWindow.print()"><x-icon name="file" /> {{ t('documents.print') }}</button>
                <button type="button" class="btn btn-outline btn-sm" x-on:click="copy()"><x-icon name="copy" /> {{ t('documents.copy') }}</button>
                <a class="btn btn-ghost btn-sm" :href="url('txt', true)"><x-icon name="file" /> {{ t('documents.txt') }}</a>
            </div>
            <template x-if="missing.length">
                <div class="flex gap-2 rounded-md border border-warning/40 bg-warning-soft p-3 text-sm">
                    <x-icon name="alert" class="mt-0.5 size-4 shrink-0 text-warning" />
                    <div>
                        <p class="font-medium">{{ t('documents.missingTitle') }}</p>
                        <ul class="list-disc pl-5"><template x-for="m in missing" :key="m"><li x-text="m"></li></template></ul>
                    </div>
                </div>
            </template>
            <iframe x-ref="frame" :src="show ? url('html') : 'about:blank'" title="{{ t('documents.preview') }}"
                    sandbox="allow-modals allow-same-origin" class="h-[60vh] w-full rounded-md border bg-muted"></iframe>
        </div>
    </div>
</div>
@push('scripts')
<script>
    function salesDocument() {
        return {
            show: false, kind: '', id: '', title: '', missing: [],
            url(format, download = false) {
                return this.id ? `/documents/${this.kind}/${this.id}?format=${format}${download ? '&download=true' : ''}` : 'about:blank';
            },
            async open(d) {
                Object.assign(this, { kind: d.kind, id: d.id, title: d.title, missing: [], show: true });
                try { this.missing = (await window.api(`/documents/${d.kind}/${d.id}/check`)).missing; } catch (e) { window.toast(e.message, 'error'); }
            },
            async copy() {
                try {
                    const res = await fetch(this.url('txt'), { credentials: 'same-origin' });
                    if (!res.ok) throw new Error(@js(t('documents.failed')));
                    await navigator.clipboard.writeText(await res.text());
                    window.toast(@js(t('documents.copied')));
                } catch (e) { window.toast(e.message, 'error'); }
            },
        };
    }
</script>
@endpush
@endonce
