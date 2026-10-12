{{-- Вкладка «Реквизиты» карточки клиента ($client): подставляются в договор. Изменить — право client.update. --}}
@if (can('client.read'))
    @php
        $r = is_array($client->requisites) ? \App\Services\Sales\DocumentsService::requisitesOf($client) : null;
        $fields = ['legalName' => true, 'inn' => false, 'oked' => false, 'director' => false, 'directorPosition' => false, 'signerGenitive' => true,
            'basis' => false, 'vatCode' => false, 'address' => true, 'phone' => false, 'bank' => false, 'mfo' => false, 'account' => false];
        $mine = old('_form') === 'client-requisites';
        $initial = array_merge(['legalName' => $client->name, 'phone' => $client->phone ?? '', 'directorPosition' => 'Директор', 'basis' => 'Устава'], array_filter($r ?? [], fn ($v) => $v !== ''));
    @endphp
    <div class="grid gap-4">
        @if ($r)
            <dl class="grid grid-cols-1 gap-x-4 gap-y-1.5 text-sm sm:grid-cols-[auto,1fr]">
                @foreach ($fields as $k => $_)
                    <dt class="muted">{{ t('requisites.'.$k) }}</dt><dd class="break-words">{{ $r[$k] !== '' ? $r[$k] : '—' }}</dd>
                @endforeach
            </dl>
        @else
            <x-empty icon="file" :title="t('requisites.empty')">{{ t('requisites.emptyText') }}</x-empty>
        @endif
        @if (can('client.update'))
            <div><button type="button" class="btn btn-outline btn-sm" x-data x-on:click="$dispatch('open-modal', 'client-requisites')"><x-icon name="edit" /> {{ t($r ? 'requisites.edit' : 'requisites.fill') }}</button></div>
            <x-modal name="client-requisites" :title="t('requisites.title')" size="max-w-2xl" :open="$mine && $errors->any()">
                <form method="POST" action="{{ route('sales.requisites.update', $client->id) }}" class="grid gap-4 sm:grid-cols-2">
                    @csrf @method('PUT')
                    <input type="hidden" name="_form" value="client-requisites">
                    @foreach ($fields as $k => $wide)
                        <div @class(['sm:col-span-2' => $wide])>
                            <label class="label" for="rq-{{ $k }}">{{ t('requisites.'.$k) }}</label>
                            <input id="rq-{{ $k }}" name="{{ $k }}" class="input" value="{{ $mine ? old($k) : ($initial[$k] ?? '') }}"
                                   @if (in_array($k, ['inn', 'mfo', 'account'], true)) inputmode="numeric" maxlength="{{ ['inn' => 14, 'mfo' => 5, 'account' => 20][$k] }}" @endif>
                            @if ($mine && $errors->has($k))<p class="error">{{ $errors->first($k) }}</p>@elseif (in_array($k, ['signerGenitive', 'basis'], true))<p class="hint">{{ t('requisites.'.$k.'Hint') }}</p>@endif
                        </div>
                    @endforeach
                    <div class="flex justify-end gap-2 sm:col-span-2"><button type="button" class="btn btn-outline" x-on:click="show = false">{{ t('requisites.cancel') }}</button><button class="btn btn-primary">{{ t('requisites.save') }}</button></div>
                </form>
            </x-modal>
        @endif
    </div>
@endif
