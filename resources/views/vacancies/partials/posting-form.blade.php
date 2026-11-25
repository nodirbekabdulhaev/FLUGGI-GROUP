{{-- Поля вакансии компании: $p (редактирование) или null. Значения при правке подставляет Alpine (объект f). --}}
@props(['edit' => false])
@php $m = fn (string $k) => $edit ? 'x-model="f.'.$k.'"' : ''; @endphp
<div class="grid gap-3 sm:grid-cols-2">
    <div class="sm:col-span-2"><label class="label" for="vp-title-ru{{ $edit ? '-e' : '' }}">{{ t('vacancies.postings.titleRu') }}<span class="text-danger"> *</span></label>
        <input id="vp-title-ru{{ $edit ? '-e' : '' }}" name="title_ru" class="input" maxlength="191" required {!! $m('title_ru') !!} @unless ($edit) value="{{ old('title_ru') }}" @endunless></div>
    <div><label class="label">{{ t('vacancies.postings.titleUz') }}</label><input name="title_uz" class="input" maxlength="191" {!! $m('title_uz') !!} @unless ($edit) value="{{ old('title_uz') }}" @endunless></div>
    <div><label class="label">{{ t('vacancies.postings.titleEn') }}</label><input name="title_en" class="input" maxlength="191" {!! $m('title_en') !!} @unless ($edit) value="{{ old('title_en') }}" @endunless></div>
    @foreach (['ru', 'uz', 'en'] as $l)
        <div class="sm:col-span-2"><label class="label">{{ t('vacancies.postings.description') }} ({{ strtoupper($l) }})</label>
            <textarea name="description_{{ $l }}" rows="6" class="input" maxlength="10000" {!! $m('description_'.$l) !!}>@unless ($edit){{ old('description_'.$l) }}@endunless</textarea></div>
    @endforeach
    <div><label class="label">{{ t('vacancies.postings.city') }}</label><input name="city" class="input" maxlength="100" {!! $m('city') !!} @unless ($edit) value="{{ old('city') }}" @endunless></div>
    <div><label class="label">{{ t('vacancies.postings.salary') }}</label><input name="salary" class="input" maxlength="100" placeholder="{{ t('vacancies.postings.salaryHint') }}" {!! $m('salary') !!} @unless ($edit) value="{{ old('salary') }}" @endunless></div>
    <div><label class="label">{{ t('vacancies.postings.employment') }}</label>
        <select name="employment" class="input" {!! $m('employment') !!}>@foreach (\App\Services\Vacancies\PostingService::EMPLOYMENT as $e)<option value="{{ $e }}" @selected(! $edit && old('employment', 'FULL') === $e)>{{ t('vacancies.postings.emp.'.$e) }}</option>@endforeach</select></div>
    <div><label class="label">{{ t('vacancies.postings.status') }}</label>
        <select name="status" class="input" {!! $m('status') !!}>@foreach (\App\Services\Vacancies\PostingService::STATUSES as $s)<option value="{{ $s }}" @selected(! $edit && old('status', 'PUBLISHED') === $s)>{{ t('vacancies.postings.st.'.$s) }}</option>@endforeach</select>
        <p class="hint">{{ t('vacancies.postings.statusHint') }}</p></div>
    @unless ($edit)
        <div><label class="label">{{ t('vacancies.postings.slug') }}</label><input name="slug" class="input" maxlength="120" pattern="[a-z0-9-]*" placeholder="php-developer" value="{{ old('slug') }}"><p class="hint">{{ t('vacancies.postings.slugHint') }}</p></div>
    @endunless
    <div><label class="label">{{ t('vacancies.postings.sort') }}</label><input name="sort" type="number" min="0" max="9999" class="input" {!! $m('sort') !!} @unless ($edit) value="{{ old('sort', 0) }}" @endunless></div>
</div>
@if ($errors->any())<p class="error mt-2">{{ $errors->first() }}</p>@endif
