@extends('layouts.app')
@section('title', __('Принять оплату'))
@section('content')
<form method="POST" action="{{ route('payments.store') }}" class="card card-body max-w-xl space-y-4"
      x-data="paymentForm(@js($student ? ['id' => $student->id, 'name' => $student->full_name, 'phone' => $student->phone] : null), @js((string) old('method_id', $defaultMethod)))">
    @csrf
    {{-- 1. student --}}
    <div class="relative">
        <label class="label">1. {{ __('Ученик') }}</label>
        <input type="hidden" name="student_id" :value="student?.id">
        <template x-if="student">
            <div class="flex items-center justify-between rounded-lg border border-brand-200 bg-brand-50 px-3 py-2 dark:border-brand-900 dark:bg-brand-900/20">
                <div><div class="font-medium" x-text="student.name"></div><div class="text-xs text-slate-500" x-text="student.phone"></div></div>
                <button type="button" class="text-xs text-slate-500" @click="student = null; info = null">✕ {{ __('сменить') }}</button>
            </div>
        </template>
        <template x-if="!student">
            <div>
                <input type="search" x-model="q" @input.debounce.250ms="find()" class="input" placeholder="{{ __('Имя, телефон или ID ученика…') }}" autocomplete="off" autofocus>
                <div x-show="results.length" x-cloak class="absolute z-20 mt-1 w-full overflow-hidden rounded-lg border bg-white shadow-lg dark:border-slate-700 dark:bg-slate-900">
                    <template x-for="r in results" :key="r.id"><button type="button" @click="pick(r)" class="flex w-full justify-between px-3 py-2 text-left text-sm hover:bg-slate-50 dark:hover:bg-slate-800"><span x-text="r.title"></span><span class="text-xs text-slate-500" x-text="r.sub"></span></button></template>
                </div>
            </div>
        </template>
        @error('student_id')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
    </div>

    <div x-show="info" x-cloak class="rounded-lg bg-slate-50 p-3 text-sm dark:bg-slate-800">
        <div class="flex justify-between"><span class="text-slate-500">{{ __('Начислено / оплачено') }}</span><span x-text="fmt(info?.charged) + ' / ' + fmt(info?.paid)"></span></div>
        <div class="flex justify-between font-semibold"><span x-text="info?.balance > 0 ? '{{ __('Долг') }}' : '{{ __('Баланс') }}'"></span><span :class="info?.balance > 0 ? 'text-rose-600' : 'text-emerald-600'" x-text="fmt(Math.abs(info?.balance || 0)) + ' {{ __('сум') }}'"></span></div>
        <button type="button" x-show="info?.balance > 0" @click="amount = info.balance" class="mt-1 text-xs text-brand-600">{{ __('Подставить сумму долга') }}</button>
    </div>

    {{-- 2. amount --}}
    <div><label class="label">2. {{ __('Сумма') }}</label>
        <input type="number" name="amount" x-model="amount" inputmode="numeric" step="1" required class="input text-lg font-semibold" placeholder="0">
        <div class="mt-1.5 flex flex-wrap gap-1.5"><template x-for="v in [100000, 300000, 500000, 1000000]"><button type="button" @click="amount = v" class="btn-secondary btn-sm" x-text="fmt(v)"></button></template></div>
        @error('amount')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror</div>

    {{-- 3. method --}}
    <div><label class="label">3. {{ __('Способ оплаты') }}</label>
        <div class="flex flex-wrap gap-2">
        @foreach($methods as $id => $n)
            <label class="cursor-pointer"><input type="radio" name="method_id" value="{{ $id }}" x-model="method" class="peer sr-only"><span class="inline-block rounded-lg border px-3.5 py-2 text-sm peer-checked:border-brand-600 peer-checked:bg-brand-600 peer-checked:text-white dark:border-slate-700">{{ $n }}</span></label>
        @endforeach
        </div>@error('method_id')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror</div>

    <details class="text-sm" {{ $errors->hasAny(['group_id', 'paid_at', 'type']) ? 'open' : '' }}>
        <summary class="cursor-pointer text-slate-500">{{ __('Дополнительно') }}</summary>
        <div class="mt-3 space-y-3">
            <div><label class="label">{{ __('Группа') }} <span class="text-slate-400">({{ __('авто — по долгу') }})</span></label>
                <select name="group_id" class="input"><option value="">{{ __('Определить автоматически') }}</option><template x-for="g in (info?.groups || [])" :key="g.group_id"><option :value="g.group_id" x-text="g.name + (g.balance > 0 ? ' — ' + fmt(g.balance) : '')"></option></template></select>
                @error('group_id')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror</div>
            <div><label class="label">{{ __('Дата и время') }}</label><input type="datetime-local" name="paid_at" max="{{ now()->format('Y-m-d\TH:i') }}" class="input"></div>
            <div><label class="label">{{ __('Комментарий') }}</label><input name="comment" maxlength="255" class="input"></div>
            @if($canCorrect)
            <div><label class="label">{{ __('Тип операции') }}</label><select name="type" class="input"><option value="payment">{{ __('Оплата') }}</option><option value="refund">{{ __('Возврат (сумма положительная)') }}</option><option value="correction">{{ __('Коррекция (+/−)') }}</option></select></div>
            @endif
        </div>
    </details>

    <button class="btn-primary w-full py-3 text-base" :disabled="!student || !amount">{{ __('Сохранить оплату') }}</button>
</form>
@endsection
@push('scripts')
<script>
function paymentForm(initial, method) {
    return {
        student: initial, q: '', results: [], info: null, amount: '', method,
        init() { if (this.student) this.load(); },
        fmt(n) { return new Intl.NumberFormat('ru-RU').format(Math.round(n || 0)); },
        async find() {
            if (this.q.trim().length < 2) { this.results = []; return; }
            const r = await fetch('{{ route('search.json') }}?q=' + encodeURIComponent(this.q), { headers: { Accept: 'application/json' } });
            this.results = ((await r.json()).results.students || []).map(s => ({ id: s.id, title: s.title, sub: s.sub }));
        },
        pick(r) { this.student = { id: r.id, name: r.title, phone: r.sub }; this.results = []; this.q = ''; this.load(); },
        async load() {
            const r = await fetch('{{ url('finance/students') }}/' + this.student.id + '/balance', { headers: { Accept: 'application/json' } });
            if (r.ok) { this.info = await r.json(); if (!this.amount && this.info.balance > 0) this.amount = this.info.balance; }
        },
    };
}
</script>
@endpush
