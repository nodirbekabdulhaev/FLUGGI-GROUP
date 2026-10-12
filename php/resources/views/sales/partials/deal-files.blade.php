{{-- Вкладка «Файлы» карточки сделки ($deal): загрузка с проверкой типа по содержимому, скачивание через проверку прав. --}}
@if (can('deal.read'))
    @php $files = \App\Services\Sales\Panels::files($deal); @endphp
    <div class="grid gap-4">
        @if (can('deal.update'))
            <form method="POST" action="{{ route('sales.files.store') }}" enctype="multipart/form-data" class="flex flex-wrap items-center gap-3" x-data>
                @csrf
                <input type="hidden" name="_idem" value="{{ \Illuminate\Support\Str::random(32) }}">
                <input type="hidden" name="deal_id" value="{{ $deal->id }}">
                <input type="hidden" name="category" value="DOCUMENT">
                <label class="btn btn-primary btn-sm cursor-pointer">
                    <x-icon name="upload" /> {{ t('sales.files.upload') }}
                    <input type="file" name="file" class="sr-only" accept=".pdf,.png,.jpg,.jpeg,.webp,.docx,.xlsx,.zip" x-on:change="$el.form.submit()">
                </label>
                <span class="text-xs text-muted-foreground">{{ t('sales.files.hint') }}</span>
                @error('file')<p class="error w-full">{{ $message }}</p>@enderror
            </form>
        @endif
        @if ($files->isEmpty())
            <x-empty icon="paperclip" :title="t('sales.files.empty')" />
        @else
            <ul class="divide-y rounded-lg border">
                @foreach ($files as $f)
                    <li class="flex flex-wrap items-center gap-3 px-4 py-2.5 text-sm">
                        <x-icon name="paperclip" class="size-4 text-muted-foreground" />
                        <span class="min-w-0 flex-1 truncate">{{ $f->original_name }}</span>
                        <span class="text-xs text-muted-foreground">{{ t('sales.files.meta', ['size' => max(1, (int) round($f->size_bytes / 1024)), 'name' => $f->uploadedBy?->full_name ?? '—', 'date' => d($f->created_at)]) }}</span>
                        <a class="btn btn-ghost btn-icon" href="{{ route('sales.files.download', $f->id) }}" aria-label="{{ t('sales.files.download') }}"><x-icon name="download" /></a>
                        @if (can('deal.update'))
                            <form method="POST" action="{{ route('sales.files.destroy', $f->id) }}" data-confirm="{{ t('sales.files.deleteConfirm') }}">@csrf @method('DELETE')
                                <button class="btn btn-ghost btn-icon" aria-label="{{ t('common.delete') }}"><x-icon name="trash" /></button>
                            </form>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
@endif
