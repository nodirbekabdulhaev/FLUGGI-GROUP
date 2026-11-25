{{-- Форма человека для налоговой: создание или правка ($person); данные для отчёта в налоговую, оклад с месяца, льготы --}}
@php
    use App\Services\Accounting\TaxReport;
    use App\Support\Format;
    $num = fn ($v) => $v === null ? null : rtrim(rtrim((string) $v, '0'), '.');
    $key = $person?->id ?? 'new';
    $codes = fn (string $group, array $values) => collect($values)->mapWithKeys(fn ($v) => [$v => $v.' — '.t('accounting.people.'.$group.'.'.$v)])->all();
    $own = $person?->benefits;
    $mode = old('benefits_mode', $person === null || $own === null ? 'default' : ($own === [] ? 'none' : 'custom'));
    $benefitRows = old('benefits', $own
        ? array_map(fn ($b) => ['code' => $b['code'], 'kind' => isset($b['percent']) ? 'percent' : 'amount', 'value' => isset($b['percent']) ? $b['percent'] : $num($b['amount'] ?? '0')], $own)
        : []);
    $benefitRows = array_values(is_array($benefitRows) && $benefitRows ? $benefitRows : [['code' => '', 'kind' => 'percent', 'value' => '']]);
    $companyBenefit = $settings['benefitCode'] !== '' && (float) $settings['benefitPercent'] > 0
        ? t('accounting.people.companyBenefit', ['code' => $settings['benefitCode'], 'percent' => $settings['benefitPercent']]) : t('accounting.people.companyBenefitNone');
@endphp
<form method="POST" action="{{ $person ? '/accounting/people/'.$person->id : '/accounting/people' }}" class="space-y-4">
    @csrf
    @if ($person) @method('PUT') @endif
    <x-field name="full_name" :id="'p-name-'.$key" :label="t('accounting.people.fullName')" :value="$person?->full_name" required />
    <x-field name="passport_name" :id="'p-passport-'.$key" :label="t('accounting.people.passportName')" :hint="t('accounting.people.passportNameHint')" :value="$person?->passport_name" />
    <div class="grid gap-4 sm:grid-cols-2">
        <x-field name="pinfl" :id="'p-pinfl-'.$key" inputmode="numeric" maxlength="14" :label="t('accounting.people.pinfl')" :hint="t('accounting.people.pinflHint')" :value="$person?->pinfl" />
        <x-field name="position" :id="'p-position-'.$key" :label="t('accounting.people.position')" :hint="t('accounting.people.positionHint')" :value="$person?->position" />
        <x-field name="monthly_salary" :id="'p-salary-'.$key" inputmode="decimal" data-money :label="t('accounting.people.salary')" :hint="t('accounting.people.salaryHint')" :value="$num($person?->monthly_salary)" required />
        <x-field name="salary_from" :id="'p-salary-from-'.$key" type="month" :label="t('accounting.people.salaryFrom')"
                 :hint="$person ? t('accounting.people.salaryFromHint') : t('accounting.people.salaryFromNewHint')" :value="$person ? Format::today()->format('Y-m') : null" />
        <x-select name="user_id" :id="'p-user-'.$key" :label="t('accounting.people.user')" :placeholder="t('accounting.people.noUser')" :options="$users" :value="$person?->user_id" />
        <x-field name="hired_at" :id="'p-hired-'.$key" type="date" :label="t('accounting.people.hiredAt')" :value="$person?->hired_at?->toDateString()" />
        <x-field name="fired_at" :id="'p-fired-'.$key" type="date" :label="t('accounting.people.firedAt')" :value="$person?->fired_at?->toDateString()" />
    </div>

    <fieldset class="space-y-4 rounded-lg border p-3">
        <legend class="px-1 text-sm font-medium">{{ t('accounting.people.reportSection') }}</legend>
        <div class="grid gap-4 sm:grid-cols-2">
            <x-select name="resident_type" :id="'p-resident-'.$key" :label="t('accounting.people.residentType')" :options="$codes('residentTypes', TaxReport::RESIDENT_TYPES)" :value="$person?->resident_type ?? 1" />
            <x-select name="contract_type" :id="'p-contract-'.$key" :label="t('accounting.people.contractType')" :options="$codes('contractTypes', TaxReport::CONTRACT_TYPES)" :value="$person?->contract_type ?? 1" />
            <x-field name="work_rate" :id="'p-rate-'.$key" inputmode="decimal" :label="t('accounting.people.workRate')" :hint="t('accounting.people.workRateHint')" :value="$num($person?->work_rate) ?? '1'" required />
            <x-select name="workplace_type" :id="'p-workplace-'.$key" :label="t('accounting.people.workplaceType')" :options="$codes('workplaceTypes', TaxReport::WORKPLACE_TYPES)" :value="$person?->workplace_type ?? 1" />
        </div>
        <div x-data="{ mode: @js($mode), rows: @js($benefitRows) }">
            <span class="label">{{ t('accounting.people.benefits') }}</span>
            <div class="space-y-1 text-sm">
                <label class="flex items-start gap-2"><input type="radio" name="benefits_mode" value="default" x-model="mode" class="mt-0.5"> <span>{{ t('accounting.people.benefitsDefault') }}<span class="block text-xs text-muted-foreground">{{ $companyBenefit }}</span></span></label>
                <label class="flex items-center gap-2"><input type="radio" name="benefits_mode" value="none" x-model="mode"> {{ t('accounting.people.benefitsNone') }}</label>
                <label class="flex items-center gap-2"><input type="radio" name="benefits_mode" value="custom" x-model="mode"> {{ t('accounting.people.benefitsCustom') }}</label>
            </div>
            <div class="mt-2 space-y-2" x-show="mode === 'custom'" x-cloak>
                <template x-for="(row, i) in rows" :key="i">
                    <div class="grid grid-cols-[1fr_auto] gap-2 sm:grid-cols-[8rem_9rem_1fr_auto]">
                        <input class="input" inputmode="numeric" maxlength="10" :name="'benefits[' + i + '][code]'" x-model="row.code" :disabled="mode !== 'custom'"
                               placeholder="{{ t('accounting.people.benefitCode') }}" aria-label="{{ t('accounting.people.benefitCode') }}">
                        <select class="input" :name="'benefits[' + i + '][kind]'" x-model="row.kind" :disabled="mode !== 'custom'" aria-label="{{ t('accounting.people.benefitKind') }}">
                            <option value="percent">{{ t('accounting.people.benefitPercent') }}</option>
                            <option value="amount">{{ t('accounting.people.benefitAmount') }}</option>
                        </select>
                        <input class="input" inputmode="decimal" :name="'benefits[' + i + '][value]'" x-model="row.value" :disabled="mode !== 'custom'"
                               :placeholder="row.kind === 'percent' ? '37.5' : '1 000 000'" aria-label="{{ t('accounting.people.benefitValue') }}">
                        <button type="button" class="btn btn-ghost btn-icon" @click="rows.splice(i, 1); if (! rows.length) rows.push({ code: '', kind: 'percent', value: '' })" aria-label="{{ t('common.delete') }}"><x-icon name="trash" /></button>
                    </div>
                </template>
                <button type="button" class="btn btn-outline btn-sm" x-show="rows.length < {{ TaxReport::MAX_BENEFITS }}" @click="rows.push({ code: '', kind: 'percent', value: '' })"><x-icon name="plus" /> {{ t('accounting.people.benefitAdd') }}</button>
                <p class="hint">{{ t('accounting.people.benefitsHint') }}</p>
            </div>
            @error('benefits')<p class="error">{{ $message }}</p>@enderror
            @foreach ($errors->getMessages() as $field => $messages)
                @if (str_starts_with($field, 'benefits.'))<p class="error">{{ $messages[0] }}</p>@break @endif
            @endforeach
        </div>
    </fieldset>

    <x-field name="comment" :id="'p-comment-'.$key" type="textarea" rows="2" :label="t('accounting.fields.description')" :value="$person?->comment" />
    <input type="hidden" name="is_active" value="0">
    <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $person?->is_active ?? true))> {{ t('accounting.people.active') }}</label>
    <div class="flex justify-end gap-2">
        @if ($person)<a href="/accounting/people" class="btn btn-outline">{{ t('common.cancel') }}</a>
        @else<button type="button" class="btn btn-outline" @click="$dispatch('close-modal', 'acc-person')">{{ t('common.cancel') }}</button>@endif
        <button class="btn btn-primary">{{ t('common.save') }}</button>
    </div>
</form>
