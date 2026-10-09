{{-- Сообщения: session('ok') / session('error') после редиректа и window.toast() из JS --}}
<div x-data="toasts" x-init="
        @if (session('ok')) push({ text: @js(session('ok')), type: 'ok' }); @endif
        @if (session('error')) push({ text: @js(session('error')), type: 'error' }); @endif
     " @toast.window="push($event.detail)"
     class="pointer-events-none fixed inset-x-0 bottom-4 z-[60] flex flex-col items-center gap-2 px-4 sm:bottom-6">
    <template x-for="item in items" :key="item.id">
        <div class="pointer-events-auto max-w-md rounded-md px-4 py-2.5 text-sm shadow-lg"
             :class="item.type === 'error' ? 'bg-danger text-white' : 'bg-primary text-primary-foreground'" x-text="item.text"></div>
    </template>
</div>
