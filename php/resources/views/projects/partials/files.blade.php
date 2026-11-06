{{-- Файлы проекта или задачи (отчёты, макеты, документы). Загрузка, скачивание, удаление — модуль файлов sales.files.* --}}
<div class="grid gap-3">
    @php $projectId = $owner['project_id'] ?? \App\Models\Task::whereKey($owner['task_id'] ?? null)->value('project_id'); @endphp
    <form method="POST" action="{{ route('sales.files.store') }}" enctype="multipart/form-data" class="flex flex-wrap items-center gap-3">
            @csrf
            @foreach ($owner as $k => $v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
            <input type="hidden" name="category" value="DOCUMENT">
            <input type="file" name="file" required class="max-w-full text-sm" accept=".pdf,.png,.jpg,.jpeg,.webp,.docx,.xlsx,.zip">
            <button class="btn btn-outline btn-sm"><x-icon name="upload" /> {{ t('sales.files.upload') }}</button>
            <span class="text-xs text-muted-foreground">{{ t('sales.files.hint') }}</span>
    </form>
    @if ($files->isEmpty())
        <p class="text-sm text-muted-foreground">{{ t('sales.files.empty') }}</p>
    @else
        <ul class="divide-y rounded-lg border">
            @foreach ($files as $f)
                <li class="flex items-center gap-3 px-3 py-2 text-sm">
                    <x-icon name="paperclip" class="size-4 text-muted-foreground" />
                    <span class="min-w-0 flex-1 truncate">{{ $f->original_name }}</span>
                    <span class="hidden text-xs text-muted-foreground sm:inline">{{ number_format($f->size_bytes / 1024, 0, ',', ' ') }} KB · {{ $f->uploadedBy?->full_name }} · {{ d($f->created_at) }}</span>
                    <a href="{{ route('sales.files.download', $f->id) }}" class="btn btn-ghost btn-icon" aria-label="{{ t('sales.files.download') }}"><x-icon name="download" /></a>
                    @if ($f->uploaded_by_id === access()->id() || ($projectId && \App\Services\Projects\ProjectAccess::can($projectId, 'project.update')))
                        <form method="POST" action="{{ route('sales.files.destroy', $f->id) }}" data-confirm="{{ t('sales.files.deleteConfirm') }}">
                            @csrf @method('DELETE')
                            <button class="btn btn-ghost btn-icon text-danger" aria-label="{{ t('common.delete') }}"><x-icon name="trash" /></button>
                        </form>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</div>
