{{-- Generic form field. $f: name,label,type,options,hint ; $value current value --}}
@props(['f', 'value' => null])
@php
 $name = $f['name']; $type = $f['type'] ?? 'text';
 $opts = $f['options'] ?? []; if ($opts instanceof Closure) $opts = $opts();
 $id = 'f_'.str_replace(['[', ']', '.'], '_', $name);
 $old = old($name, $value);
 $rules = $f['rules'] ?? ''; if ($rules instanceof Closure) $rules = $rules(null);
 $rulesStr = is_array($rules) ? implode('|', array_filter($rules, 'is_string')) : (string) $rules;
@endphp
<div class="{{ $f['span'] ?? '' }}">
 @if($type !== 'checkbox')<label for="{{ $id }}" class="label">{{ __($f['label']) }}@if(str_contains($rulesStr, 'required') && ! str_contains($rulesStr, 'required_without'))<span class="text-rose-500"> *</span>@endif</label>@endif
 @switch($type)
  @case('textarea')
   <textarea id="{{ $id }}" name="{{ $name }}" rows="{{ $f['rows'] ?? 3 }}" class="input">{{ $old }}</textarea> @break
  @case('select')
   <select id="{{ $id }}" name="{{ $name }}" class="input">
    @if($f['blank'] ?? true)<option value="">—</option>@endif
    @foreach($opts as $k => $v)<option value="{{ $k }}" @selected((string)$old === (string)$k)>{{ __($v) }}</option>@endforeach
   </select> @break
  @case('multiselect')
   @php $sel = collect(old($name, $value ?? []))->map(fn($x) => (string)$x)->all(); @endphp
   <select id="{{ $id }}" name="{{ $name }}[]" multiple size="{{ min(6, max(3, count($opts))) }}" class="input">
    @foreach($opts as $k => $v)<option value="{{ $k }}" @selected(in_array((string)$k, $sel, true))>{{ $v }}</option>@endforeach
   </select> @break
  @case('checkbox')
   <label class="mt-5 inline-flex items-center gap-2 text-sm"><input type="hidden" name="{{ $name }}" value="0"><input type="checkbox" name="{{ $name }}" value="1" @checked((bool)$old) class="rounded border-slate-300 text-brand-600"> {{ __($f['label']) }}</label> @break
  @case('file')
   <input id="{{ $id }}" type="file" name="{{ $name }}" accept="{{ $f['accept'] ?? '.jpg,.jpeg,.png,.webp,.pdf' }}" class="input"> @break
  @case('money')
   <input id="{{ $id }}" type="number" step="1000" min="0" name="{{ $name }}" value="{{ $old }}" class="input"> @break
  @default
   <input id="{{ $id }}" type="{{ $type }}" name="{{ $name }}" value="{{ $old }}" @if(isset($f['step']))step="{{ $f['step'] }}"@endif @if(isset($f['placeholder']))placeholder="{{ $f['placeholder'] }}"@endif class="input" @if($type==='password')autocomplete="new-password"@endif>
 @endswitch
 @isset($f['hint'])<p class="mt-1 text-xs text-slate-500">{{ __($f['hint']) }}</p>@endisset
 @error($name)<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
</div>
