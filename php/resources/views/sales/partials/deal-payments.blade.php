{{-- Вкладка «Оплаты» карточки сделки ($deal). Без права payment.read — ничего не выводится. --}}
@if (can('payment.read'))
    @php
        $panel = \App\Services\Sales\Panels::payments($deal);
        $methods = collect(\App\Services\Sales\PaymentsService::METHODS)->mapWithKeys(fn ($m) => [$m => t('sales.paymentMethod.'.$m)])->all();
        $types = collect(\App\Services\Sales\PaymentsService::CREATE_TYPES)->mapWithKeys(fn ($m) => [$m => t('sales.paymentType.'.$m)])->all();
        $contractOptions = $panel['contracts']->mapWithKeys(fn ($c) => [$c->id => \App\Services\Sales\Numbers::contract($c->number).' · '.money($c->amount, $c->currency)])->all();
    @endphp
    <div class="grid gap-4">
        @if (in_array($deal->status, ['OPEN', 'WON'], true) && can('payment.create'))
            <div>
                <button type="button" class="btn btn-primary btn-sm" x-data x-on:click="$dispatch('open-modal', 'payment-new')"><x-icon name="plus" /> {{ t('sales.payments.new') }}</button>
            </div>
        @endif
        @if ($panel['items']->isEmpty())
            <x-empty icon="wallet" :title="t('sales.payments.empty')" />
        @else
            <ul class="divide-y rounded-lg border">
                @foreach ($panel['items'] as $p)
                    @php $number = \App\Services\Sales\Numbers::payment($p->number); @endphp
                    <li class="flex flex-wrap items-center gap-3 px-4 py-3 text-sm">
                        <div class="min-w-0 flex-1">
                            <p class="font-medium">
                                {{ $number }} · {{ t('sales.paymentType.'.$p->type) }} · {{ t('sales.paymentMethod.'.$p->method) }}
                                @if ($p->refundOf)<span class="text-muted-foreground">({{ t('sales.payments.refundOf', ['number' => \App\Services\Sales\Numbers::payment($p->refundOf->number)]) }})</span>@endif
                            </p>
                            <p class="text-xs text-muted-foreground">
                                @if ($p->paid_at){{ t('sales.payments.paidAt') }}: {{ dt($p->paid_at) }}@elseif ($p->due_date){{ t('sales.payments.dueDate') }}: {{ d($p->due_date) }}@else{{ dt($p->created_at) }}@endif
                                @if ($p->confirmedBy) · {{ t('sales.payments.confirmedBy') }}: {{ $p->confirmedBy->full_name }}@endif
                                @if ($p->contract) · {{ \App\Services\Sales\Numbers::contract($p->contract->number) }}@endif
                                @if ($p->project) · {{ \App\Services\Sales\Numbers::project($p->project->number) }}@endif
                                @if ($p->comment) · {{ $p->comment }}@endif
                            </p>
                        </div>
                        @include('sales.partials.badge', ['kind' => 'paymentStatus', 'status' => $p->status])
                        <span @class(['text-right font-semibold tabular-nums sm:w-40', 'text-danger' => $p->type === 'REFUND'])>{{ $p->type === 'REFUND' ? '− ' : '' }}{{ money($p->amount, $p->currency) }}</span>
                        <div class="flex flex-wrap gap-2">
                            @if ($p->status === 'PENDING' && can('payment.confirm'))
                                <form method="POST" action="{{ route('sales.payments.confirm', $p->id) }}"
                                      data-confirm="{{ t('sales.payments.confirm') }} {{ money($p->amount, $p->currency) }}?&#10;&#10;{{ t('sales.payments.confirmHint') }}">
                                    @csrf
                                    <input type="hidden" name="_idem" value="{{ \Illuminate\Support\Str::random(32) }}">
                                    <button class="btn btn-primary btn-sm">{{ t('sales.payments.confirm') }}</button>
                                </form>
                            @endif
                            @if ($p->status === 'PENDING' && can('payment.create'))
                                <button type="button" class="btn btn-outline btn-sm" x-data x-on:click="$dispatch('open-modal', 'payment-edit-{{ $p->id }}')"><x-icon name="edit" /> {{ t('sales.payments.edit') }}</button>
                                <form method="POST" action="{{ route('sales.payments.cancel', $p->id) }}" data-confirm="{{ t('sales.payments.cancelConfirm') }}">@csrf<button class="btn btn-ghost btn-sm">{{ t('sales.payments.cancel') }}</button></form>
                            @endif
                            @if ($p->status === 'PAID' && $p->type !== 'REFUND' && (float) $p->refundable_uzs > 0 && can('payment.refund'))
                                <button type="button" class="btn btn-ghost btn-sm" x-data x-on:click="$dispatch('open-modal', 'payment-refund-{{ $p->id }}')">{{ t('sales.payments.refund') }}</button>
                            @endif
                        </div>
                    </li>

                    @if ($p->status === 'PENDING' && can('payment.create'))
                        @php $formName = 'payment-edit-'.$p->id; @endphp
                        <x-modal :name="$formName" :title="t('sales.payments.edit').' '.$number" :open="old('_form') === $formName && $errors->any()">
                            <p class="mb-4 text-sm text-muted-foreground">{{ t('sales.payments.editHint') }}</p>
                            <form method="POST" action="{{ route('sales.payments.update', $p->id) }}" class="grid gap-4">
                                @csrf @method('PATCH')
                                <input type="hidden" name="_form" value="{{ $formName }}">
                                @include('sales.partials.payment-fields', ['payment' => $p, 'prefix' => 'pe-'.$p->id])
                                <div class="flex justify-end gap-2"><button type="button" class="btn btn-outline" x-on:click="show = false">{{ t('common.cancel') }}</button><button class="btn btn-primary">{{ t('common.save') }}</button></div>
                            </form>
                        </x-modal>
                    @endif
                    @if ($p->status === 'PAID' && $p->type !== 'REFUND' && (float) $p->refundable_uzs > 0 && can('payment.refund'))
                        @php $formName = 'payment-refund-'.$p->id; @endphp
                        <x-modal :name="$formName" :title="t('sales.payments.refundTitle').' · '.$number" :open="old('_form') === $formName && $errors->any()">
                            <p class="mb-4 text-sm text-muted-foreground">{{ t('sales.payments.refundHint', ['amount' => money($p->refundable_uzs)]) }}</p>
                            <form method="POST" action="{{ route('sales.payments.refund', $p->id) }}" class="grid gap-4">
                                @csrf
                                <input type="hidden" name="_form" value="{{ $formName }}">
                                <input type="hidden" name="_idem" value="{{ \Illuminate\Support\Str::random(32) }}">
                                <div class="grid gap-4 sm:grid-cols-2">
                                    <x-field name="amount" :id="'rf-amount-'.$p->id" :label="t('sales.payments.amount').', '.$p->currency" inputmode="decimal" required />
                                    <x-select name="method" :id="'rf-method-'.$p->id" :label="t('sales.payments.method')" :options="$methods" :value="$p->method" />
                                </div>
                                <x-field name="comment" type="textarea" :id="'rf-comment-'.$p->id" :label="t('sales.payments.refundReason')" required />
                                <div class="flex justify-end gap-2"><button type="button" class="btn btn-outline" x-on:click="show = false">{{ t('common.cancel') }}</button><button class="btn btn-danger">{{ t('sales.payments.refund') }}</button></div>
                            </form>
                        </x-modal>
                    @endif
                @endforeach
            </ul>
        @endif
    </div>

    @if (in_array($deal->status, ['OPEN', 'WON'], true) && can('payment.create'))
        <x-modal name="payment-new" :title="t('sales.payments.new')" :open="old('_form') === 'payment-new' && $errors->any()">
            <form method="POST" action="{{ route('sales.payments.store') }}" class="grid gap-4">
                @csrf
                <input type="hidden" name="_form" value="payment-new">
                <input type="hidden" name="_idem" value="{{ \Illuminate\Support\Str::random(32) }}">
                <input type="hidden" name="deal_id" value="{{ $deal->id }}">
                @include('sales.partials.payment-fields', ['payment' => null, 'prefix' => 'pn'])
                <div class="flex justify-end gap-2"><button type="button" class="btn btn-outline" x-on:click="show = false">{{ t('common.cancel') }}</button><button class="btn btn-primary">{{ t('sales.payments.new') }}</button></div>
            </form>
        </x-modal>
    @endif
@endif
