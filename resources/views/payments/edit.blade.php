@extends('layouts.app')
@section('title', __('Оплата').' #'.$payment->id)
@section('content')
<form method="POST" action="{{ route('payments.update', $payment) }}" class="card card-body max-w-xl space-y-4">@csrf @method('PUT')
    <div class="text-sm">{{ __('Ученик') }}: <a class="link font-medium" href="{{ route('students.show', $payment->student_id) }}">{{ $payment->student?->full_name }}</a> · {{ __(\App\Models\Payment::TYPES[$payment->type]) }}</div>
    <div><label class="label">{{ __('Сумма') }}</label><input type="number" name="amount" value="{{ old('amount', $payment->amount) }}" class="input" required>@error('amount')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror</div>
    <div><label class="label">{{ __('Способ оплаты') }}</label><select name="method_id" class="input">@foreach($methods as $id => $n)<option value="{{ $id }}" @selected(old('method_id', $payment->method_id) == $id)>{{ $n }}</option>@endforeach</select></div>
    <div><label class="label">{{ __('Дата и время') }}</label><input type="datetime-local" name="paid_at" value="{{ old('paid_at', $payment->paid_at->format('Y-m-d\TH:i')) }}" class="input" required></div>
    <div><label class="label">{{ __('Комментарий') }}</label><input name="comment" value="{{ old('comment', $payment->comment) }}" class="input"></div>
    <p class="text-xs text-slate-500">{{ __('Изменение фиксируется в истории действий (старое → новое значение).') }}</p>
    <div class="flex gap-2"><button class="btn-primary">{{ __('Сохранить') }}</button><a href="{{ route('payments.index') }}" class="btn-secondary">{{ __('Отмена') }}</a></div>
</form>
@endsection
