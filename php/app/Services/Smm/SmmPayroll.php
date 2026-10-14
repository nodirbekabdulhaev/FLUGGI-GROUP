<?php

namespace App\Services\Smm;

use App\Models\Employee;
use App\Models\User;
use App\Support\Audit;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Оклад SMM-специалиста по плану роликов: оклад при 100 % плана × min(опубликовано / план, 1).
 * Оклад при 100 % — employees.base_salary, план роликов в месяц — employees.video_target.
 */
final class SmmPayroll
{
    /** Исполнитель SMM-специальности с планом роликов — оклад считается по плану. */
    public static function hasVideoPlan(?User $user): bool
    {
        return SmmAccess::isSmmExecutor($user) && $user->employee?->video_target !== null && $user->employee->video_target > 0;
    }

    /** Оклад за месяц (2 знака): full × min(published / target, 1). */
    public static function base(string $full, int $published, int $target): string
    {
        if ($target <= 0) {
            return (string) BigDecimal::of($full)->toScale(2);
        }
        $share = min($published, $target);

        return (string) BigDecimal::of($full)->multipliedBy($share)->dividedBy($target, 2, RoundingMode::HalfUp);
    }

    /** Процент выполнения плана (0–100, целое — для подписи «9 из 12 видео (75%)»). */
    public static function pct(int $published, int $target): int
    {
        return $target > 0 ? (int) floor(min($published, $target) * 100 / $target) : 0;
    }

    /** Активные исполнители SMM-специальностей — для блока «SMM: оклад и план роликов». */
    public static function plans(): Collection
    {
        return User::with(['employee', 'role'])->where('status', 'ACTIVE')
            ->whereHas('role', fn ($q) => $q->where('code', 'EXECUTOR'))
            ->whereHas('employee', fn ($q) => $q->withSpecialty(SmmAccess::SPECIALTIES))
            ->orderBy('full_name')->get();
    }

    /**
     * @param  array<string, array{salary?:?string, target?:?int}>  $plans  user_id → оклад при 100 % и роликов в месяц
     */
    public static function save(array $plans): void
    {
        $users = self::plans()->keyBy('id');
        DB::transaction(function () use ($plans, $users) {
            foreach ($plans as $userId => $p) {
                $user = $users[$userId] ?? abort(404);
                $oldSalary = $user->employee?->base_salary;
                $oldTarget = $user->employee?->video_target;
                $salary = ($p['salary'] ?? null) === null || $p['salary'] === '' ? null : (string) BigDecimal::of($p['salary'])->toScale(2);
                $target = ($p['target'] ?? null) === null || $p['target'] === '' ? null : (int) $p['target'];
                $sameSalary = $oldSalary === null || $salary === null ? $oldSalary === $salary : BigDecimal::of((string) $oldSalary)->isEqualTo($salary);
                if ($sameSalary && $oldTarget === $target) {
                    continue;
                }
                Employee::updateOrCreate(['user_id' => $userId], ['base_salary' => $salary, 'video_target' => $target]);
                Audit::log('employee.video_plan_update', 'user', $userId, array_filter([
                    'baseSalary' => $sameSalary ? null : ['old' => $oldSalary === null ? null : (string) $oldSalary, 'new' => $salary],
                    'videoTarget' => $oldTarget === $target ? null : ['old' => $oldTarget, 'new' => $target],
                ]));
            }
        });
    }
}
