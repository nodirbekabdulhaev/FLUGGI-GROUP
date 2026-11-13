@extends('layouts.app')
@section('title', t('smm.finance.title'))
@section('content')
    @php
        use App\Services\Smm\SmmFinance;
        use App\Services\Smm\SmmVideoPay;
        use App\Support\Format;
        $num = fn ($v) => rtrim(rtrim((string) $v, '0'), '.');
        $statusTone = ['DRAFT' => 'gray', 'APPROVED' => 'blue', 'PAID' => 'green'];
    @endphp
    <x-page-header :title="t('smm.title')" :subtitle="t('smm.finance.subtitle')">
        <form method="GET" action="/smm/finance"><input type="month" name="month" value="{{ $period }}" class="input w-44" aria-label="{{ t('smm.month') }}" onchange="this.form.submit()"></form>
        <a href="/finance/payroll?period={{ $period }}" class="btn btn-outline"><x-icon name="wallet" /> {{ t('smm.finance.toPayroll') }}</a>
    </x-page-header>

    <x-tabs :items="['/smm?view=kanban' => t('smm.views.kanban'), '/smm?view=list' => t('smm.views.list'), '/smm/finance' => t('smm.views.finance')]" active="/smm/finance" />

    {{-- Итоги месяца --}}
    <div class="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <x-stat :label="t('smm.finance.cost')" :value="money($totals['cost'])" :hint="t('smm.finance.costHint', ['people' => money($totals['people']), 'other' => money($totals['other'])])" />
        <x-stat :label="t('smm.finance.videos')" :value="$totals['videos']" :hint="$totals['costPerVideo'] !== null ? t('smm.finance.perVideo', ['amount' => money($totals['costPerVideo'])]) : t('smm.finance.noVideos')" />
        <x-stat :label="t('smm.finance.revenue')" :value="money($totals['revenue'])" :hint="t('smm.finance.revenueHint', ['leads' => $totals['leads'], 'won' => $totals['won']])" />
        <x-stat :label="t('smm.finance.roi')" :value="$totals['roi'] !== null ? $totals['roi'].'%' : '—'" :tone="$totals['roi'] === null ? null : ((float) $totals['roi'] >= 0 ? 'good' : 'bad')"
                :hint="t('smm.finance.roiHint')" />
    </div>

    {{-- Оплата SMM по людям --}}
    <x-card class="mb-6" :title="t('smm.finance.peopleTitle')" :subtitle="t('smm.finance.peopleHint')" :padding="false">
        @if ($people->isEmpty())
            <x-empty icon="users" :title="t('smm.finance.peopleEmpty')">{{ t('smm.finance.peopleEmptyHint') }}</x-empty>
        @else
            <div class="overflow-x-auto"><table class="table">
                <thead><tr>
                    <th>{{ t('smm.finance.person') }}</th>
                    <th class="text-right">{{ t('smm.finance.fixed') }}</th>
                    <th class="text-right">{{ t('smm.finance.videoPay') }}</th>
                    <th class="text-right">{{ t('smm.finance.bonus') }}</th>
                    <th class="text-right">{{ t('smm.finance.total') }}</th>
                    <th class="text-right">{{ t('smm.finance.paid') }}</th>
                    <th class="text-right">{{ t('smm.finance.remaining') }}</th>
                </tr></thead>
                <tbody>
                @foreach ($people as $r)
                    <tr>
                        <td><span class="font-medium">{{ $r['user']->full_name }}</span>
                            <span class="block text-xs text-muted-foreground">{{ collect($r['specialties'])->map(fn ($s) => t('specialties.'.$s))
                                ->merge(collect($r['videoRoles'])->map(fn ($s) => t('smm.finance.roles.'.$s)))->unique()->implode(', ') ?: t('roles.'.$r['user']->role?->code) }}</span>
                            @if ($r['payrollStatus'])<x-badge :color="$statusTone[$r['payrollStatus']]">{{ t('payroll.status.'.$r['payrollStatus']) }}</x-badge>
                            @else<span class="text-xs text-muted-foreground">{{ t('smm.finance.notCalculated') }}</span>@endif
                        </td>
                        <td class="whitespace-nowrap text-right tabular-nums">{{ money($r['fixed']) }}
                            @if ($r['fixedSource'] === 'plan')<span class="block text-xs text-muted-foreground">{{ t('smm.finance.fixedPlan') }}</span>@endif</td>
                        <td class="whitespace-nowrap text-right tabular-nums">{{ money($r['videoPay']) }}
                            @if ($r['videoCount'])<span class="block text-xs text-muted-foreground">{{ $r['videoParts'] ? SmmVideoPay::partsText($r['videoParts']) : t('payroll.smmVideoCount', ['count' => $r['videoCount']]) }}</span>@endif</td>
                        <td class="whitespace-nowrap text-right tabular-nums">{{ money($r['bonus']) }}</td>
                        <td class="whitespace-nowrap text-right font-semibold tabular-nums">{{ money($r['total']) }}</td>
                        <td class="whitespace-nowrap text-right tabular-nums">{{ money($r['paid']) }}</td>
                        <td @class(['whitespace-nowrap text-right tabular-nums', 'text-warning' => (float) $r['remaining'] > 0])>{{ money($r['remaining']) }}</td>
                    </tr>
                @endforeach
                </tbody>
                <tfoot><tr class="font-semibold">
                    <td>{{ t('smm.finance.totalRow') }}</td>
                    <td class="whitespace-nowrap text-right tabular-nums">{{ money($totals['fixed']) }}</td>
                    <td class="whitespace-nowrap text-right tabular-nums">{{ money($totals['videoPay']) }}<span class="block text-xs font-normal text-muted-foreground">{{ t('payroll.smmVideoCount', ['count' => $totals['videoCount']]) }}</span></td>
                    <td class="whitespace-nowrap text-right tabular-nums">{{ money($totals['bonus']) }}</td>
                    <td class="whitespace-nowrap text-right tabular-nums">{{ money($totals['people']) }}</td>
                    <td class="whitespace-nowrap text-right tabular-nums">{{ money($totals['paid']) }}</td>
                    <td class="whitespace-nowrap text-right tabular-nums">{{ money($totals['remaining']) }}</td>
                </tr></tfoot>
            </table></div>
        @endif
        <p class="border-t px-4 py-3 text-xs text-muted-foreground">{{ t('smm.finance.payHint') }}</p>
    </x-card>

    <div class="grid gap-6 lg:grid-cols-3">
        {{-- Прочие расходы SMM --}}
        <x-card class="lg:col-span-2" :title="t('smm.finance.otherTitle')" :subtitle="t('smm.finance.otherHint')">
            <x-slot:actions>
                <button type="button" class="btn btn-primary btn-sm" x-data x-on:click="$dispatch('open-modal', 'smm-expense')"><x-icon name="plus" /> {{ t('smm.finance.addExpense') }}</button>
            </x-slot:actions>
            @if ($expenses->isEmpty())
                <x-empty icon="wallet" :title="t('smm.finance.otherEmpty')" />
            @else
                <ul class="divide-y">
                    @foreach ($expenses as $x)
                        <li class="flex flex-wrap items-start justify-between gap-3 py-2 text-sm">
                            <div class="min-w-0 flex-1">
                                <p class="font-medium">{{ $x->category }} <span class="text-xs font-normal text-muted-foreground">· {{ d($x->date) }}</span></p>
                                @if ($x->comment)<p class="whitespace-pre-wrap text-xs text-muted-foreground">{{ $x->comment }}</p>@endif
                                <p class="text-xs text-muted-foreground">{{ $x->createdBy?->full_name }}@if ($x->transaction && ! $x->transaction->trashed()) · {{ t('smm.finance.inAccounting', ['account' => $x->transaction->account?->name]) }}@endif</p>
                            </div>
                            <div class="flex items-center gap-2">
                                <span class="whitespace-nowrap font-semibold tabular-nums">{{ money($x->amount_uzs) }}</span>
                                <form method="POST" action="{{ route('smm.finance.expenses.destroy', $x->id) }}" onsubmit="return confirm(@js(t('smm.finance.deleteConfirm')))">
                                    @csrf @method('DELETE')
                                    <button class="btn btn-ghost btn-icon" aria-label="{{ t('smm.finance.deleteExpense') }}"><x-icon name="trash" /></button>
                                </form>
                            </div>
                        </li>
                    @endforeach
                </ul>
                <p class="mt-2 border-t pt-2 text-right text-sm">{{ t('smm.finance.otherTotal') }}: <span class="font-semibold tabular-nums">{{ money($totals['other']) }}</span></p>
            @endif
        </x-card>

        {{-- Ставки за ролик --}}
        <x-card :title="t('smm.finance.ratesTitle')" :subtitle="t('smm.finance.ratesHint')">
            <form method="POST" action="{{ route('smm.finance.rates') }}" class="grid gap-3">
                @csrf @method('PUT')
                <input type="hidden" name="month" value="{{ $period }}">
                @foreach (['VIDEOGRAPHER' => 'rate_videographer', 'MOBILOGRAPHER' => 'rate_mobilographer', 'EDITOR' => 'rate_editor'] as $role => $field)
                    <x-field :name="$field" inputmode="decimal" data-money :label="t('smm.finance.roles.'.$role)" :value="$num($settings['rates'][$role])" placeholder="0" />
                @endforeach
                <input type="hidden" name="pay_assignee" value="0">
                <label class="flex items-start gap-2 text-sm">
                    <input type="checkbox" name="pay_assignee" value="1" class="mt-0.5" @checked(old('pay_assignee', $settings['payAssignee']))>
                    <span><span class="font-medium">{{ t('smm.finance.payAssignee') }}</span><span class="block text-xs text-muted-foreground">{{ t('smm.finance.payAssigneeHint') }}</span></span>
                </label>
                <p class="text-xs text-muted-foreground">{{ t('smm.finance.ratesNote') }}</p>
                @if ($personal->isNotEmpty())
                    <div class="rounded-md bg-muted/50 p-3 text-xs">
                        <p class="font-medium">{{ t('smm.finance.personalList') }}</p>
                        <ul class="mt-1 grid gap-1">
                            @foreach ($personal as $u)
                                <li class="flex justify-between gap-2"><span>@if ($canPersonal)<a href="/team/employees?rates={{ $u->id }}" class="link">{{ $u->full_name }}</a>@else{{ $u->full_name }}@endif</span>
                                    <span class="tabular-nums">{{ money($u->employee->smm_video_rate) }}</span></li>
                            @endforeach
                        </ul>
                    </div>
                @endif
                <div class="flex justify-end"><button class="btn btn-primary">{{ t('common.save') }}</button></div>
            </form>
        </x-card>
    </div>

    <x-modal name="smm-expense" :title="t('smm.finance.addExpense')" :open="$errors->any() && old('_form') === 'smm-expense'">
        <form method="POST" action="{{ route('smm.finance.expenses.store') }}" class="grid gap-4" x-data="{ acc: @js((bool) old('to_accounting', false)) }">
            @csrf
            <input type="hidden" name="_form" value="smm-expense">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-field name="date" type="date" :label="t('smm.finance.date')" :value="old('date', str_starts_with(Format::today()->format('Y-m-d'), $period) ? Format::today()->format('Y-m-d') : $period.'-01')" required />
                <x-field name="amount" inputmode="decimal" data-money :label="t('smm.finance.amount')" required />
            </div>
            <div>
                <x-field name="category" :label="t('smm.finance.category')" list="smm-expense-categories" maxlength="100" required />
                <datalist id="smm-expense-categories">
                    @foreach (SmmFinance::CATEGORIES as $c)<option value="{{ t('smm.finance.categories.'.$c) }}"></option>@endforeach
                </datalist>
            </div>
            <x-field name="comment" type="textarea" rows="2" :label="t('smm.finance.comment')" maxlength="1000" />
            @if ($canAccounting)
                <input type="hidden" name="to_accounting" value="0">
                <label class="flex items-start gap-2 text-sm">
                    <input type="checkbox" name="to_accounting" value="1" class="mt-0.5" x-model="acc">
                    <span><span class="font-medium">{{ t('smm.finance.toAccounting') }}</span><span class="block text-xs text-muted-foreground">{{ t('smm.finance.toAccountingHint') }}</span></span>
                </label>
                <div class="grid gap-4 sm:grid-cols-2" x-show="acc" x-cloak>
                    <x-select name="account_id" :label="t('smm.finance.account')" :options="$accounts->pluck('name', 'id')->all()" :placeholder="t('crm.common.select')"
                              :hint="$accounts->isEmpty() ? t('smm.finance.noAccounts') : null" />
                    <x-select name="article" :label="t('smm.finance.article')" :options="$articles" :value="$defaultArticle" />
                </div>
            @endif
            <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <button type="button" class="btn btn-outline" x-on:click="$dispatch('close-modal', 'smm-expense')">{{ t('common.cancel') }}</button>
                <button class="btn btn-primary">{{ t('common.save') }}</button>
            </div>
        </form>
    </x-modal>
@endsection
