@props(['name' => 'roles', 'selected' => [], 'model' => null, 'label' => null, 'id' => null, 'hint' => null])
{{--
  Выбор нескольких позиций (специальностей) — плитки-флажки, удобно на телефоне.
  Отправляется name[] в порядке выбора: первая выбранная — основная позиция.
  model — имя массива во внешнем x-data (для подстановки значений при смене сотрудника), иначе — свой x-data.
--}}
@php
    $id ??= 'sp-'.substr(md5($name.uniqid('', true)), 0, 8);
    $options = collect(\App\Services\Projects\ProjectAccess::SPECIALTIES)->mapWithKeys(fn ($s) => [$s => t('specialties.'.$s)])->all();
    $var = $model ?? 'sel';
@endphp
<fieldset @if (! $model) x-data="{ sel: @js(array_values($selected)) }" @endif class="grid gap-2" aria-describedby="{{ $id }}-hint">
    <legend class="label">{{ $label ?? t('projects.team.positions') }}</legend>
    <input type="hidden" name="{{ $name }}[]" value="">
    <template x-for="s in {{ $var }}" :key="s"><input type="hidden" name="{{ $name }}[]" :value="s"></template>
    <div class="flex flex-wrap gap-2">
        @foreach ($options as $k => $text)
            <label class="inline-flex cursor-pointer select-none items-center gap-2 rounded-full border px-3 py-1.5 text-sm has-[:checked]:border-accent has-[:checked]:bg-accent-soft">
                <input type="checkbox" value="{{ $k }}" x-model="{{ $var }}" id="{{ $id }}-{{ $k }}">
                <span>{{ $text }}</span>
                <span class="text-xs text-accent" x-show="{{ $var }}.length > 1 && {{ $var }}[0] === '{{ $k }}'" x-cloak>· {{ t('projects.team.mainPosition') }}</span>
            </label>
        @endforeach
    </div>
    <p id="{{ $id }}-hint" class="text-xs text-muted-foreground">{{ $hint ?? t('projects.team.positionsHint') }}</p>
    @error($name)<p class="error">{{ $message }}</p>@enderror
    @error($name.'.*')<p class="error">{{ $message }}</p>@enderror
</fieldset>
