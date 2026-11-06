@extends('layouts.app')
@section('title', t('accounting.people.title'))
@section('content')
    <x-page-header :title="t('accounting.people.title')" :subtitle="t('accounting.people.subtitle')">
        @if ($canManage)
            <button type="button" class="btn btn-primary" @click="$dispatch('open-modal', 'acc-person')"><x-icon name="plus" /> {{ t('accounting.people.add') }}</button>
        @endif
    </x-page-header>
    @include('accounting.partials.tabs', ['active' => '/accounting/people'])

    @if ($people->isEmpty())
        <div class="card"><x-empty icon="users" :title="t('accounting.people.empty')">{{ t('accounting.people.emptyText') }}</x-empty></div>
    @else
        <x-table>
            <thead><tr>
                <th>{{ t('accounting.people.fullName') }}</th><th>{{ t('accounting.people.pinfl') }}</th><th>{{ t('accounting.people.position') }}</th>
                <th class="text-right">{{ t('accounting.people.salary') }}</th><th>{{ t('accounting.people.period') }}</th><th></th>
            </tr></thead>
            <tbody>
            @foreach ($people as $p)
                <tr @class(['opacity-60' => ! $p->is_active])>
                    <td><span class="font-medium">{{ $p->full_name }}</span>
                        @if ($p->user)<span class="block text-xs text-muted-foreground">{{ t('accounting.people.crmUser') }}: {{ $p->user->full_name }}</span>@endif
                        @unless ($p->is_active)<x-badge color="yellow">{{ t('accounting.people.inactive') }}</x-badge>@endunless
                    </td>
                    <td class="whitespace-nowrap tabular-nums">{{ $p->pinfl ?? '—' }}</td>
                    <td>{{ $p->position ?? '—' }}</td>
                    <td class="whitespace-nowrap text-right tabular-nums">{{ money($p->monthly_salary) }}</td>
                    <td class="whitespace-nowrap text-sm">{{ $p->hired_at ? d($p->hired_at) : '…' }} — {{ $p->fired_at ? d($p->fired_at) : '…' }}</td>
                    <td class="whitespace-nowrap text-right">
                        <a href="/accounting/operations?person={{ $p->id }}" class="btn btn-ghost btn-sm">{{ t('accounting.people.payments') }}</a>
                        @if ($canManage)
                            <a href="/accounting/people?edit={{ $p->id }}" class="btn btn-ghost btn-icon" aria-label="{{ t('common.edit') }}"><x-icon name="edit" /></a>
                            <form method="POST" action="/accounting/people/{{ $p->id }}" class="inline" onsubmit="return confirm(@js(t('accounting.people.deleteConfirm')))">@csrf @method('DELETE')
                                <button class="btn btn-ghost btn-icon" aria-label="{{ t('common.delete') }}"><x-icon name="trash" /></button></form>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </x-table>
    @endif

    @if ($canManage)
        <x-modal name="acc-person" :title="t('accounting.people.add')" :open="$errors->any() && ! $editing">
            @include('accounting.partials.person-form', ['person' => null])
        </x-modal>
        @if ($editing)
            <x-modal name="acc-person-edit" :title="t('accounting.people.edit')" :open="true">
                @include('accounting.partials.person-form', ['person' => $editing])
            </x-modal>
        @endif
    @endif
@endsection
