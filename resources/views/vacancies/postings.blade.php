@extends('layouts.app')
@section('title', t('vacancies.postings.title'))
@section('content')
    @php $colors = ['PUBLISHED' => 'green', 'DRAFT' => 'gray', 'CLOSED' => 'red']; @endphp
    <x-page-header :title="t('vacancies.postings.title')" :subtitle="t('vacancies.postings.subtitle')">
        @if ($canManage)<button type="button" class="btn btn-primary" x-data @click="$dispatch('open-modal', 'posting-new')"><x-icon name="plus" /> {{ t('vacancies.postings.new') }}</button>@endif
    </x-page-header>
    @include('vacancies.partials.tabs', ['active' => '/vacancies/postings'])

    <p class="mb-3 text-xs text-muted-foreground">{{ t('vacancies.postings.feedInfo') }} <code>{{ url('/hooks/website-vacancies') }}</code></p>

    @if (! $rows)
        <div class="card"><x-empty icon="briefcase" :title="t('vacancies.postings.empty')">{{ t('vacancies.postings.emptyText') }}</x-empty></div>
    @else
        <div x-data="{ id: null, f: {} }">
        <x-table>
            <thead><tr><th>{{ t('vacancies.postings.titleRu') }}</th><th>{{ t('vacancies.postings.city') }}</th><th>{{ t('vacancies.postings.employment') }}</th><th>{{ t('vacancies.postings.status') }}</th><th class="text-right">{{ t('vacancies.postings.apps') }}</th>@if ($canManage)<th></th>@endif</tr></thead>
            <tbody>
            @foreach ($rows as $r)
                @php $p = $r['posting']; @endphp
                <tr>
                    <td class="font-medium">{{ $p->title_ru }}<span class="block text-xs font-normal text-muted-foreground">{{ $p->slug }}@if ($p->salary) · {{ $p->salary }}@endif</span></td>
                    <td>{{ $p->city ?: '—' }}</td>
                    <td>{{ t('vacancies.postings.emp.'.$p->employment) }}</td>
                    <td><x-badge :color="$colors[$p->status]">{{ t('vacancies.postings.st.'.$p->status) }}</x-badge>@if ($p->published_at)<span class="block text-xs text-muted-foreground">{{ d($p->published_at) }}</span>@endif</td>
                    <td class="text-right tabular-nums"><a class="link" href="/vacancies?view=list&q={{ urlencode($p->title_ru) }}">{{ $r['applications'] }}</a></td>
                    @if ($canManage)
                        <td class="whitespace-nowrap text-right">
                            <form method="POST" action="/vacancies/postings/{{ $p->id }}/status" class="inline">@csrf @method('PATCH')
                                @if ($p->status === 'PUBLISHED')<input type="hidden" name="status" value="CLOSED"><button class="btn btn-outline btn-sm">{{ t('vacancies.postings.unpublish') }}</button>
                                @else<input type="hidden" name="status" value="PUBLISHED"><button class="btn btn-primary btn-sm">{{ t('vacancies.postings.publish') }}</button>@endif</form>
                            <button type="button" class="btn btn-ghost btn-icon" aria-label="{{ t('common.edit') }}"
                                @click="id = @js($p->id); f = @js($p->only(['title_ru', 'title_uz', 'title_en', 'description_ru', 'description_uz', 'description_en', 'city', 'salary', 'employment', 'status', 'sort'])); $dispatch('open-modal', 'posting-edit')"><x-icon name="edit" /></button>
                            <form method="POST" action="/vacancies/postings/{{ $p->id }}" class="inline" onsubmit="return confirm(@js(t('vacancies.postings.deleteConfirm')))">@csrf @method('DELETE')
                                <button class="btn btn-ghost btn-icon" aria-label="{{ t('common.delete') }}"><x-icon name="trash" /></button></form>
                        </td>
                    @endif
                </tr>
            @endforeach
            </tbody>
        </x-table>
        @if ($canManage)
            <x-modal name="posting-edit" :title="t('vacancies.postings.edit')" size="max-w-3xl">
                <form method="POST" :action="'/vacancies/postings/' + id" class="space-y-4">@csrf @method('PUT')
                    @include('vacancies.partials.posting-form', ['edit' => true])
                    <div class="flex justify-end gap-2"><button type="button" class="btn btn-outline" @click="$dispatch('close-modal', 'posting-edit')">{{ t('common.cancel') }}</button><button class="btn btn-primary">{{ t('common.save') }}</button></div>
                </form>
            </x-modal>
        @endif
        </div>
    @endif

    @if ($canManage)
        <x-modal name="posting-new" :title="t('vacancies.postings.new')" size="max-w-3xl" :open="$errors->any() && old('_form') === 'posting'">
            <form method="POST" action="/vacancies/postings" class="space-y-4">@csrf
                <input type="hidden" name="_form" value="posting">
                @include('vacancies.partials.posting-form', ['edit' => false])
                <div class="flex justify-end gap-2"><button type="button" class="btn btn-outline" @click="$dispatch('close-modal', 'posting-new')">{{ t('common.cancel') }}</button><button class="btn btn-primary">{{ t('vacancies.postings.createPublish') }}</button></div>
            </form>
        </x-modal>
    @endif
@endsection
