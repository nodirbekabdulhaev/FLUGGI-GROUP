<?php

namespace Tests\Unit\People;

use App\Services\People\EmployeeScore;
use PHPUnit\Framework\TestCase;

/** Формула оценки эффективности: баллы составляющих, пересчёт весов без данных, буква. */
class EmployeeScoreTest extends TestCase
{
    public function test_component_scores(): void
    {
        $this->assertSame(50.0, EmployeeScore::ratio(10, 20));
        $this->assertSame(100.0, EmployeeScore::ratio(30, 20));     // перевыполнение не выше 100
        $this->assertSame(0.0, EmployeeScore::ratio(-5, 20));
        $this->assertNull(EmployeeScore::ratio(5, 0));               // нет цели — нет данных
        $this->assertSame(75.0, EmployeeScore::share(3, 4));
        $this->assertNull(EmployeeScore::share(0, 0));
        $this->assertSame(80.0, EmployeeScore::reworkScore(1, 5));
        $this->assertSame(0.0, EmployeeScore::reworkScore(7, 5));    // не ниже 0
        $this->assertSame(0.0, EmployeeScore::reworkScore(2, 0));    // одни возвраты
        $this->assertNull(EmployeeScore::reworkScore(0, 0));
    }

    public function test_grades(): void
    {
        $this->assertSame(['A', 'A', 'B', 'B', 'C', 'C', 'D', 'D'], array_map(EmployeeScore::grade(...), [100, 85, 84, 70, 69, 50, 49, 0]));
        $this->assertNull(EmployeeScore::grade(null));
    }

    public function test_combine_renormalizes_weights_of_components_without_data(): void
    {
        $r = EmployeeScore::combine([
            ['key' => 'kpi', 'weight' => 30, 'score' => 80.0],
            ['key' => 'attendance', 'weight' => 20, 'score' => 100.0],
            ['key' => 'meetings', 'weight' => 15, 'score' => null],   // нет встреч — не учитывается
            ['key' => 'todos', 'weight' => 10, 'score' => 40.0],
        ]);
        // (30×80 + 20×100 + 10×40) / 60 = 80
        $this->assertSame(80, $r['score']);
        $this->assertSame('B', $r['grade']);
        $this->assertSame([50.0, 33.3, null, 16.7], array_column($r['components'], 'share'));

        $none = EmployeeScore::combine([['key' => 'kpi', 'weight' => 30, 'score' => null]]);
        $this->assertNull($none['score']);
        $this->assertNull($none['grade']);
        $this->assertSame(['score' => null, 'grade' => null, 'components' => []], EmployeeScore::combine([]));
    }

    public function test_weights_cover_every_role(): void
    {
        foreach (['CEO', 'ROP', 'MANAGER', 'EXECUTOR', 'HR_ADMIN', 'PROJECT_MANAGER', 'ACCOUNTANT'] as $role) {
            $this->assertArrayHasKey($role, EmployeeScore::WEIGHTS);
            $this->assertGreaterThan(0, array_sum(EmployeeScore::WEIGHTS[$role]));
        }
    }
}
