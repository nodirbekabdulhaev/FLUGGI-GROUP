@extends('layouts.app')
@section('title', $a->name)
@section('content')
    @php
        $p = $a->profile ?? [];
        $as = $a->assessment ?? [];
        $parts = $a->score_parts['parts'] ?? [];
        $flags = $a->score_parts['flags'] ?? [];
        $flagOf = ['ru' => '🇷🇺', 'uz' => '🇺🇿', 'en' => '🇬🇧', 'tr' => '🇹🇷', 'kk' => '🇰🇿', 'kz' => '🇰🇿', 'tg' => '🇹🇯', 'ky' => '🇰🇬', 'de' => '🇩🇪', 'fr' => '🇫🇷', 'es' => '🇪🇸', 'it' => '🇮🇹', 'zh' => '🇨🇳', 'ko' => '🇰🇷', 'ja' => '🇯🇵', 'ar' => '🇸🇦', 'fa' => '🇮🇷', 'hi' => '🇮🇳'];
        $tone = ['excellent' => 'text-success', 'strong' => 'text-primary', 'medium' => 'text-warning', 'weak' => 'text-danger'][$a->score_level] ?? 'text-muted-foreground';
        $lbl = fn (string $g, $k) => ($x = t('vacancies.'.$g.'.'.$k)) !== 'vacancies.'.$g.'.'.$k ? $x : (string) $k;
        $site = isset($as['grade']) || isset($as['score']);
        $city = $p['city'] ?? ($as['city'] ?? null);
        $bar = fn ($v) => $v >= 80 ? 'bg-success' : ($v >= 65 ? 'bg-primary' : ($v >= 50 ? 'bg-warning' : 'bg-danger'));
    @endphp
    <style>
        @keyframes vac-grow { from { width: 0 } }
        @keyframes vac-pop { from { opacity: 0; transform: translateY(12px) scale(.96) } to { opacity: 1; transform: none } }
        .vac-bar > span { animation: vac-grow 1.1s ease-out both }
        .vac-pop { animation: vac-pop .6s ease-out both }
        @media (prefers-reduced-motion: reduce) { .vac-bar > span, .vac-pop { animation: none } }
    </style>
    <x-page-header :title="$a->name" :subtitle="$a->vacancy_name ?: t('vacancies.noVacancy')" back="/vacancies">
        @if ($canManage)
            <form method="POST" action="/vacancies/{{ $a->id }}" onsubmit="return confirm(@js(t('vacancies.deleteConfirm')))">@csrf @method('DELETE')
                <button class="btn btn-outline" aria-label="{{ t('common.delete') }}"><x-icon name="trash" /> {{ t('common.delete') }}</button></form>
        @endif
    </x-page-header>

    <div class="grid gap-4 lg:grid-cols-3">
        <div class="space-y-4 lg:col-span-1">
            <x-card class="vac-pop text-center">
                @if (! empty($p['photo_url']))
                    <img src="{{ $p['photo_url'] }}" alt="{{ $a->name }}" referrerpolicy="no-referrer" loading="lazy" class="mx-auto size-40 rounded-full object-cover ring-4 ring-primary/30">
                @else
                    <div class="mx-auto flex size-40 items-center justify-center rounded-full bg-primary/10 text-5xl font-bold text-primary">{{ mb_strtoupper(mb_substr($a->name, 0, 1)) }}</div>
                @endif
                <h2 class="mt-3 text-lg font-semibold">{{ $a->name }}</h2>
                <p class="text-sm text-muted-foreground">@if ($city){{ $city }}@endif @if (! empty($p['birth_year'])) · {{ t('vacancies.age', ['n' => (int) date('Y') - (int) $p['birth_year']]) }}@endif</p>
                <p class="mt-2 text-sm"><a class="link" href="tel:{{ $a->phone }}">{{ $a->phone }}</a>@if ($a->email)<br><a class="link" href="mailto:{{ $a->email }}">{{ $a->email }}</a>@endif</p>
                @php $links = array_filter([...($p['links'] ?? []), $as['link'] ?? null]); @endphp
                @foreach ($links as $l)<a class="link block truncate text-xs" href="{{ $l }}" target="_blank" rel="noopener noreferrer">{{ $l }}</a>@endforeach
            </x-card>

            <x-card :title="t('vacancies.score')" class="vac-pop text-center">
                @if ($a->score === null)
                    <p class="text-sm text-muted-foreground">{{ t('vacancies.noScore') }}</p>
                @else
                    <p class="text-5xl font-bold tabular-nums {{ $tone }}">{{ $a->score }}<span class="text-xl text-muted-foreground">/100</span></p>
                    <p class="mt-1 font-medium {{ $tone }}">{{ t('vacancies.levels.'.$a->score_level) }}@if (! empty($as['grade'])) · {{ t('vacancies.grade') }} {{ $as['grade'] }}@endif</p>
                    @if (! empty($as['recommendation']))<p class="mt-1 text-sm">{{ $lbl('recommendation', $as['recommendation']) }}</p>@endif
                    @if (! empty($as['archetype']))<p class="mt-2 inline-flex items-center gap-2 rounded-full bg-primary/10 px-3 py-1 text-sm font-medium text-primary">🎮 {{ $lbl('archetype', $as['archetype']) }}@if (isset($as['level'])) · {{ t('vacancies.gameLevel', ['n' => $as['level']]) }}@endif</p>@endif
                    <div class="mt-4 space-y-2 text-left">
                        @foreach ($as['parts'] ?? [] as $k => $v)
                            <div><div class="flex justify-between text-xs"><span>{{ $lbl('siteParts', $k) }}</span><span class="tabular-nums">{{ $k === 'penalty' ? '−' : '' }}{{ (int) $v }}</span></div>
                                <div class="vac-bar h-2 overflow-hidden rounded-full bg-muted"><span class="block h-full rounded-full {{ $k === 'penalty' ? 'bg-danger' : $bar((float) $v) }}" style="width: {{ max(0, min(100, (float) $v)) }}%"></span></div></div>
                        @endforeach
                        @foreach ($parts as $k => $part)
                            <div><div class="flex justify-between text-xs"><span>{{ t('vacancies.parts.'.$k) }} <span class="text-muted-foreground">· {{ $part['weight'] }}%</span></span><span class="tabular-nums">{{ $part['score'] }}</span></div>
                                <div class="vac-bar h-2 overflow-hidden rounded-full bg-muted"><span class="block h-full rounded-full {{ $bar($part['score']) }}" style="width: {{ $part['score'] }}%"></span></div></div>
                        @endforeach
                    </div>
                    @if ($flags)<p class="mt-3 flex items-start gap-1 text-left text-xs text-danger"><x-icon name="alert" /> {{ t('vacancies.redFlags') }}: {{ collect($flags)->map(fn ($f) => t('vacancies.traits.'.$f))->implode(', ') }}</p>@endif
                    <p class="mt-3 text-left text-xs text-muted-foreground">{{ $site ? t('vacancies.scoreHowSite') : t('vacancies.scoreHow') }}</p>
                @endif
            </x-card>
        </div>

        <div class="space-y-4 lg:col-span-2">
            @if (! empty($p['about']) || $a->message)
                <x-card :title="t('vacancies.about')" class="vac-pop"><p class="whitespace-pre-line text-sm">{{ $p['about'] ?? $a->message }}</p>
                    @if (! empty($p['about']) && $a->message)<p class="mt-2 whitespace-pre-line border-t pt-2 text-sm text-muted-foreground">{{ $a->message }}</p>@endif</x-card>
            @endif

            @if (! empty($p['skills']))
                <x-card :title="t('vacancies.skills')" class="vac-pop">
                    <div class="grid gap-3 sm:grid-cols-2">
                        @foreach ($p['skills'] as $s)
                            @php $lv = max(1, min(5, (float) ($s['level'] ?? 3))); @endphp
                            <div><div class="flex justify-between text-sm"><span class="font-medium">{{ $s['name'] }}</span><span class="text-xs text-muted-foreground">{{ rtrim(rtrim(number_format($lv, 1), '0'), '.') }}/5</span></div>
                                <div class="vac-bar h-2.5 overflow-hidden rounded-full bg-muted"><span class="block h-full rounded-full bg-gradient-to-r from-primary to-success" style="width: {{ $lv * 20 }}%"></span></div></div>
                        @endforeach
                    </div>
                </x-card>
            @endif

            @if (! empty($p['education']))
                <x-card :title="t('vacancies.education')" class="vac-pop">
                    <ul class="space-y-2">
                        @foreach ($p['education'] as $e)
                            @php
                                $eName = $e['name'] ?? $e['institution'] ?? $e['school'] ?? $e['title'] ?? null;
                                $eLevel = isset($e['level']) ? $lbl('edu', $e['level']) : null;
                                $eUsed = ['name', 'institution', 'school', 'title', 'level'];
                                $eRest = collect($e)->except($eUsed)->filter(fn ($x) => is_scalar($x))->map(fn ($x) => (string) $x)->implode(' · ');
                            @endphp
                            <li class="flex gap-3 text-sm"><span class="mt-0.5 text-lg">{{ ['school' => '🏫', 'college' => '🎓', 'courses' => '📚', 'bachelor' => '🏛️', 'university' => '🏛️', 'master' => '🎓'][$e['level'] ?? ''] ?? '📘' }}</span>
                                <span><span class="font-medium">{{ $eName ?? $eLevel ?? '—' }}</span>@if ($eName && $eLevel) <span class="text-muted-foreground">· {{ $eLevel }}</span>@endif
                                    @if ($eRest)<span class="block text-xs text-muted-foreground">{{ $eRest }}</span>@endif</span></li>
                        @endforeach
                    </ul>
                </x-card>
            @endif

            @if (! empty($p['languages']))
                <x-card :title="t('vacancies.languages')" class="vac-pop">
                    <div class="flex flex-wrap gap-2">
                        @foreach ($p['languages'] as $l)
                            <span class="inline-flex items-center gap-2 rounded-full border px-3 py-1.5 text-sm"><span class="text-xl leading-none">{{ $flagOf[strtolower($l['code'])] ?? '🌐' }}</span>{{ t('vacancies.lang.'.strtolower($l['code'])) !== 'vacancies.lang.'.strtolower($l['code']) ? t('vacancies.lang.'.strtolower($l['code'])) : strtoupper($l['code']) }}
                                @if (! empty($l['level']))<span class="rounded bg-primary/10 px-1.5 text-xs font-semibold text-primary">{{ strtoupper($l['level']) }}</span>@endif</span>
                        @endforeach
                    </div>
                </x-card>
            @endif

            @if ($site && (! empty($as['skills']) || ! empty($as['motivators']) || ! empty($as['experience']) || ! empty($as['badges']) || ! empty($as['flags'])))
                <x-card :title="t('vacancies.gameCard')" class="vac-pop">
                    <div class="space-y-3 text-sm">
                        @if (! empty($as['experience']))<p><span class="text-muted-foreground">{{ t('vacancies.experience') }}:</span> <span class="font-medium">{{ $lbl('exp', $as['experience']) }}</span></p>@endif
                        @foreach (['skills' => 'skill', 'motivators' => 'motivator', 'badges' => 'badge'] as $key => $grp)
                            @if (! empty($as[$key]))<div><p class="mb-1 text-xs text-muted-foreground">{{ t('vacancies.'.$key) }}</p><div class="flex flex-wrap gap-1.5">@foreach ($as[$key] as $x)<span class="rounded-full border px-2.5 py-1 text-xs">{{ $lbl($grp, $x) }}</span>@endforeach</div></div>@endif
                        @endforeach
                        @if (! empty($as['flags']))<div><p class="mb-1 flex items-center gap-1 text-xs text-danger"><x-icon name="alert" class="size-3.5" />{{ t('vacancies.attention') }}</p><div class="flex flex-wrap gap-1.5">@foreach ($as['flags'] as $x)<span class="rounded-full bg-danger/10 px-2.5 py-1 text-xs text-danger">{{ $lbl('flag', $x) }}</span>@endforeach</div></div>@endif
                    </div>
                </x-card>
            @endif

            @if (! empty($as['traits']))
                <x-card :title="t('vacancies.psych')" class="vac-pop">
                    <p class="mb-3 text-xs text-muted-foreground">{{ $as['test_name'] ?? t('vacancies.testDefault') }}@if (! empty($as['duration_seconds'])) · {{ t('vacancies.duration', ['min' => max(1, (int) round($as['duration_seconds'] / 60))]) }}@endif</p>
                    <div class="grid gap-3 sm:grid-cols-2">
                        @foreach ($as['traits'] as $k => $v)
                            <div><div class="flex justify-between text-sm"><span>{{ t('vacancies.traits.'.$k) !== 'vacancies.traits.'.$k ? t('vacancies.traits.'.$k) : $k }}</span><span class="tabular-nums">{{ (int) $v }}</span></div>
                                <div class="vac-bar h-2.5 overflow-hidden rounded-full bg-muted"><span class="block h-full rounded-full {{ $bar((float) $v) }}" style="width: {{ max(0, min(100, (float) $v)) }}%"></span></div></div>
                        @endforeach
                    </div>
                </x-card>
            @endif

            @if (! empty($p['experience']))
                <x-card :title="t('vacancies.experience')" class="vac-pop">
                    <ul class="space-y-1 text-sm">@foreach ($p['experience'] as $e)
                        <li><span class="font-medium">{{ $e['position'] ?? '—' }}</span>@if (! empty($e['company'])) · {{ $e['company'] }}@endif
                            @if (! empty($e['period']))<span class="text-muted-foreground"> · {{ $e['period'] }}</span>@endif
                            @if (isset($e['years']) && is_numeric($e['years']))<span class="text-muted-foreground"> · {{ t('vacancies.years', ['n' => $e['years']]) }}</span>@endif</li>
                    @endforeach</ul>
                </x-card>
            @endif

            @if ($canManage)
                <x-card :title="t('vacancies.decision')">
                    <form method="POST" action="/vacancies/{{ $a->id }}" class="space-y-3">@csrf @method('PATCH')
                        <select name="status" class="input" aria-label="{{ t('vacancies.status') }}">@foreach (\App\Services\Vacancies\VacancyService::STATUSES as $s)<option value="{{ $s }}" @selected($a->status === $s)>{{ t('vacancies.statuses.'.$s) }}</option>@endforeach</select>
                        <textarea name="comment" rows="4" class="input" placeholder="{{ t('vacancies.note') }}" aria-label="{{ t('vacancies.note') }}">{{ $a->comment }}</textarea>
                        <button class="btn btn-primary">{{ t('common.save') }}</button>
                    </form>
                </x-card>
            @endif
        </div>
    </div>
@endsection
