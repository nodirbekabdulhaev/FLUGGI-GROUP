@extends('layouts.app')
@section('title', t('followUps.title'))
@section('content')
    <x-page-header :title="t('followUps.title')" :subtitle="t('followUps.subtitle')" />
    <div class="mb-4 flex flex-wrap gap-2">
        @foreach (['due', 'pending', 'closed'] as $v)
            <a href="{{ route('followUps', ['view' => $v]) }}" @class(['btn btn-sm', 'btn-primary' => $view === $v, 'btn-outline' => $view !== $v])>{{ t('followUps.views.'.$v) }}</a>
        @endforeach
    </div>
    <div x-data="{ f: null }" @follow-up.window="f = $event.detail; $dispatch('open-modal', 'follow-up')">
        @if ($items->isEmpty())
            <x-card><x-empty icon="refresh" :title="t('followUps.emptyTitle')">{{ t('followUps.emptyText') }}</x-empty></x-card>
        @else
            <x-table>
                <thead><tr>
                    <th>{{ t('followUps.client') }}</th><th>{{ t('followUps.kind') }}</th><th>{{ t('followUps.project') }}</th>
                    <th>{{ t('followUps.due') }}</th><th>{{ t('followUps.owner') }}</th><th>{{ t('followUps.status') }}</th><th class="w-32"></th>
                </tr></thead>
                <tbody>
                    @foreach ($items as $f)
                        @php($overdue = \App\Services\Automation\FollowUpService::overdueDays($f))
                        <tr>
                            <td class="font-medium">@if ($f->client)<a href="/clients/{{ $f->client->id }}" class="hover:underline">{{ $f->client->name }}</a>@endif</td>
                            <td>{{ t('followUps.kinds.'.$f->kind) }}</td>
                            <td>@if ($f->project)<a href="/projects/{{ $f->project->id }}" class="text-muted-foreground hover:underline">{{ \App\Support\Format::code('P', $f->project->number) }} {{ $f->project->name }}</a>@else — @endif</td>
                            <td class="whitespace-nowrap">{{ d($f->due_date?->format('Y-m-d')) }}
                                @if ($overdue > 0)<x-badge color="red" class="ml-1">{{ t('followUps.overdue', ['days' => $overdue]) }}</x-badge>@endif</td>
                            <td>{{ $f->owner?->full_name }}</td>
                            <td>
                                @if ($f->status === 'PENDING')
                                    <x-badge color="yellow">{{ t('followUps.statuses.PENDING') }}</x-badge>
                                @else
                                    <span class="grid gap-0.5">
                                        <x-badge :color="$f->status === 'DONE' ? 'green' : 'gray'">{{ t('followUps.statuses.'.$f->status) }}</x-badge>
                                        @if ($f->resultDeal)<a href="/sales/deals/{{ $f->resultDeal->id }}" class="text-xs hover:underline">{{ \App\Support\Format::code('D', $f->resultDeal->number) }}</a>@endif
                                    </span>
                                @endif
                            </td>
                            <td class="text-right">
                                @if ($f->status === 'PENDING')
                                    <button type="button" class="btn btn-outline btn-sm" @click="$dispatch('follow-up', @js(['id' => $f->id, 'client' => $f->client?->name, 'action' => route('followUps.complete', $f->id), 'repeat' => $f->kind === 'REPEAT_SALE']))">{{ t('followUps.complete') }}</button>
                                @else
                                    <span class="line-clamp-2 text-xs text-muted-foreground">{{ $f->result }}</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
                <x-slot:footer>{{ $items->links('vendor.pagination.fluggi') }}</x-slot:footer>
            </x-table>
        @endif

        <x-modal name="follow-up" :title="t('followUps.completeTitle')">
            <form method="POST" :action="f?.action" class="space-y-4">
                @csrf
                <p class="text-sm text-muted-foreground" x-text="f?.client"></p>
                <div><label class="label" for="fu-result">{{ t('followUps.result') }}</label>
                    <textarea id="fu-result" name="result" rows="3" class="input" maxlength="2000" placeholder="{{ t('followUps.resultPlaceholder') }}"></textarea></div>
                @if (can('deal.create'))
                    <label class="flex items-center gap-2 text-sm"><input type="hidden" name="createDeal" value="0"><input type="checkbox" name="createDeal" value="1" :checked="f?.repeat"> {{ t('followUps.createDeal') }}</label>
                @endif
                <div class="flex flex-wrap justify-end gap-2">
                    <button name="status" value="SKIPPED" class="btn btn-outline" onclick="this.form.querySelector('[name=createDeal][type=checkbox]') && (this.form.querySelector('[name=createDeal][type=checkbox]').checked = false)">{{ t('followUps.skip') }}</button>
                    <button name="status" value="DONE" class="btn btn-primary">{{ t('followUps.done') }}</button>
                </div>
            </form>
        </x-modal>
    </div>
@endsection
