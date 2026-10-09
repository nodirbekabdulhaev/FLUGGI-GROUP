@props(['name', 'label' => null, 'type' => 'text', 'value' => null, 'hint' => null, 'required' => false])
{{-- Поле формы с подписью и ошибкой валидации: <x-field name="title" :label="t('leads.title')" :value="$lead->title" required /> --}}
@php $id = $attributes->get('id', 'f-'.str_replace(['[', ']', '.'], '-', $name)); $err = $errors->first(str_replace(['[', ']'], ['.', ''], $name)); @endphp
<div {{ $attributes->only('class') }}>
    @if ($label)
        <label for="{{ $id }}" class="label">{{ $label }}@if ($required)<span class="text-danger"> *</span>@endif</label>
    @endif
    @if ($type === 'textarea')
        <textarea id="{{ $id }}" name="{{ $name }}" rows="{{ $attributes->get('rows', 3) }}" @required($required) {{ $attributes->except(['class', 'id', 'rows'])->merge(['class' => 'input']) }} @class(['border-danger' => $err])>{{ old($name, $value) }}</textarea>
    @else
        <input id="{{ $id }}" name="{{ $name }}" type="{{ $type }}" value="{{ $type === 'password' ? '' : old($name, $value) }}" @required($required) {{ $attributes->except(['class', 'id'])->merge(['class' => 'input'.($err ? ' border-danger' : '')]) }}>
    @endif
    @if ($err)
        <p class="error">{{ $err }}</p>
    @elseif ($hint)
        <p class="hint">{{ $hint }}</p>
    @endif
</div>
