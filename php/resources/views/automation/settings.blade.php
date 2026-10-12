@extends('layouts.app')
@section('title', t('nav.settings'))
@section('content')
    <x-page-header :title="t('automation.title')" :subtitle="t('automation.subtitle')" back="/settings" />
    <div class="grid gap-6 lg:grid-cols-2">
        <x-card :title="t('automation.settingsTitle')" :subtitle="t('automation.settingsText')">
            <form method="POST" action="{{ route('automation.save') }}" class="space-y-5"
                  x-data="{ rows: @js(old('followUps', $settings['followUps'])) }">
                @csrf @method('PUT')
                <x-field name="largeAmountUzs" type="number" min="0" :label="t('automation.largeAmount')" :hint="t('automation.largeAmountHint')" :value="$settings['largeAmountUzs']" required />
                <fieldset class="grid gap-2">
                    <legend class="mb-1 text-sm font-medium">{{ t('automation.followUps') }}</legend>
                    <p class="text-xs text-muted-foreground">{{ t('automation.followUpsHint') }}</p>
                    <template x-for="(f, i) in rows" :key="i">
                        <div class="flex items-center gap-2">
                            <input type="number" min="1" max="730" class="input w-20 shrink-0" :name="'followUps[' + i + '][days]'" x-model="f.days" aria-label="{{ t('automation.days') }}">
                            <span class="shrink-0 text-sm text-muted-foreground">{{ t('automation.daysAfter') }}</span>
                            <select class="input min-w-0 flex-1" :name="'followUps[' + i + '][kind]'" x-model="f.kind" aria-label="{{ t('automation.kind') }}">
                                @foreach (\App\Services\Automation\FollowUpService::KINDS as $k)<option value="{{ $k }}">{{ t('followUps.kinds.'.$k) }}</option>@endforeach
                            </select>
                            <button type="button" class="btn btn-ghost btn-sm" aria-label="{{ t('automation.remove') }}" @click="rows.splice(i, 1)"><x-icon name="trash" /></button>
                        </div>
                    </template>
                    <div x-show="rows.length < 10"><button type="button" class="btn btn-outline btn-sm" @click="rows.push({ days: 30, kind: 'CONTACT' })"><x-icon name="plus" /> {{ t('automation.addFollowUp') }}</button></div>
                    @error('followUps')<p class="error">{{ $message }}</p>@enderror
                </fieldset>
                <div class="grid gap-2 text-sm">
                    <label class="flex items-center gap-2"><input type="hidden" name="dailyReport" value="0"><input type="checkbox" name="dailyReport" value="1" @checked($settings['dailyReport'])> {{ t('automation.dailyReport') }}</label>
                    <label class="flex items-center gap-2"><input type="hidden" name="weeklyReport" value="0"><input type="checkbox" name="weeklyReport" value="1" @checked($settings['weeklyReport'])> {{ t('automation.weeklyReport') }}</label>
                </div>
                <button class="btn btn-primary">{{ t('automation.save') }}</button>
            </form>
        </x-card>

        <x-card :title="t('automation.previewTitle')" :subtitle="t('automation.previewText')">
            <div class="mb-3 flex gap-1">
                @foreach (['daily', 'weekly'] as $k)
                    <a href="{{ route('automation', ['report' => $k]) }}" @class(['btn btn-sm', 'btn-primary' => $kind === $k, 'btn-outline' => $kind !== $k])>{{ t('automation.'.$k) }}</a>
                @endforeach
            </div>
            <pre class="whitespace-pre-wrap break-words rounded-md bg-muted p-4 font-sans text-sm leading-relaxed" data-testid="report-preview">{{ $preview }}</pre>
        </x-card>

        <x-card :title="t('automation.jobsTitle')" :subtitle="t('automation.jobsText')" :padding="false" class="lg:col-span-2">
            <div class="overflow-x-auto">
                <table class="table">
                    <thead><tr><th>{{ t('automation.job') }}</th><th>{{ t('automation.schedule') }}</th><th>{{ t('automation.lastRun') }}</th><th class="w-12"></th></tr></thead>
                    <tbody>
                        @foreach ($jobs as $j)
                            @php($label = t('automation.jobs.'.$j['name'].'.label'))
                            <tr>
                                <td class="font-medium">{{ $label }}</td>
                                <td class="text-muted-foreground">{{ t('automation.jobs.'.$j['name'].'.schedule') }}</td>
                                <td>
                                    @if ($j['last'])
                                        <span class="flex flex-wrap items-center gap-2">{{ dt($j['last']->started_at) }}
                                            @if ($j['last']->error)<x-badge color="red" title="{{ $j['last']->error }}">{{ t('automation.failed') }}</x-badge>
                                            @elseif ($j['last']->finished_at)<x-badge color="green">{{ t('automation.ok') }}</x-badge>
                                            @else<x-badge color="yellow">{{ t('automation.running') }}</x-badge>@endif
                                        </span>
                                    @else
                                        <span class="text-muted-foreground">{{ t('automation.never') }}</span>
                                    @endif
                                </td>
                                <td>
                                    <form method="POST" action="{{ route('automation.run', $j['name']) }}">@csrf
                                        <button class="btn btn-ghost btn-sm" aria-label="{{ t('automation.runNow', ['name' => $label]) }}" title="{{ t('automation.runNow', ['name' => $label]) }}"><x-icon name="play" /></button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-card>

        <div class="lg:col-span-2">@include('automation.company-card')</div>
    </div>
@endsection
