@props(['name', 'label' => null, 'options' => [], 'value' => null, 'placeholder' => null, 'required' => false, 'hint' => null])
{{-- Список: options — [значение => подпись]. <x-select name="source_id" :options="$sources" :value="$lead->source_id" /> --}}
@php $id = $attributes->get('id', 'f-'.str_replace(['[', ']', '.'], '-', $name)); $err = $errors->first(str_replace(['[', ']'], ['.', ''], $name)); $current = (string) old($name, $value); @endphp
<div {{ $attributes->only('class') }}>
    @if ($label)
        <label for="{{ $id }}" class="label">{{ $label }}@if ($required)<span class="text-danger"> *</span>@endif</label>
    @endif
    <select id="{{ $id }}" name="{{ $name }}" @required($required) {{ $attributes->except(['class', 'id'])->merge(['class' => 'input'.($err ? ' border-danger' : '')]) }}>
        @if ($placeholder !== null)<option value="">{{ $placeholder }}</option>@endif
        @foreach ($options as $key => $text)
            <option value="{{ $key }}" @selected($current === (string) $key)>{{ $text }}</option>
        @endforeach
    </select>
    @if ($err)<p class="error">{{ $err }}</p>@elseif ($hint)<p class="hint">{{ $hint }}</p>@endif
</div>
