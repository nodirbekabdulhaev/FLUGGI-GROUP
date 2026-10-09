@if ($paginator->hasPages() || $paginator->total() > 0)
    <nav class="flex items-center justify-between gap-2 text-sm" aria-label="pagination">
        <span class="text-muted-foreground">{{ t('common.pageOf', ['from' => $paginator->firstItem() ?? 0, 'to' => $paginator->lastItem() ?? 0, 'total' => $paginator->total()]) }}</span>
        <div class="flex gap-1">
            @if ($paginator->onFirstPage())
                <span class="btn btn-outline btn-sm opacity-50">{{ t('common.prev') }}</span>
            @else
                <a class="btn btn-outline btn-sm" href="{{ $paginator->previousPageUrl() }}" rel="prev">{{ t('common.prev') }}</a>
            @endif
            @if ($paginator->hasMorePages())
                <a class="btn btn-outline btn-sm" href="{{ $paginator->nextPageUrl() }}" rel="next">{{ t('common.next') }}</a>
            @else
                <span class="btn btn-outline btn-sm opacity-50">{{ t('common.next') }}</span>
            @endif
        </div>
    </nav>
@endif
