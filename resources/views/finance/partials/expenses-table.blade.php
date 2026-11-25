{{-- Таблица расходов — на странице «Расходы» и во вкладке «Финансы» проекта --}}
@php($showProject = $showProject ?? true)
<div class="overflow-x-auto">
    <table class="table">
        <thead><tr>
            <th>{{ t('finance.expenses.date') }}</th>
            @if ($showProject)<th>{{ t('finance.expenses.project') }}</th>@endif
            <th>{{ t('finance.expenses.category') }}</th><th>{{ t('finance.expenses.payee') }}</th>
            <th class="text-right">{{ t('finance.expenses.amount') }}</th><th></th>
        </tr></thead>
        <tbody>
        @foreach ($items as $e)
            <tr>
                <td class="whitespace-nowrap">{{ d($e->expense_date) }}<span class="block text-xs text-muted-foreground">{{ \App\Support\Format::code('EXP', $e->number) }}</span></td>
                @if ($showProject)
                    <td>@if ($e->project)<a href="/projects/{{ $e->project->id }}" class="hover:underline">{{ \App\Support\Format::code('P', $e->project->number) }} · {{ $e->project->name }}</a>@else<span class="text-muted-foreground">{{ t('finance.scope.COMPANY') }}</span>@endif</td>
                @endif
                <td>{{ $e->categoryRef?->name ?? $e->category }}@if ($e->description)<span class="block max-w-72 truncate text-xs text-muted-foreground">{{ $e->description }}</span>@endif</td>
                <td class="text-muted-foreground">{{ $e->payee?->full_name ?? '—' }}</td>
                <td class="whitespace-nowrap text-right font-medium tabular-nums">{{ money($e->amount, $e->currency) }}@if ($e->currency !== 'UZS')<span class="block text-xs text-muted-foreground">{{ money($e->amount_uzs) }}</span>@endif</td>
                <td class="whitespace-nowrap text-right">
                    @if ($e->can_edit)
                        <a href="{{ $editBase }}{{ str_contains($editBase, '?') ? '&' : '?' }}edit={{ $e->id }}" class="btn btn-ghost btn-icon" aria-label="{{ t('finance.expenses.edit') }}"><x-icon name="edit" /></a>
                        <form method="POST" action="/finance/expenses/{{ $e->id }}" class="inline" data-confirm="{{ t('finance.expenses.deleteConfirm', ['number' => \App\Support\Format::code('EXP', $e->number)]) }}">
                            @csrf @method('DELETE')
                            <button class="btn btn-ghost btn-icon" aria-label="{{ t('common.delete') }}"><x-icon name="trash" /></button>
                        </form>
                    @endif
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
