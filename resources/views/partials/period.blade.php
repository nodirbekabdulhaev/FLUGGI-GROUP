{{-- Period picker. Expects $key, $from, $to. Extra hidden inputs may be passed as $keep (array). --}}
<form method="GET" class="mb-4 flex flex-wrap items-end gap-2" x-data="{ k: '{{ $key }}' }">
    @foreach(($keep ?? request()->except(['period', 'from', 'to', 'page'])) as $n => $v)@if(is_scalar($v))<input type="hidden" name="{{ $n }}" value="{{ $v }}">@endif @endforeach
    <select name="period" x-model="k" class="input !w-auto">@foreach(\App\Support\Period::LABELS as $pk => $pl)<option value="{{ $pk }}">{{ __($pl) }}</option>@endforeach</select>
    <div x-show="k === 'custom'" x-cloak class="flex gap-2"><input type="date" name="from" value="{{ $from->toDateString() }}" class="input !w-auto"><input type="date" name="to" value="{{ $to->toDateString() }}" class="input !w-auto"></div>
    <button class="btn-primary">{{ __('Показать') }}</button>
    <span class="ml-auto text-xs text-slate-500">{{ $from->format('d.m.Y') }} — {{ $to->format('d.m.Y') }}</span>
</form>
