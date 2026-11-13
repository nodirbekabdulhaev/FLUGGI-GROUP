@extends('layouts.app')
@section('title', $user->full_name)
@section('content')
    @php
        use App\Services\People\AdvanceService;
        use App\Services\People\EmployeeScore;
        use App\Support\Format;
        $role = $user->role->code;
        $emp = $user->employee;
        $specs = $emp?->specialtyList() ?? [];
        $payTone = ['DRAFT' => 'gray', 'APPROVED' => 'blue', 'PAID' => 'green'];
        $comTone = ['ACCRUED' => 'yellow', 'APPROVED' => 'blue', 'PAID' => 'green', 'CANCELLED' => 'gray'];
        $num = fn ($v) => rtrim(rtrim((string) $v, '0'), '.');
        $monthLabel = fn (string $p) => \Carbon\CarbonImmutable::createFromFormat('!Y-m', $p)->format('m.Y');
        $tabs = array_filter([
            'score' => $canScore ? t('employees.card.tabs.score') : null,
            'salary' => $canSalary ? t('employees.card.tabs.salary') : null,
            'commissions' => $canCommissions ? t('employees.card.tabs.commissions') : null,
        ]);
        $firstTab = array_key_first($tabs) ?? 'score';
        $backUrl = can('employee.read') ? '/team/employees' : null;
    @endphp
    @include('crm.partials.errors')

    <x-page-header :title="t('employees.card.title')" :back="$backUrl">
        <form method="GET" class="flex items-center gap-2">
            <label for="emp-period" class="sr-only">{{ t('employees.card.month') }}</label>
            <input id="emp-period" type="month" name="period" value="{{ $period }}" class="input w-44" onchange="this.form.submit()">
        </form>
        @if ($canManage)
            <a href="/team/employees/{{ $user->id }}?period={{ $period }}&edit=1" class="btn btn-outline"><x-icon name="edit" /> {{ t('common.edit') }}</a>
        @endif
    </x-page-header>

    {{-- Шапка: кто это и как связаться --}}
    <section class="card card-body mb-6">
        <div class="flex items-start gap-3 sm:gap-4">
            <x-avatar :name="$user->full_name" class="!size-12 !text-base sm:!size-16 sm:!text-xl" />
            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-2">
                    <h1 class="break-words text-xl font-semibold tracking-tight">{{ $user->full_name }}</h1>
                    <x-badge :color="$user->status === 'ACTIVE' ? 'green' : 'red'">{{ t('userStatus.'.$user->status) }}</x-badge>
                    @if ($score && $score['score'] !== null)
                        <x-badge :color="EmployeeScore::color($score['grade'])">{{ $score['grade'] }} · {{ $score['score'] }}</x-badge>
                    @endif
                </div>
                <p class="mt-1 text-sm text-muted-foreground">
                    {{ t('roles.'.$role) }}@if ($emp?->position && $emp->position !== t('roles.'.$role)) · {{ $emp->position }}@endif
                    @if ($user->team) · {{ $user->team->name }}@endif
                </p>
                @if ($specs)<p class="mt-1 text-sm">{{ collect($specs)->map(fn ($s) => t('specialties.'.$s))->implode(', ') }}</p>@endif
                @if ($user->directions->isNotEmpty())<p class="mt-1 text-sm text-muted-foreground">{{ $user->directions->map(fn ($dd) => $dd->direction?->name)->filter()->implode(', ') }}</p>@endif
                <dl class="mt-3 grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2 lg:grid-cols-4">
                    <div class="min-w-0"><dt class="text-xs text-muted-foreground">{{ t('employees.email') }}</dt><dd class="truncate"><a class="link" href="mailto:{{ $user->email }}">{{ $user->email }}</a></dd></div>
                    <div class="min-w-0"><dt class="text-xs text-muted-foreground">{{ t('employees.phone') }}</dt><dd class="truncate">@if ($user->phone)<a class="link" href="tel:{{ preg_replace('/[^0-9+]/', '', $user->phone) }}">{{ $user->phone }}</a>@else — @endif</dd></div>
                    <div class="min-w-0"><dt class="text-xs text-muted-foreground">{{ t('employees.card.telegram') }}</dt><dd class="truncate">{{ $user->telegram_username ? '@'.ltrim($user->telegram_username, '@') : ($user->telegram_chat_id ? t('employees.card.telegramLinked') : '—') }}</dd></div>
                    <div class="min-w-0"><dt class="text-xs text-muted-foreground">{{ t('employees.card.hiredAt') }}</dt><dd>{{ $emp?->hired_at ? d($emp->hired_at) : '—' }}@if ($emp?->fired_at) · {{ t('employees.card.firedAt') }} {{ d($emp->fired_at) }}@endif</dd></div>
                </dl>
            </div>
        </div>
    </section>

    @if (! $tabs)
        <x-card><x-empty icon="lock" :title="t('employees.card.nothing')" /></x-card>
    @else
    <div x-data="{ tab: (location.hash || '#{{ $firstTab }}').slice(1) }" x-on:hashchange.window="tab = (location.hash || '#{{ $firstTab }}').slice(1)">
        @if (count($tabs) > 1)
            <nav class="tabs">
                @foreach ($tabs as $key => $label)
                    <a href="#{{ $key }}" class="tab" :class="tab === '{{ $key }}' && 'tab-active'">{{ $label }}</a>
                @endforeach
            </nav>
        @endif

        {{-- Эффективность за месяц --}}
        @if ($canScore)
            <div x-show="tab === 'score'" x-cloak class="grid grid-cols-[minmax(0,1fr)] gap-6 lg:grid-cols-3">
                <x-card :title="t('employees.score.title', ['period' => $monthLabel($period)])" :subtitle="t('employees.score.subtitle')" class="lg:col-span-2">
                    @if ($score['score'] === null)
                        <x-empty icon="gauge" :title="t('employees.score.noData')">{{ t('employees.score.noDataText') }}</x-empty>
                    @else
                        <div class="mb-5 flex items-center gap-4">
                            <div @class(['flex size-20 shrink-0 flex-col items-center justify-center rounded-full text-center ring-4',
                                'bg-success-soft ring-success/20' => $score['grade'] === 'A', 'bg-sky-soft ring-sky/30' => $score['grade'] === 'B',
                                'bg-warning-soft ring-warning/30' => $score['grade'] === 'C', 'bg-danger-soft ring-danger/20' => $score['grade'] === 'D'])>
                                <span class="text-2xl font-semibold tabular-nums leading-none">{{ $score['score'] }}</span>
                                <span class="text-xs font-medium text-muted-foreground">{{ t('employees.score.grade', ['grade' => $score['grade']]) }}</span>
                            </div>
                            <p class="min-w-0 text-sm text-muted-foreground">{{ t('employees.score.gradeHint.'.$score['grade']) }}</p>
                        </div>
                        <ul class="divide-y">
                            @foreach ($score['components'] as $c)
                                <li class="py-3">
                                    <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                                        <span class="font-medium">{{ t('employees.score.c.'.$c['key']) }}</span>
                                        <span class="text-sm tabular-nums">
                                            @if ($c['score'] === null)
                                                <span class="text-muted-foreground">{{ t('employees.score.excluded') }}</span>
                                            @else
                                                <span class="font-semibold">{{ (int) round($c['score']) }}</span><span class="text-muted-foreground"> / 100 · {{ t('employees.score.weight', ['pct' => rtrim(rtrim(number_format($c['share'], 1, '.', ''), '0'), '.')]) }}</span>
                                            @endif
                                        </span>
                                    </div>
                                    <p class="mt-0.5 text-xs text-muted-foreground">{{ t('employees.score.fact') }}: {{ $c['value'] }} · {{ t('employees.score.target') }}: {{ $c['target'] }}</p>
                                    @if ($c['score'] !== null)
                                        <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-muted"><div class="h-full rounded-full bg-gradient-to-r from-sky to-periwinkle" style="width: {{ min(100, (int) round($c['score'])) }}%"></div></div>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                    <details class="mt-4 text-xs text-muted-foreground">
                        <summary class="cursor-pointer select-none font-medium">{{ t('employees.score.howTitle') }}</summary>
                        <p class="mt-2">{{ t('employees.score.how') }}</p>
                        <ul class="mt-2 list-disc space-y-0.5 pl-4">
                            @foreach (EmployeeScore::WEIGHTS[$role] ?? ['todos' => 100] as $k => $w)
                                <li>{{ t('employees.score.c.'.$k) }} — {{ t('employees.score.weightOf', ['weight' => $w]) }}. {{ t('employees.score.d.'.$k) }}</li>
                            @endforeach
                        </ul>
                    </details>
                </x-card>
                <div class="grid min-w-0 content-start gap-6">
                    <x-card :title="t('employees.score.trend')">
                        @php($hasTrend = collect($trend)->contains(fn ($p) => $p['score'] !== null))
                        @include('dashboard.partials.chart', ['empty' => ! $hasTrend, 'height' => 'h-44', 'label' => t('employees.score.trend'), 'config' => [
                            'type' => 'line', 'format' => 'number', 'legend' => false,
                            'labels' => array_map(fn ($p) => $monthLabel($p['period']), $trend),
                            'series' => [['label' => t('employees.score.title', ['period' => '']), 'data' => array_map(fn ($p) => $p['score'], $trend)]],
                        ]])
                        <ul class="mt-3 grid grid-cols-3 gap-2 text-center text-xs sm:grid-cols-6 lg:grid-cols-3">
                            @foreach ($trend as $p)
                                <li class="rounded-lg bg-muted/40 px-1 py-1.5"><span class="block text-muted-foreground">{{ $monthLabel($p['period']) }}</span>
                                    <span class="font-semibold tabular-nums">{{ $p['score'] ?? '—' }}</span>@if ($p['score'] !== null) <span class="text-muted-foreground">{{ EmployeeScore::grade($p['score']) }}</span>@endif</li>
                            @endforeach
                        </ul>
                    </x-card>
                    <x-card :title="t('employees.activity.title', ['period' => $monthLabel($period)])" :padding="false">
                        <ul class="divide-y text-sm">
                            @foreach ($activity as $a)
                                <li class="flex items-center justify-between gap-3 px-4 py-2.5 sm:px-5">
                                    <span class="min-w-0 text-muted-foreground">{{ t('employees.activity.'.$a['key']) }}</span>
                                    @if ($a['url'])
                                        <a href="{{ $a['url'] }}" class="link shrink-0 font-semibold tabular-nums">{{ $a['value'] }}</a>
                                    @else
                                        <span class="shrink-0 font-semibold tabular-nums">{{ $a['value'] }}</span>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </x-card>
                </div>
            </div>
        @endif

        {{-- Зарплата по месяцам и авансы --}}
        @if ($canSalary)
            <div x-show="tab === 'salary'" x-cloak class="grid grid-cols-[minmax(0,1fr)] gap-6">
                <x-card :title="t('employees.salary.title')" :subtitle="t('employees.salary.subtitle')" :padding="false">
                    <x-slot:actions>
                        @if ($canAdvance)
                            <button type="button" class="btn btn-primary btn-sm" x-on:click="$dispatch('open-modal', 'advance')"><x-icon name="plus" /> {{ t('payroll.advance.button') }}</button>
                        @endif
                    </x-slot:actions>
                    @if ($payroll->isEmpty())
                        <x-empty icon="wallet" :title="t('employees.salary.empty')">{{ t('employees.salary.emptyText') }}</x-empty>
                    @else
                        <div class="overflow-x-auto"><table class="table">
                            <thead><tr>
                                <th>{{ t('employees.salary.month') }}</th>
                                <th class="text-right">{{ t('payroll.base') }}</th>
                                <th class="text-right">{{ t('payroll.pieceRate') }}</th>
                                <th class="text-right">{{ t('employees.salary.bonuses') }}</th>
                                <th class="text-right">{{ t('payroll.commission') }}</th>
                                <th class="text-right">{{ t('payroll.penalty') }}</th>
                                <th class="text-right">{{ t('payroll.final') }}</th>
                                <th class="text-right">{{ t('payroll.advance.column') }}</th>
                                <th class="text-right">{{ t('payroll.payable') }}</th>
                            </tr></thead>
                            <tbody>
                            @foreach ($payroll as $e)
                                <tr>
                                    <td class="whitespace-nowrap"><a class="link font-medium" href="/finance/payroll?period={{ $e->period }}">{{ $monthLabel($e->period) }}</a>
                                        <span class="mt-1 block"><x-badge :color="$payTone[$e->status]">{{ t('payroll.status.'.$e->status) }}</x-badge></span></td>
                                    <td class="whitespace-nowrap text-right tabular-nums">{{ money($e->base_salary) }}
                                        @if ($e->attendance_pct !== null)<span class="block text-xs text-muted-foreground">{{ t('employees.salary.attendance', ['pct' => $num($e->attendance_pct)]) }}</span>@endif
                                        @if ($e->videos_target !== null)<span class="block text-xs text-muted-foreground">{{ t('employees.salary.videos', ['published' => (int) $e->videos_published, 'target' => $e->videos_target]) }}</span>@endif
                                    </td>
                                    <td class="whitespace-nowrap text-right tabular-nums">{{ (float) $e->piece_rate ? money($e->piece_rate) : ((float) $e->smm_video_pay ? '' : '—') }}
                                        @if ((float) $e->smm_video_pay)<span class="block text-xs text-muted-foreground">{{ t('payroll.smmVideo') }}: {{ money($e->smm_video_pay) }}</span>@endif</td>
                                    <td class="whitespace-nowrap text-right tabular-nums">{{ money(\Brick\Math\BigDecimal::of((string) $e->kpi_bonus)->plus((string) $e->other_bonus)) }}</td>
                                    <td class="whitespace-nowrap text-right tabular-nums">{{ money($e->commission) }}</td>
                                    <td @class(['whitespace-nowrap text-right tabular-nums', 'text-danger' => (float) $e->penalty])>{{ (float) $e->penalty ? '− '.money($e->penalty) : '—' }}</td>
                                    <td class="whitespace-nowrap text-right tabular-nums">{{ money($e->final_salary) }}</td>
                                    <td @class(['whitespace-nowrap text-right tabular-nums', 'text-danger' => (float) $e->advances])>{{ (float) $e->advances ? '− '.money($e->advances) : '—' }}</td>
                                    <td class="whitespace-nowrap text-right font-semibold tabular-nums">{{ money(AdvanceService::payable($e)) }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table></div>
                    @endif
                    <p class="border-t px-4 py-3 text-xs text-muted-foreground sm:px-5">{{ t('employees.salary.hint') }} <a class="link" href="/finance/payroll?period={{ $period }}">{{ t('employees.salary.open', ['period' => $monthLabel($period)]) }}</a></p>
                </x-card>

                <x-card :title="t('payroll.advance.listTitle')" :padding="false">
                    @if ($advances->isEmpty())
                        <x-empty icon="wallet" :title="t('payroll.advance.empty')" />
                    @else
                        <ul class="divide-y text-sm">
                            @foreach ($advances as $a)
                                <li class="flex flex-wrap items-start gap-x-4 gap-y-1 px-4 py-3 sm:px-5" x-data="{ cancel: false }">
                                    <div class="min-w-0 flex-1">
                                        <p @class(['font-semibold tabular-nums', 'line-through text-muted-foreground' => $a->cancelled_at])>{{ money($a->amount) }}</p>
                                        <p class="text-xs text-muted-foreground">{{ t('payroll.advance.line', ['date' => d($a->paid_at), 'period' => $monthLabel($a->period)]) }}@if ($a->createdBy) · {{ $a->createdBy->full_name }}@endif</p>
                                        @if ($a->comment)<p class="mt-0.5 text-xs">{{ $a->comment }}</p>@endif
                                        @if ($a->accounting_transaction_id)<p class="mt-0.5 text-xs text-muted-foreground">{{ t('payroll.advance.inAccounting', ['account' => $a->transaction?->account?->name ?? '—']) }}</p>@endif
                                        @if ($a->cancelled_at)<p class="mt-0.5 text-xs text-danger">{{ t('payroll.advance.cancelledLine', ['date' => dt($a->cancelled_at), 'reason' => $a->cancel_reason]) }}</p>@endif
                                    </div>
                                    @if ($a->cancelled_at)
                                        <x-badge color="gray">{{ t('payroll.advance.cancelled') }}</x-badge>
                                    @elseif ($canAdvance)
                                        <button type="button" class="btn btn-ghost btn-sm text-danger" x-on:click="cancel = !cancel">{{ t('payroll.advance.cancel') }}</button>
                                        <form x-show="cancel" x-cloak method="POST" action="{{ route('payroll.advances.cancel', $a->id) }}" class="flex w-full flex-col gap-2 sm:flex-row">
                                            @csrf
                                            <input name="reason" class="input sm:flex-1" required minlength="3" maxlength="500" placeholder="{{ t('payroll.advance.reason') }}" aria-label="{{ t('payroll.advance.reason') }}">
                                            <button class="btn btn-danger btn-sm">{{ t('payroll.advance.cancelConfirm') }}</button>
                                        </form>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </x-card>
            </div>
            @if ($canAdvance)
                @include('people.partials.advance-form', ['advanceUser' => $user])
            @endif
        @endif

        {{-- Комиссии по месяцам: сделка и проект из неё --}}
        @if ($canCommissions)
            <div x-show="tab === 'commissions'" x-cloak class="grid grid-cols-[minmax(0,1fr)] gap-6">
                @forelse ($commissions as $p => $group)
                    <x-card :padding="false">
                        <x-slot:title>{{ $monthLabel($p) }}</x-slot:title>
                        <x-slot:actions><span class="text-sm font-semibold tabular-nums">{{ money($group['total']) }}</span></x-slot:actions>
                        <div class="flex flex-wrap gap-2 border-b px-4 py-2 text-xs sm:px-5">
                            @foreach ($group['byStatus'] as $st => $sum)
                                <x-badge :color="$comTone[$st] ?? 'gray'">{{ t('employees.commissions.status.'.$st) }}: {{ money($sum) }}</x-badge>
                            @endforeach
                            @if (can('commission.read'))<a class="link ml-auto" href="/finance/commissions?period={{ $p }}&user_id={{ $user->id }}">{{ t('employees.commissions.open') }}</a>@endif
                        </div>
                        <ul class="divide-y text-sm">
                            @foreach ($group['rows'] as $c)
                                @php($project = $c->payment?->project ?? $c->deal?->project)
                                <li class="flex flex-wrap items-start gap-x-4 gap-y-1 px-4 py-3 sm:px-5">
                                    <div class="min-w-0 flex-1">
                                        @if ($c->deal)
                                            <a href="/sales/deals/{{ $c->deal_id }}" class="link font-medium">{{ Format::code('D', $c->deal->number) }} · {{ $c->deal->title }}</a>
                                        @endif
                                        <p class="text-xs text-muted-foreground">
                                            @if ($project)
                                                {{ t('employees.commissions.project') }}: <a href="/projects/{{ $project->id }}" class="link">{{ Format::code('P', $project->number) }} · {{ $project->name }}</a>
                                            @else
                                                {{ t('employees.commissions.noProject') }}
                                            @endif
                                            @if ($c->payment) · {{ t('employees.commissions.payment') }} {{ Format::code('PAY', $c->payment->number) }}@endif
                                            · {{ t('employees.commissions.role.'.$c->role) }}
                                        </p>
                                    </div>
                                    <div class="flex shrink-0 items-center gap-2">
                                        <span @class(['font-semibold tabular-nums', 'line-through text-muted-foreground' => $c->status === 'CANCELLED'])>{{ money($c->amount_uzs) }}</span>
                                        <x-badge :color="$comTone[$c->status] ?? 'gray'">{{ t('employees.commissions.status.'.$c->status) }}</x-badge>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    </x-card>
                @empty
                    <x-card><x-empty icon="dollar" :title="t('employees.commissions.empty')" /></x-card>
                @endforelse
            </div>
        @endif
    </div>
    @endif

    @if ($editing)
        <x-modal name="emp-edit" :title="t('employees.editTitle')" :open="true">
            @include('people.partials.employee-form', ['user' => $editing, 'slug' => 'employees', 'fixedRole' => null, 'returnTo' => '/team/employees/'.$user->id])
        </x-modal>
    @endif
@endsection
