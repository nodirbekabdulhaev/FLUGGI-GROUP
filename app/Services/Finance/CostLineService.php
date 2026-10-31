<?php

namespace App\Services\Finance;

use App\Exceptions\BusinessRule;
use App\Models\ProjectCostLine;
use App\Models\ProjectMember;
use App\Services\Projects\ProjectAccess;
use App\Support\Format;
use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Плановая себестоимость проекта по тарифу: из позиций тарифа (8 рилсов, 8 обложек…)
 * получаются строки «что и кому заплатить». Ставка — личная ставка назначенного исполнителя
 * (карточка сотрудника), иначе базовая. «Начислить» превращает строку в расход проекта.
 */
final class CostLineService
{
    /** Строки из тарифов принятого КП сделки проекта. Повторный вызов ничего не делает. Вызывать в транзакции. */
    public static function generate(string $projectId): int
    {
        if (ProjectCostLine::where('project_id', $projectId)->exists()) {
            return 0;
        }
        $project = DB::table('projects')->where('id', $projectId)->first();
        if (! $project) {
            return 0;
        }
        $proposal = DB::table('proposals')->where('deal_id', $project->deal_id)->where('status', 'ACCEPTED')->orderByDesc('accepted_at')->first();
        if (! $proposal) {
            return 0;
        }
        $items = DB::table('proposal_items')->where('proposal_id', $proposal->id)->whereNotNull('tariff_id')->orderBy('sort')->get();
        $base = now();
        $n = 0;
        foreach ($items as $pi) {
            $tis = DB::table('tariff_items as ti')->leftJoin('work_items as w', 'w.id', '=', 'ti.work_item_id')
                ->where('ti.tariff_id', $pi->tariff_id)->orderBy('ti.sort')
                ->get(['ti.*', 'w.id as w_id', 'w.name as w_name', 'w.specialty as w_specialty', 'w.default_rate as w_rate', 'w.currency as w_currency']);
            foreach ($tis as $ti) {
                $row = null;
                if ($ti->kind === 'PIECE' && $ti->w_id) {
                    $row = ['kind' => 'PIECE', 'work_item_id' => $ti->work_item_id, 'specialty' => $ti->w_specialty, 'label' => $ti->label ?? $ti->w_name,
                        'quantity' => (string) BigDecimal::of($ti->quantity)->multipliedBy(BigDecimal::of($pi->quantity))->toScale(2), 'rate' => $ti->w_rate, 'currency' => $ti->w_currency];
                } elseif ($ti->kind === 'FIXED' && $ti->amount !== null && BigDecimal::of($ti->amount)->isPositive()) {
                    $row = ['kind' => 'FIXED', 'work_item_id' => null, 'specialty' => $ti->specialty, 'label' => $ti->label ?? t('costLines.defaultLabel'),
                        'quantity' => $pi->quantity, 'rate' => $ti->amount, 'currency' => $ti->currency];
                }
                if ($row) {
                    // Порядок строк = порядок в тарифе
                    ProjectCostLine::create($row + ['id' => (string) Str::uuid(), 'project_id' => $projectId, 'tariff_id' => $pi->tariff_id, 'tariff_item_id' => $ti->id,
                        'created_at' => $base->copy()->addMilliseconds($n), 'updated_at' => $base]);
                    $n++;
                }
            }
        }
        // Исполнители, уже назначенные в команду
        foreach (ProjectMember::where('project_id', $projectId)->where('status', 'ACTIVE')->pluck('user_id') as $uid) {
            self::assign($projectId, $uid);
        }

        return $n;
    }

    /**
     * Исполнитель в команде: ему — свободные плановые строки каждой его позиции (фотограф,
     * видеограф, мобилограф — каждая строка оплачивается отдельно), с его личными ставками.
     */
    public static function assign(string $projectId, string $userId): void
    {
        $member = ProjectMember::where('project_id', $projectId)->where('user_id', $userId)->first();
        if (! $member || $member->status !== 'ACTIVE' || ! ($positions = $member->positions())) {
            return;
        }
        foreach (ProjectCostLine::where('project_id', $projectId)->where('status', 'PLANNED')->whereIn('specialty', $positions)->whereNull('assignee_id')->get() as $l) {
            $personal = $l->kind === 'PIECE' && $l->work_item_id
                ? DB::table('employee_rates')->where('user_id', $userId)->where('work_item_id', $l->work_item_id)->first() : null;
            $l->update(['assignee_id' => $userId] + ($personal ? ['rate' => $personal->rate, 'currency' => $personal->currency] : []));
        }
    }

    /**
     * С участника сняли позиции: его ещё не начисленные строки этих специальностей освобождаются
     * (личная ставка штучной работы — снова базовая) и переходят к другому участнику с такой позицией.
     * Начисленные и отменённые строки не меняются. Вызывать в транзакции.
     *
     * @param  list<string>  $specialties
     * @return int сколько строк освобождено
     */
    public static function unassign(string $projectId, string $userId, array $specialties): int
    {
        if (! $specialties) {
            return 0;
        }
        $lines = ProjectCostLine::with('workItem:id,default_rate,currency')->where('project_id', $projectId)->where('status', 'PLANNED')
            ->where('assignee_id', $userId)->whereIn('specialty', $specialties)->get();
        $personal = DB::table('employee_rates')->where('user_id', $userId)->whereIn('work_item_id', $lines->pluck('work_item_id')->filter()->all() ?: ['-'])->get()->keyBy('work_item_id');
        foreach ($lines as $l) {
            // Личная ставка ушедшего исполнителя заменяется базовой; ставку, изменённую вручную, не трогаем
            $own = $l->kind === 'PIECE' && $l->workItem ? ($personal[$l->work_item_id] ?? null) : null;
            $base = $own && BigDecimal::of($own->rate)->isEqualTo(BigDecimal::of($l->rate)) && $own->currency === $l->currency
                ? ['rate' => $l->workItem->default_rate, 'currency' => $l->workItem->currency] : [];
            $l->update(['assignee_id' => null] + $base);
        }
        if ($lines->isNotEmpty()) {
            foreach (ProjectMember::where('project_id', $projectId)->where('status', 'ACTIVE')->where('user_id', '<>', $userId)->orderBy('assigned_at')->pluck('user_id') as $uid) {
                self::assign($projectId, $uid);
            }
        }

        return $lines->count();
    }

    /** Статус для экрана: удалённый расход — строку можно начислить снова. */
    public static function effectiveStatus(ProjectCostLine $l): string
    {
        $accrued = $l->status === 'ACCRUED' && $l->expense && ! $l->expense->deleted_at;

        return $l->status === 'ACCRUED' && ! $accrued ? 'PLANNED' : $l->status;
    }

    public static function list(string $projectId): Collection
    {
        ProjectAccess::project($projectId, 'finance.read');
        // План строится по событию project.created; если событие ещё не обработано cron-ом — строим сейчас
        DB::transaction(fn () => self::generate($projectId));
        $rows = ProjectCostLine::with(['assignee:id,full_name', 'expense' => fn ($q) => $q->withTrashed(), 'workItem:id,default_rate,currency'])
            ->where('project_id', $projectId)->where('status', '<>', 'CANCELLED')->orderBy('created_at')->get();
        foreach ($rows as $l) {
            $l->setAttribute('view_status', self::effectiveStatus($l));
            $l->setAttribute('amount', (string) BigDecimal::of($l->quantity)->multipliedBy(BigDecimal::of($l->rate))->toScale(2));
            $l->setAttribute('personal_rate', $l->kind === 'PIECE' && $l->workItem && ! BigDecimal::of($l->rate)->isEqualTo(BigDecimal::of($l->workItem->default_rate)));
            $l->setAttribute('expense_code', self::effectiveStatus($l) === 'ACCRUED' ? Format::code('EXP', $l->expense->number) : null);
        }

        return $rows;
    }

    private static function line(string $id): ProjectCostLine
    {
        $l = ProjectCostLine::with(['expense' => fn ($q) => $q->withTrashed()])->findOrFail($id);
        ProjectAccess::project($l->project_id, 'expense.update');

        return $l;
    }

    /** @param  array{quantity?:string,rate?:string,assignee_id?:?string}  $input */
    public static function update(string $id, array $input): void
    {
        $l = self::line($id);
        if (self::effectiveStatus($l) !== 'PLANNED') {
            throw new BusinessRule(t('costLines.errors.accrued'));
        }
        if (! empty($input['assignee_id'])
            && ! ProjectMember::where('project_id', $l->project_id)->where('user_id', $input['assignee_id'])->where('status', 'ACTIVE')->exists()) {
            throw new BusinessRule(t('costLines.errors.notMember'), ['assignee_id' => t('costLines.errors.notMember')]);
        }
        DB::transaction(fn () => $l->update(array_intersect_key($input, array_flip(['quantity', 'rate', 'assignee_id'])) + ['status' => 'PLANNED', 'expense_id' => null]));
    }

    /** Начислить исполнителю: строка становится расходом проекта (категория «Исполнитель»). */
    public static function accrue(string $id): void
    {
        $l = self::line($id);
        if (self::effectiveStatus($l) !== 'PLANNED') {
            throw new BusinessRule(t('costLines.errors.accrued'));
        }
        if (! $l->assignee_id) {
            throw new BusinessRule(t('costLines.errors.noAssignee'));
        }
        DB::transaction(function () use ($l) {
            $amount = (string) BigDecimal::of($l->quantity)->multipliedBy(BigDecimal::of($l->rate))->toScale(2);
            $expense = ExpenseService::create([
                'scope' => 'PROJECT',
                'project_id' => $l->project_id,
                'category' => 'EXECUTOR',
                'amount' => $amount,
                'currency' => $l->currency,
                'expense_date' => Format::today()->toDateString(),
                'payee_user_id' => $l->assignee_id,
                'description' => $l->label.': '.BigDecimal::of($l->quantity)->strippedOfTrailingZeros().' × '.$l->rate.' '.$l->currency,
            ]);
            $l->update(['status' => 'ACCRUED', 'expense_id' => $expense->id]);
        });
    }

    public static function cancel(string $id): void
    {
        $l = self::line($id);
        if (self::effectiveStatus($l) !== 'PLANNED') {
            throw new BusinessRule(t('costLines.errors.cancelAccrued'));
        }
        $l->update(['status' => 'CANCELLED']);
    }
}
