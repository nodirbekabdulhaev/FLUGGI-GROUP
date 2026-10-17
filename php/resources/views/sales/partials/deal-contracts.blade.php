{{-- Вкладка «Договоры» карточки сделки ($deal). Без права contract.read — ничего не выводится. --}}
@if (can('contract.read'))
    @php
        $panel = \App\Services\Sales\Panels::contracts($deal);
        $editable = in_array($deal->status, ['OPEN', 'WON'], true);
        $reopenNew = old('_form') === 'contract-new' && $errors->any();
        $today = \App\Support\Format::today()->toDateString();
    @endphp
    <div class="grid gap-4">
        @if ($deal->status === 'OPEN' && can('contract.create'))
            <div>
                <button type="button" class="btn btn-primary btn-sm" x-data x-on:click="$dispatch('open-modal', 'contract-new')"><x-icon name="plus" /> {{ t('sales.contracts.new') }}</button>
            </div>
        @elseif ($deal->status === 'OPEN')
            <p class="text-sm text-muted-foreground">{{ t('sales.contracts.byRop') }}</p>
        @endif
        @if ($panel['items']->isEmpty())
            <x-empty icon="file" :title="t('sales.contracts.empty')" />
        @else
            <ul class="divide-y rounded-lg border">
                @foreach ($panel['items'] as $c)
                    @php $number = \App\Services\Sales\Numbers::contract($c->number); @endphp
                    <li class="grid gap-2 px-4 py-3">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-medium">{{ t('sales.contracts.numberFrom', ['number' => $number, 'date' => d($c->contract_date)]) }}</span>
                            @include('sales.partials.badge', ['kind' => 'contractStatus', 'status' => $c->status])
                            @if ($c->proposal)
                                <span class="text-xs text-muted-foreground">{{ t('sales.contracts.byProposal', ['number' => \App\Services\Sales\Numbers::proposal($c->proposal->number)]) }}</span>
                            @endif
                            <span class="ml-auto text-right">
                                <span class="block font-semibold tabular-nums">{{ money($c->amount, $c->currency) }}</span>
                                <span class="block text-xs text-muted-foreground">{{ t('sales.contracts.paid') }}: {{ money($c->paid_uzs) }}</span>
                            </span>
                        </div>
                        @if ($c->comment)<p class="text-sm text-muted-foreground">{{ $c->comment }}</p>@endif
                        <div>
                            <button type="button" class="btn btn-outline btn-sm" x-data x-on:click="$dispatch('open-doc', { kind: 'contracts', id: @js($c->id), title: @js(t('sales.contracts.documentTitle').' '.$number) })"><x-icon name="file" /> {{ t('sales.contracts.document') }}</button>
                        </div>
                        @if ($c->files->isNotEmpty())
                            <ul class="flex flex-wrap gap-2 text-xs">
                                @foreach ($c->files as $file)
                                    <li><a class="inline-flex max-w-full items-center gap-1 rounded border px-2 py-1 hover:bg-muted" href="{{ route('sales.files.download', $file->id) }}"><x-icon name="paperclip" class="size-3" /><span class="truncate">{{ $file->original_name }}</span></a></li>
                                @endforeach
                            </ul>
                        @endif
                        @if ($editable && can('contract.update'))
                            <div class="flex flex-wrap gap-2">
                                @if ($c->status === 'DRAFT')
                                    <form method="POST" action="{{ route('sales.contracts.action', [$c->id, 'send']) }}">@csrf<button class="btn btn-outline btn-sm">{{ t('sales.contracts.send') }}</button></form>
                                @endif
                                @if (in_array($c->status, ['DRAFT', 'SENT'], true))
                                    <form method="POST" action="{{ route('sales.contracts.action', [$c->id, 'submit-approval']) }}">@csrf<button class="btn btn-outline btn-sm">{{ t('sales.contracts.submitApproval') }}</button></form>
                                @endif
                                @if (in_array($c->status, ['DRAFT', 'SENT', 'IN_APPROVAL'], true))
                                    <form method="POST" action="{{ route('sales.contracts.action', [$c->id, 'sign']) }}">@csrf<button class="btn btn-primary btn-sm">{{ t('sales.contracts.sign') }}</button></form>
                                    <button type="button" class="btn btn-ghost btn-sm" x-data x-on:click="$dispatch('open-modal', 'contract-edit-{{ $c->id }}')"><x-icon name="edit" /> {{ t('common.edit') }}</button>
                                @endif
                                <form method="POST" action="{{ route('sales.files.store') }}" enctype="multipart/form-data" x-data>
                                    @csrf
                                    <input type="hidden" name="_idem" value="{{ \Illuminate\Support\Str::random(32) }}">
                                    <input type="hidden" name="contract_id" value="{{ $c->id }}">
                                    <input type="hidden" name="category" value="CONTRACT">
                                    <label class="btn btn-ghost btn-sm cursor-pointer">
                                        <x-icon name="upload" /> {{ t('sales.contracts.upload') }}
                                        <input type="file" name="file" class="sr-only" accept=".pdf,.png,.jpg,.jpeg,.webp,.docx,.xlsx,.zip" x-on:change="$el.form.submit()">
                                    </label>
                                </form>
                                @if ($c->status !== 'CANCELLED')
                                    <form method="POST" action="{{ route('sales.contracts.action', [$c->id, 'cancel']) }}" data-confirm="{{ t('sales.contracts.cancelConfirm') }}">@csrf<button class="btn btn-ghost btn-sm">{{ t('sales.contracts.cancel') }}</button></form>
                                @endif
                            </div>
                            @if (in_array($c->status, ['DRAFT', 'SENT', 'IN_APPROVAL'], true))
                                @php $formName = 'contract-edit-'.$c->id; @endphp
                                <x-modal :name="$formName" :title="t('sales.contracts.editTitle', ['number' => $number])" :open="old('_form') === $formName && $errors->any()">
                                    <form method="POST" action="{{ route('sales.contracts.update', $c->id) }}" class="grid gap-4">
                                        @csrf @method('PATCH')
                                        <input type="hidden" name="_form" value="{{ $formName }}">
                                        <div class="grid gap-4 sm:grid-cols-[1fr_6rem]">
                                            <x-field name="amount" :label="t('sales.contracts.amount')" :value="\App\Services\Sales\Numbers::rate($c->amount)" inputmode="decimal" data-money required />
                                            <x-select name="currency" :label="t('sales.currency')" :options="['UZS' => 'UZS', 'USD' => 'USD']" :value="$c->currency" />
                                        </div>
                                        <x-field name="contract_date" type="date" :label="t('sales.contracts.date')" :value="$c->contract_date->format('Y-m-d')" required />
                                        <x-field name="comment" type="textarea" :label="t('sales.comment')" :value="$c->comment" />
                                        <div class="flex justify-end gap-2"><button type="button" class="btn btn-outline" x-on:click="show = false">{{ t('common.cancel') }}</button><button class="btn btn-primary">{{ t('common.save') }}</button></div>
                                    </form>
                                </x-modal>
                            @endif
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    @if ($deal->status === 'OPEN' && can('contract.create'))
        @php
            $proposals = $panel['accepted']->map(fn ($p) => ['id' => $p->id, 'total' => \App\Services\Sales\Numbers::rate($p->total), 'currency' => $p->currency])->values();
        @endphp
        <x-modal name="contract-new" :title="t('sales.contracts.new')" :open="$reopenNew">
            <form method="POST" action="{{ route('sales.contracts.store') }}" class="grid gap-4"
                  x-data="{ proposals: @js($proposals), proposal: @js(old('proposal_id', '')), amount: @js(old('amount', \App\Services\Sales\Numbers::rate($deal->amount))), currency: @js(old('currency', $deal->currency)),
                            pick() { const p = this.proposals.find((x) => x.id === this.proposal); if (p) { this.amount = p.total; this.currency = p.currency; } } }">
                @csrf
                <input type="hidden" name="_form" value="contract-new">
                <input type="hidden" name="_idem" value="{{ \Illuminate\Support\Str::random(32) }}">
                <input type="hidden" name="deal_id" value="{{ $deal->id }}">
                <div>
                    <label class="label" for="ct-kp">{{ t('sales.contracts.fromProposal') }}</label>
                    <select id="ct-kp" name="proposal_id" class="input" x-model="proposal" x-on:change="pick()">
                        <option value="">—</option>
                        @foreach ($panel['accepted'] as $p)
                            <option value="{{ $p->id }}">{{ \App\Services\Sales\Numbers::proposal($p->number) }} · {{ $p->title }} · {{ money($p->total, $p->currency) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="grid gap-4 sm:grid-cols-[1fr_6rem]">
                    <div>
                        <label class="label" for="ct-amount">{{ t('sales.contracts.amount') }}<span class="text-danger"> *</span></label>
                        <input id="ct-amount" name="amount" class="input" inputmode="decimal" data-money x-model="amount" :readonly="proposal !== ''" required>
                        @error('amount')<p class="error">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="label" for="ct-cur">{{ t('sales.currency') }}</label>
                        <select id="ct-cur" name="currency" class="input" x-model="currency"><option value="UZS">UZS</option><option value="USD">USD</option></select>
                    </div>
                </div>
                <x-field name="contract_date" type="date" :label="t('sales.contracts.date')" :value="$today" required />
                <x-field name="comment" type="textarea" :label="t('sales.comment')" />
                <div class="flex justify-end gap-2"><button type="button" class="btn btn-outline" x-on:click="show = false">{{ t('common.cancel') }}</button><button class="btn btn-primary">{{ t('sales.contracts.new') }}</button></div>
            </form>
        </x-modal>
    @endif
    @include('sales.partials.document-dialog')
@endif
