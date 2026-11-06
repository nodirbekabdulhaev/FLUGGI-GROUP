@extends('layouts.app')
@section('title', t('accounting.categories.title'))
@section('content')
    <x-page-header :title="t('accounting.categories.title')" :subtitle="t('accounting.categories.subtitle')">
        @if ($canManage)
            <button type="button" class="btn btn-primary" @click="$dispatch('open-modal', 'acc-category')"><x-icon name="plus" /> {{ t('accounting.categories.add') }}</button>
        @endif
    </x-page-header>
    @include('accounting.partials.tabs', ['active' => '/accounting/categories'])

    <p class="mb-4 text-sm text-muted-foreground">{{ t('accounting.categories.intro') }}</p>

    <div class="grid gap-4 lg:grid-cols-2">
        @foreach (['IN', 'OUT'] as $d)
            @php $list = $categories->where('direction', $d); @endphp
            <x-card :title="t('accounting.categories.list'.$d)" :padding="false">
                @if ($list->isEmpty())
                    <x-empty icon="list" :title="t('accounting.categories.empty')">{{ t('accounting.categories.emptyText'.$d) }}</x-empty>
                @else
                    <ul class="divide-y">
                        @foreach ($list as $c)
                            <li class="flex flex-col gap-2 p-4 sm:flex-row sm:items-center sm:justify-between">
                                <div class="min-w-0">
                                    <p class="flex flex-wrap items-center gap-2 font-medium"><span class="break-words">{{ $c->name }}</span>
                                        @unless ($c->is_active)<x-badge color="yellow">{{ t('accounting.categories.inactive') }}</x-badge>@endunless
                                    </p>
                                    <p class="text-sm text-muted-foreground">{{ t('accounting.categories.countsAs', ['base' => t('accounting.direction.'.$c->direction).' → '.t('accounting.category.'.$c->base)]) }}</p>
                                    @if ($c->keywords)<p class="break-words text-xs text-muted-foreground">{{ t('accounting.categories.keywords') }}: {{ $c->keywords }}</p>@endif
                                    <p class="text-xs text-muted-foreground">
                                        @if ($c->operations_count > 0)
                                            <a class="link" href="/accounting/operations?category={{ $c->id }}">{{ t('accounting.accounts.operationsCount', ['count' => $c->operations_count]) }}</a>
                                        @else
                                            {{ t('accounting.accounts.operationsCount', ['count' => 0]) }}
                                        @endif
                                        · {{ t('accounting.categories.sort') }}: {{ $c->sort }}
                                    </p>
                                </div>
                                @if ($canManage)
                                    <div class="flex flex-wrap items-center gap-2 sm:justify-end">
                                        <a href="/accounting/categories?edit={{ $c->id }}" class="btn btn-ghost btn-icon" aria-label="{{ t('common.edit') }}"><x-icon name="edit" /></a>
                                        <form method="POST" action="/accounting/categories/{{ $c->id }}/active">@csrf<input type="hidden" name="active" value="{{ $c->is_active ? 0 : 1 }}">
                                            <button class="btn btn-outline btn-sm">{{ $c->is_active ? t('accounting.accounts.deactivate') : t('accounting.accounts.activate') }}</button></form>
                                        @if ($c->operations_count === 0)
                                            <form method="POST" action="/accounting/categories/{{ $c->id }}" onsubmit="return confirm(@js(t('accounting.categories.deleteConfirm')))">@csrf @method('DELETE')
                                                <button class="btn btn-ghost btn-icon" aria-label="{{ t('common.delete') }}"><x-icon name="trash" /></button></form>
                                        @endif
                                    </div>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-card>
        @endforeach
    </div>
    <p class="mt-3 text-xs text-muted-foreground">{{ t('accounting.categories.footer') }}</p>

    @if ($canManage)
        <x-modal name="acc-category" :title="t('accounting.categories.add')" :open="$errors->any() && ! $editing">
            @include('accounting.partials.category-form', ['category' => null])
        </x-modal>
        @if ($editing)
            @php $editing->setAttribute('operations_count', (int) ($categories->firstWhere('id', $editing->id)?->operations_count ?? 0)); @endphp
            <x-modal name="acc-category-edit" :title="t('accounting.categories.edit')" :open="true">
                @include('accounting.partials.category-form', ['category' => $editing])
            </x-modal>
        @endif
    @endif
@endsection
