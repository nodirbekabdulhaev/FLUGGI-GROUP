{{-- Форма своей статьи: создание или правка ($category) --}}
@php
    use App\Services\Accounting\Accounting;
    $key = $category?->id ?? 'new';
    $dir = old('direction', $category?->direction ?? 'OUT');
    $dir = in_array($dir, ['IN', 'OUT'], true) ? $dir : 'OUT';
    $base = old('base', $category?->base ?? ($dir === 'IN' ? 'OTHER_INCOME' : 'OTHER'));
    $dirLocked = $category && $category->operations_count > 0;
@endphp
<form method="POST" action="{{ $category ? '/accounting/categories/'.$category->id : '/accounting/categories' }}" class="space-y-4"
      x-data="{ dir: @js($dir), base: @js($base) }">
    @csrf
    @if ($category) @method('PUT') @endif
    <input type="hidden" name="direction" :value="dir">
    <div>
        <span class="label">{{ t('accounting.fields.direction') }}</span>
        <div class="grid grid-cols-2 gap-2" role="radiogroup">
            @foreach (['IN', 'OUT'] as $d)
                <button type="button" class="btn" :class="dir === @js($d) ? 'btn-primary' : 'btn-outline'" @disabled($dirLocked && $dir !== $d)
                        @click="dir = @js($d); base = @js($d === 'IN' ? 'OTHER_INCOME' : 'OTHER')">{{ t('accounting.direction.'.$d) }}</button>
            @endforeach
        </div>
        @if ($dirLocked)<p class="hint">{{ t('accounting.categories.directionLocked') }}</p>@endif
        @error('direction')<p class="error">{{ $message }}</p>@enderror
    </div>
    <x-field name="name" :id="'cat-name-'.$key" :label="t('accounting.categories.name')" :value="$category?->name" :hint="t('accounting.categories.nameHint')" maxlength="120" required />
    <div>
        <label class="label" for="cat-base-{{ $key }}">{{ t('accounting.categories.base') }}</label>
        @foreach (['IN', 'OUT'] as $d)
            <select @if ($d === 'IN') id="cat-base-{{ $key }}" @else aria-label="{{ t('accounting.categories.base') }}" @endif name="base" class="input"
                    x-show="dir === @js($d)" :disabled="dir !== @js($d)" x-model="base" @if ($d !== $dir) disabled style="display: none" @endif>
                @foreach (Accounting::manual($d) as $c)
                    <option value="{{ $c }}" @selected($base === $c && $dir === $d)>{{ t('accounting.direction.'.$d) }} → {{ t('accounting.category.'.$c) }}</option>
                @endforeach
            </select>
        @endforeach
        @error('base')<p class="error">{{ $message }}</p>@else<p class="hint">{{ t('accounting.categories.baseHint') }}</p>@enderror
        @if ($category)<p class="hint">{{ t('accounting.categories.baseChangeHint') }}</p>@endif
    </div>
    <x-field name="keywords" :id="'cat-kw-'.$key" type="textarea" rows="2" :label="t('accounting.categories.keywords')" :value="$category?->keywords" :hint="t('accounting.categories.keywordsHint')" maxlength="1000" />
    <x-field name="sort" :id="'cat-sort-'.$key" type="number" min="0" max="1000" :label="t('accounting.categories.sort')" :value="$category?->sort ?? 0" />
    <div class="flex justify-end gap-2">
        @if ($category)<a href="/accounting/categories" class="btn btn-outline">{{ t('common.cancel') }}</a>
        @else<button type="button" class="btn btn-outline" @click="$dispatch('close-modal', 'acc-category')">{{ t('common.cancel') }}</button>@endif
        <button class="btn btn-primary">{{ t('common.save') }}</button>
    </div>
</form>
