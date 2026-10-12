{{-- Вкладка «КП» карточки сделки ($deal). Без права proposal.read — ничего не выводится. --}}
@if (can('proposal.read'))
    @php
        $panel = \App\Services\Sales\Panels::proposals($deal);
        $open = $deal->status === 'OPEN';
    @endphp
    <div class="grid gap-4">
        @if ($open && can('proposal.create'))
            <div>
                <button type="button" class="btn btn-primary btn-sm" x-data x-on:click="$dispatch('open-proposal', null)"><x-icon name="plus" /> {{ t('sales.proposals.new') }}</button>
            </div>
        @endif
        @if ($panel['items']->isEmpty())
            <x-empty icon="file" :title="t('sales.proposals.empty')" />
        @else
            <ul class="divide-y rounded-lg border">
                @foreach ($panel['items'] as $p)
                    @php
                        $number = \App\Services\Sales\Numbers::proposal($p->number);
                        $actions = [];
                        if ($p->status === 'DRAFT') {
                            if (! $p->approved_by_id) {
                                $actions[] = ['submit-approval', t('sales.proposals.submitApproval'), 'proposal.update', 'btn-outline'];
                            }
                            $actions[] = ['send', t('sales.proposals.send'), 'proposal.send', 'btn-primary'];
                        }
                        if ($p->status === 'IN_APPROVAL' || ($p->status === 'DRAFT' && ! $p->approved_by_id && can('proposal.approve'))) {
                            $actions[] = ['approve', t('sales.proposals.approve'), 'proposal.approve', 'btn-outline'];
                        }
                        if ($p->status === 'SENT') {
                            $actions[] = ['mark-viewed', t('sales.proposals.viewed'), 'proposal.update', 'btn-outline'];
                        }
                        if (in_array($p->status, ['SENT', 'VIEWED'], true)) {
                            $actions[] = ['accept', t('sales.proposals.accept'), 'proposal.update', 'btn-primary'];
                            $actions[] = ['reject', t('sales.proposals.reject'), 'proposal.update', 'btn-outline'];
                        }
                        if ($p->status === 'REJECTED') {
                            $actions[] = ['send', t('sales.proposals.send'), 'proposal.send', 'btn-outline'];
                        }
                        $json = [
                            'id' => $p->id, 'number' => $number, 'status' => $p->status, 'title' => $p->title, 'currency' => $p->currency,
                            'description' => $p->description ?? '', 'implementation_term' => $p->implementation_term ?? '',
                            'payment_terms' => $p->payment_terms ?? '', 'valid_until' => $p->valid_until?->format('Y-m-d') ?? '',
                            'items' => $p->items->sortBy('sort')->values()->map(fn ($i) => [
                                'service_id' => $i->service_id ?? '', 'tariff_id' => $i->tariff_id ?? '', 'description' => $i->description,
                                'quantity' => \App\Services\Sales\Numbers::rate($i->quantity), 'unit_price' => \App\Services\Sales\Numbers::rate($i->unit_price),
                                'discount_pct' => \App\Services\Sales\Numbers::rate($i->discount_pct),
                            ])->all(),
                        ];
                    @endphp
                    <li class="grid gap-2 px-4 py-3" x-data="{ versions: false }">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-medium">{{ $number }} · {{ $p->title }}</span>
                            @include('sales.partials.badge', ['kind' => 'proposalStatus', 'status' => $p->status])
                            <span class="text-xs text-muted-foreground">v{{ $p->current_version }}</span>
                            @if ($p->approvedBy)
                                <span class="text-xs text-success">{{ t('sales.proposals.approvedBy', ['name' => $p->approvedBy->full_name]) }}</span>
                            @endif
                            <span class="ml-auto text-base font-semibold tabular-nums">{{ money($p->total, $p->currency) }}</span>
                        </div>
                        <p class="text-sm text-muted-foreground">{{ $p->items->sortBy('sort')->pluck('description')->implode(' · ') }}</p>
                        <div class="flex flex-wrap gap-2">
                            @if ($open)
                                @foreach ($actions as [$a, $label, $perm, $cls])
                                    @if (can($perm))
                                        <form method="POST" action="{{ route('sales.proposals.action', [$p->id, $a]) }}">@csrf
                                            <button class="btn {{ $cls }} btn-sm">{{ $label }}</button>
                                        </form>
                                    @endif
                                @endforeach
                                @if (can('proposal.update') && $p->status !== 'ACCEPTED')
                                    <button type="button" class="btn btn-ghost btn-sm" x-on:click="$dispatch('open-proposal', @js($json))"><x-icon name="edit" /> {{ t('common.edit') }}</button>
                                @endif
                            @endif
                            <button type="button" class="btn btn-ghost btn-sm" x-on:click="$dispatch('open-doc', { kind: 'proposals', id: @js($p->id), title: @js($number.' · '.$p->title) })"><x-icon name="file" /> {{ t('sales.proposals.document') }}</button>
                            <button type="button" class="btn btn-ghost btn-sm" x-on:click="versions = !versions" :aria-expanded="versions"><x-icon name="clock" /> {{ t('sales.proposals.versions') }}</button>
                        </div>
                        <ul x-show="versions" x-cloak class="mt-1 grid gap-1 border-l pl-3 text-xs text-muted-foreground">
                            @foreach ($panel['versions'][$p->id] ?? [] as $v)
                                <li>v{{ $v->version }} — {{ money($v->total, $v->currency) }} · {{ $v->author?->full_name }} · {{ dt($v->created_at) }}@if ($v->comment) · {{ $v->comment }}@endif</li>
                            @endforeach
                        </ul>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
    @if ($open && (can('proposal.create') || can('proposal.update')))
        @include('sales.partials.proposal-editor', ['deal' => $deal, 'editor' => $panel['editor']])
    @endif
    @include('sales.partials.document-dialog')
@endif
