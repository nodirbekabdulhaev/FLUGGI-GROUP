@extends('layouts.app')
@section('title', t('inbox.title'))
@php
    $who = fn ($t) => $t->peer_username ? '@'.$t->peer_username : ($t->peer_name ?? $t->peer_id);
    $items = $list['items'];
@endphp
@section('content')
    <x-page-header :title="t('inbox.title')" :subtitle="t('inbox.subtitle')" />
    <div class="card grid h-[calc(100dvh-14rem)] min-h-[420px] overflow-hidden md:grid-cols-[22rem_1fr]">
        <div @class(['flex min-h-0 flex-col border-r', 'max-md:hidden' => $active])>
            <form method="GET" class="border-b p-3">
                <select name="channel" class="input" aria-label="{{ t('inbox.channel') }}" onchange="this.form.submit()">
                    <option value="">{{ t('inbox.allChannels') }}</option>
                    @foreach (\App\Services\Integrations\InboxService::CHANNELS as $c)<option value="{{ $c }}" @selected($channel === $c)>{{ t('inbox.channels.'.$c) }}</option>@endforeach
                </select>
            </form>
            @if ($items->isEmpty())
                <x-empty icon="at" :title="t('inbox.empty')">{{ t('inbox.emptyText') }}</x-empty>
            @else
                <ul class="min-h-0 flex-1 divide-y overflow-y-auto">
                    @foreach ($items as $th)
                        @php($last = $list['last']->get($th->id))
                        <li>
                            <a href="{{ route('inbox', array_filter(['channel' => $channel, 'thread' => $th->id])) }}" @class(['flex gap-3 px-4 py-3 hover:bg-muted/50', 'bg-muted' => $active?->id === $th->id])>
                                <x-icon :name="$th->channel === 'INSTAGRAM_DM' ? 'message' : 'instagram'" class="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                                <span class="min-w-0 flex-1">
                                    <span class="flex items-baseline justify-between gap-2"><span class="truncate text-sm font-medium">{{ $who($th) }}</span>
                                        <span class="shrink-0 text-[11px] text-muted-foreground">{{ dt($th->last_message_at) }}</span></span>
                                    <span class="flex items-center justify-between gap-2">
                                        <span class="truncate text-xs text-muted-foreground">@if ($last){{ $last->direction === 'OUT' ? t('inbox.you').': ' : '' }}{{ $last->text }}@endif</span>
                                        @if ($th->unread)<span class="shrink-0 rounded-full bg-accent px-1.5 text-[11px] font-medium text-white">{{ $th->unread }}</span>
                                        @elseif ($th->lead_id)<x-badge color="green">{{ t('inbox.lead') }}</x-badge>@endif
                                    </span>
                                </span>
                            </a>
                        </li>
                    @endforeach
                </ul>
                @if ($items->hasPages())<div class="border-t px-3 py-2">{{ $items->links('vendor.pagination.fluggi') }}</div>@endif
            @endif
        </div>
        <div @class(['min-h-0', 'max-md:hidden' => ! $active])>
            @if ($active)
                @php($isDm = $active->channel === 'INSTAGRAM_DM')
                <div class="flex h-full min-h-0 flex-col" x-data="inboxThread(@js(route('inbox.messages', $active->id)), @js(route('inbox.reply', $active->id)), @js($isDm))">
                    <header class="flex flex-wrap items-center gap-2 border-b px-4 py-3">
                        <a href="{{ route('inbox', array_filter(['channel' => $channel])) }}" class="rounded p-1 hover:bg-muted md:hidden" aria-label="{{ t('inbox.back') }}"><x-icon name="arrow-left" /></a>
                        <div class="min-w-0 flex-1">
                            <p class="truncate font-medium">{{ $who($active) }}</p>
                            <p class="text-xs text-muted-foreground">{{ t('inbox.channels.'.$active->channel) }}@if ($active->owner) · {{ $active->owner->full_name }}@endif</p>
                        </div>
                        @if ($active->lead)
                            <a href="/sales/leads/{{ $active->lead->id }}" class="link text-sm">{{ \App\Support\Format::code('L', $active->lead->number) }}</a>
                        @elseif (can('lead.create'))
                            <form method="POST" action="{{ route('inbox.lead', $active->id) }}">@csrf<button class="btn btn-outline btn-sm"><x-icon name="plus" /> {{ t('inbox.createLead') }}</button></form>
                        @endif
                    </header>
                    <div class="min-h-0 flex-1 overflow-y-auto px-4 py-3" x-ref="box">
                        <p x-show="!loaded" class="py-6 text-center text-sm text-muted-foreground">{{ t('common.loading') }}</p>
                        <ul class="grid gap-2">
                            <template x-for="m in messages" :key="m.id">
                                <li class="flex" :class="m.direction === 'OUT' ? 'justify-end' : 'justify-start'">
                                    <button type="button" :disabled="isDm || m.direction === 'OUT' || !m.externalId" @click="commentId = m.externalId"
                                            class="max-w-[80%] rounded-2xl px-3 py-2 text-left text-sm"
                                            :class="[m.direction === 'OUT' ? 'rounded-br-sm bg-accent text-white' : 'rounded-bl-sm bg-muted', !isDm && m.direction === 'IN' && m.externalId === commentId ? 'ring-2 ring-accent' : '']">
                                        <span class="block whitespace-pre-wrap break-words" x-text="m.text"></span>
                                        <span class="mt-0.5 block text-right text-[10px]" :class="m.direction === 'OUT' ? 'text-white/70' : 'text-muted-foreground'" x-text="(m.author ? m.author + ' · ' : '') + m.createdAt"></span>
                                    </button>
                                </li>
                            </template>
                        </ul>
                    </div>
                    @if (can('lead.update'))
                        <form class="grid gap-2 border-t p-3" @submit.prevent="send()">
                            <div x-show="!isDm" class="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                                {{ t('inbox.replyTo') }}
                                <select class="input h-8 w-48 text-xs" x-model="mode" aria-label="{{ t('inbox.replyMode') }}">
                                    <option value="public">{{ t('inbox.modePublic') }}</option><option value="private">{{ t('inbox.modePrivate') }}</option>
                                </select>
                                <span x-show="!commentId" class="text-danger">{{ t('inbox.pickComment') }}</span>
                            </div>
                            <div class="flex gap-2">
                                <textarea class="input" rows="2" maxlength="1000" x-model="text" aria-label="{{ t('inbox.message') }}" placeholder="{{ t('inbox.placeholder') }}"></textarea>
                                <button type="submit" class="btn btn-primary shrink-0" aria-label="{{ t('inbox.send') }}" :disabled="busy || !text.trim() || (!isDm && !commentId)"><x-icon name="send" /></button>
                            </div>
                            <p class="text-[11px] text-muted-foreground">{{ t('inbox.windowHint') }}</p>
                        </form>
                    @endif
                </div>
            @else
                <div class="flex h-full items-center justify-center p-6 text-center text-sm text-muted-foreground">{{ t('inbox.pick') }}</div>
            @endif
        </div>
    </div>
    <script>
    document.addEventListener('alpine:init', () => {
        Alpine.data('inboxThread', (url, replyUrl, isDm) => ({
            messages: [], loaded: false, text: '', commentId: null, mode: 'public', busy: false, isDm,
            async init() { await this.load(); setInterval(() => { if (!document.hidden) this.load(); }, 20000); },
            async load() {
                try {
                    const rows = await window.api(url);
                    const grew = rows.length !== this.messages.length;
                    this.messages = rows; this.loaded = true;
                    // По умолчанию отвечаем на последний комментарий клиента
                    if (!this.isDm && !this.commentId) this.commentId = [...rows].reverse().find((m) => m.direction === 'IN' && m.externalId)?.externalId ?? null;
                    if (grew) this.$nextTick(() => { this.$refs.box.scrollTop = this.$refs.box.scrollHeight; });
                } catch (e) { window.toast(e.message, 'error'); }
            },
            async send() {
                this.busy = true;
                try {
                    this.messages = await window.api(replyUrl, { method: 'POST', body: { text: this.text, commentId: this.isDm ? null : this.commentId, mode: this.mode } });
                    this.text = '';
                    this.$nextTick(() => { this.$refs.box.scrollTop = this.$refs.box.scrollHeight; });
                } catch (e) { window.toast(e.message, 'error'); } finally { this.busy = false; }
            },
        }));
    });
    </script>
@endsection
