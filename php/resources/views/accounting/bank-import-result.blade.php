@extends('layouts.app')
@section('title', t('accounting.bank.resultTitle'))
@section('content')
    @php $t = $result['totals']; $check = $result['check']; @endphp
    <x-page-header :title="t('accounting.bank.resultTitle')" :subtitle="$result['file'].' · '.$result['account']">
        <a href="/accounting/bank-import" class="btn btn-outline"><x-icon name="upload" /> {{ t('accounting.bank.another') }}</a>
        <a href="/accounting/operations?account={{ $result['account_id'] }}" class="btn btn-primary">{{ t('accounting.bank.toOperations') }}</a>
    </x-page-header>
    @include('accounting.partials.tabs', ['active' => '/accounting/operations'])

    <div class="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
        <x-stat :label="t('accounting.bank.created')" :value="$t['created']" />
        <x-stat :label="t('accounting.bank.status.duplicate')" :value="$t['duplicate']" />
        <x-stat :label="t('accounting.bank.unchecked')" :value="$t['unchecked']" />
        <x-stat :label="t('accounting.bank.status.error')" :value="$t['error']" />
        <x-stat :label="t('accounting.bank.notCompleted')" :value="$t['notCompleted']" />
        <x-stat :label="t('accounting.bank.unreadable')" :value="$t['unreadable']" />
    </div>

    @if ($check)
        <x-card :title="t('accounting.bank.checkTitle', ['date' => d($check['date'])])" class="mb-4">
            <dl class="space-y-1 text-sm">
                <div class="flex justify-between gap-3"><dt class="text-muted-foreground">{{ t('accounting.bank.closing') }}</dt><dd class="tabular-nums">{{ money($check['statement'], $result['currency']) }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-muted-foreground">{{ t('accounting.bank.accountBalance') }}</dt><dd class="tabular-nums">{{ money($check['account'], $result['currency']) }}</dd></div>
            </dl>
            @if (\Brick\Math\BigDecimal::of($check['difference'])->isZero())
                <p class="mt-3 text-sm text-success">{{ t('accounting.bank.checkOk') }}</p>
            @else
                <p class="mt-3 flex items-start gap-2 text-sm text-warning"><x-icon name="alert" />{{ t('accounting.bank.checkDiff', ['diff' => money($check['difference'], $result['currency'])]) }}</p>
            @endif
        </x-card>
    @endif

    @if ($result['errors'])
        <x-card :title="t('accounting.bank.errorsTitle')">
            <ul class="space-y-1 text-sm">@foreach ($result['errors'] as $e)<li>{{ t('accounting.bank.row', ['n' => $e['n']]) }}: {{ $e['message'] }}</li>@endforeach</ul>
        </x-card>
    @endif
@endsection
