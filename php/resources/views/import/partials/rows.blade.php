{{-- Таблица строк файла: $rows (n, status, label, reason, code?), $hidden — сколько строк не показано --}}
@php($colors = ['create' => 'green', 'created' => 'green', 'update' => 'blue', 'updated' => 'blue', 'duplicate' => 'yellow', 'error' => 'red'])
@if ($rows->isEmpty())
    <x-empty icon="file" :title="t('import.noRows')" />
@else
    <x-table>
        <thead><tr>
            <th class="w-16">{{ t('import.table.row') }}</th>
            <th>{{ t('import.table.status') }}</th>
            <th>{{ t('import.table.record') }}</th>
            <th>{{ t('import.table.reason') }}</th>
        </tr></thead>
        <tbody>
        @foreach ($rows as $r)
            <tr>
                <td class="tabular-nums text-muted-foreground">{{ $r['n'] }}</td>
                <td class="whitespace-nowrap"><x-badge :color="$colors[$r['status']] ?? 'gray'">{{ t('import.status.'.$r['status']) }}</x-badge></td>
                <td class="max-w-xs break-words">
                    @if (! empty($r['code']))<span class="font-medium">{{ $r['code'] }}</span>@if ($r['label'] !== '') · @endif @endif{{ $r['label'] }}
                </td>
                <td @class(['max-w-md break-words text-sm', 'text-danger' => $r['status'] === 'error', 'text-muted-foreground' => $r['status'] !== 'error'])>{{ $r['reason'] }}</td>
            </tr>
        @endforeach
        </tbody>
        @if ($hidden > 0)
            <x-slot:footer><p class="text-xs text-muted-foreground">{{ t('import.moreRows', ['count' => $hidden]) }}</p></x-slot:footer>
        @endif
    </x-table>
@endif
