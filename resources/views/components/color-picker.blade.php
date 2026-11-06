@props(['name', 'value' => null, 'disabled' => false, 'label' => null, 'compact' => false])
{{--
  Выбор цвета: готовые пастельные цвета (App\Support\BoardColors::PALETTE) и свой HEX.
  <x-color-picker name="color" :value="$stage->color" :disabled="! $canEdit" compact />
  compact — образцы во всплывающей панели (для строк таблиц), иначе — сразу на странице.
  Ближайший родитель с data-color-preview получает выбранный цвет в --col (предпросмотр колонки).
--}}
@php
    $palette = \App\Support\BoardColors::PALETTE;
    $current = strtolower((string) old(str_replace(['[', ']'], ['.', ''], $name), $value ?: $palette[0]));
    $id = $attributes->get('id', 'cp-'.\Illuminate\Support\Str::random(6));
@endphp
<div {{ $attributes->except('id')->merge(['class' => 'relative']) }}
     x-data="{ c: @js($current), open: false, hex: @js($current),
               set(v) { this.c = v.toLowerCase(); this.hex = this.c; },
               typed() { const v = this.hex.trim(); if (/^#[0-9a-fA-F]{6}$/.test(v)) this.c = v.toLowerCase(); } }"
     x-effect="$el.closest('[data-color-preview]')?.style.setProperty('--col', c)"
     @click.outside="open = false" @keydown.escape="open = false">
    <input type="hidden" name="{{ $name }}" :value="c" value="{{ $current }}" @disabled($disabled)>
    @if ($compact)
        <button type="button" id="{{ $id }}" class="flex h-8 items-center gap-2 rounded-full border border-white bg-white/90 pl-1 pr-2.5 text-xs shadow-soft disabled:opacity-60"
                @click="open = !open" :aria-expanded="open" aria-label="{{ $label ?? t('ui.color.label') }}" @disabled($disabled)>
            <span class="size-6 rounded-full ring-1 ring-black/5" :style="'background-color:' + c" style="background-color: {{ $current }}"></span>
            <span class="font-mono uppercase text-muted-foreground" x-text="c">{{ $current }}</span>
        </button>
    @endif
    <div @if ($compact) x-show="open" x-cloak x-transition.opacity class="popover absolute left-0 top-full z-30 mt-2 w-64 p-3" @else class="grid gap-2" @endif>
        @if (! $compact && $label)<p class="label mb-0">{{ $label }}</p>@endif
        <p @class(['text-xs text-muted-foreground', 'mb-2' => $compact])>{{ t('ui.color.presets') }}</p>
        <div class="flex flex-wrap gap-1.5" role="radiogroup" aria-label="{{ t('ui.color.presets') }}">
            @foreach ($palette as $hex)
                <button type="button" role="radio" class="flex size-7 items-center justify-center rounded-full ring-1 ring-black/5 transition-transform hover:scale-110 disabled:pointer-events-none"
                        style="background-color: {{ $hex }}" :aria-checked="c === '{{ $hex }}'" aria-checked="{{ $current === $hex ? 'true' : 'false' }}"
                        :class="c === '{{ $hex }}' && 'ring-2 ring-offset-2 ring-foreground/60'" @click="set('{{ $hex }}'); open = false"
                        aria-label="{{ t('ui.color.choose', ['color' => $hex]) }}" @disabled($disabled)>
                    <x-icon name="check" class="size-3.5 text-white drop-shadow" x-show="c === '{{ $hex }}'" />
                </button>
            @endforeach
        </div>
        <label for="{{ $id }}-hex" @class(['text-xs text-muted-foreground', 'mt-3 block' => $compact])>{{ t('ui.color.custom') }}</label>
        <div class="flex items-center gap-2">
            <input type="color" class="h-9 w-11 shrink-0 cursor-pointer rounded-xl border border-input bg-white p-1" :value="c" value="{{ $current }}"
                   @input="set($event.target.value)" aria-label="{{ t('ui.color.custom') }}" @disabled($disabled)>
            <input id="{{ $id }}-hex" type="text" class="input h-9 w-28 font-mono uppercase" x-model="hex" @input="typed()" maxlength="7"
                   pattern="#[0-9a-fA-F]{6}" spellcheck="false" autocomplete="off" @disabled($disabled)>
        </div>
    </div>
</div>
