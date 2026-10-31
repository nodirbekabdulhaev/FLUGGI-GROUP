{{-- Личная ставка за опубликованный ролик SMM ($user): пусто — ставка роли из SMM → «Финансы». Менять — payroll.manage ALL. --}}
@php
    $editable = can('payroll.manage', 'ALL');
    $user->loadMissing('employee');
    $own = $user->employee?->smm_video_rate;
    $defaults = \App\Services\Smm\SmmVideoPay::settings()['rates'];
    $num = fn ($v) => rtrim(rtrim((string) $v, '0'), '.');
@endphp
<div class="mt-6 grid gap-3 border-t pt-4">
    <div>
        <h3 class="text-sm font-semibold">{{ t('smm.finance.personalRate') }}</h3>
        <p class="text-xs text-muted-foreground">{{ t('smm.finance.personalRateHint', ['videographer' => money($defaults['VIDEOGRAPHER']), 'mobilographer' => money($defaults['MOBILOGRAPHER']), 'editor' => money($defaults['EDITOR'])]) }}</p>
    </div>
    @if ($editable)
        <form method="POST" action="{{ route('smm.finance.personal-rate', $user->id) }}" class="flex flex-col gap-2 sm:flex-row sm:items-end">
            @csrf @method('PUT')
            <div class="min-w-0 flex-1">
                <label class="label" for="smm-personal-rate">{{ t('smm.finance.personalRateField') }}</label>
                <input id="smm-personal-rate" name="smm_video_rate" inputmode="decimal" data-money class="input text-right tabular-nums"
                       value="{{ old('smm_video_rate', $own !== null ? $num($own) : '') }}" placeholder="{{ t('smm.finance.asRole') }}">
                @error('smm_video_rate')<p class="error">{{ $message }}</p>@enderror
            </div>
            <button class="btn btn-primary">{{ t('common.save') }}</button>
        </form>
    @else
        <p class="text-sm">{{ $own !== null ? money($own) : t('smm.finance.asRole') }}</p>
    @endif
</div>
