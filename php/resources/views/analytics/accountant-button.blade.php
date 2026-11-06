{{-- Годовой пакет для бухгалтера: один Excel со всеми данными года (CEO). Подключение: @include('analytics.accountant-button') --}}
@if (can('finance.company.read', 'ALL') && can('export.run'))
    @php
        $thisYear = \App\Support\Format::today()->year;
        $defaultYear = \App\Support\Format::today()->month <= 3 ? $thisYear - 1 : $thisYear;
    @endphp
    <button type="button" class="btn btn-outline" x-data @click="$dispatch('open-modal', 'accountant-package')"><x-icon name="file" /> {{ t('accountant.button') }}</button>
    <x-modal name="accountant-package" :title="t('accountant.title')">
        <div class="space-y-4" x-data="{ year: @js((string) $defaultYear) }">
            <p class="text-sm text-muted-foreground">{{ t('accountant.text') }}</p>
            <x-select name="year" x-model="year" :label="t('accountant.year')" :value="$defaultYear" :options="collect(range($thisYear, $thisYear - 4))->mapWithKeys(fn ($y) => [$y => $y])->all()" />
            <ul class="grid gap-1 text-sm text-muted-foreground">@foreach (['c1', 'c2', 'c3', 'c4', 'c5', 'c6'] as $k)<li>• {{ t('accountant.contents.'.$k) }}</li>@endforeach</ul>
            <p class="text-xs text-muted-foreground">{{ t('accountant.companyHint') }} <a href="{{ route('automation') }}#company" class="underline underline-offset-2">{{ t('accountant.companyLink') }}</a></p>
            <div class="flex justify-end"><a class="btn btn-primary" :href="@js(route('exports.accountant')) + '?year=' + year" @click="$dispatch('close-modal', 'accountant-package')"><x-icon name="download" /> {{ t('accountant.download') }}</a></div>
        </div>
    </x-modal>
@endif
