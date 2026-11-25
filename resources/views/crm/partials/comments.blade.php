{{-- Комментарии: $comments, $target = ['lead_id' => …] --}}
<div class="grid gap-4">
    <form method="POST" action="{{ route('crm.comments.store') }}" class="grid gap-2">
        @csrf
        @foreach ($target as $k => $v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
        <textarea name="body" rows="3" class="input h-auto py-2" required maxlength="4000" placeholder="{{ t('crm.common.commentPlaceholder') }}" aria-label="{{ t('crm.common.addComment') }}"></textarea>
        @error('body')<p class="error">{{ $message }}</p>@enderror
        <div><button type="submit" class="btn btn-primary btn-sm"><x-icon name="message" /> {{ t('crm.common.send') }}</button></div>
    </form>
    @if ($comments->isEmpty())
        <x-empty :title="t('crm.common.noComments')" />
    @else
        <ul class="grid gap-3">
            @foreach ($comments as $c)
                <li class="rounded-lg border p-3 text-sm">
                    <div class="mb-1 flex items-center justify-between gap-2 text-xs text-muted-foreground">
                        <span><span class="font-medium text-foreground">{{ $c->author?->full_name }}</span> · {{ dt($c->created_at) }}</span>
                        @if ($c->author_id === access()->id())
                            <form method="POST" action="{{ route('crm.comments.destroy', $c->id) }}" data-confirm="{{ t('crm.common.deleteCommentConfirm') }}">
                                @csrf @method('DELETE')
                                <button type="submit" class="btn btn-ghost btn-icon h-7 w-7" aria-label="{{ t('crm.common.delete') }}"><x-icon name="trash" /></button>
                            </form>
                        @endif
                    </div>
                    <p class="whitespace-pre-wrap break-words">{{ $c->body }}</p>
                </li>
            @endforeach
        </ul>
    @endif
</div>
