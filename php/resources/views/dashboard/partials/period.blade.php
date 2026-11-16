{{-- Период (ТЗ §4): ?period=today|week|month|quarter|year|custom&from&to — сохраняется в адресе --}}
<form method="GET" class="flex flex-wrap items-center gap-2" x-data="{ preset: @js($period['preset']) }">
    <select name="period" class="input h-9 w-auto min-w-40" aria-label="{{ t('period.label') }}" x-model="preset" @change="if (preset !== 'custom') $el.form.submit()">
        @foreach (\App\Services\Finance\Period::PRESETS as $pp)<option value="{{ $pp }}">{{ t('period.'.$pp) }}</option>@endforeach
    </select>
    <template x-if="preset === 'custom'">
        <div class="flex flex-wrap items-center gap-2">
            <input type="date" name="from" class="input h-9 w-auto" aria-label="{{ t('period.from') }}" value="{{ $period['customFrom'] }}" required>
            <input type="date" name="to" class="input h-9 w-auto" aria-label="{{ t('period.to') }}" value="{{ $period['customTo'] }}" required>
            <button class="btn btn-outline btn-sm">{{ t('period.apply') }}</button>
        </div>
    </template>
</form>
