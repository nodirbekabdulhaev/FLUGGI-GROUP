{{-- Список «поле — значение»: $rows = [[подпись, значение(html-safe string|null)], …]; пустые строки скрываются --}}
<dl class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
    @foreach ($rows as [$label, $value])
        @if ($value !== null && $value !== '')
            <div class="min-w-0">
                <dt class="text-xs text-muted-foreground">{{ $label }}</dt>
                <dd class="mt-0.5 break-words">{!! $value instanceof \Illuminate\Support\HtmlString ? $value : e($value) !!}</dd>
            </div>
        @endif
    @endforeach
</dl>
