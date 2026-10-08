<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

/** One-click login for the public demo (route exists only when DEMO_MODE=true). */
class DemoController extends Controller
{
    public const ROLES = [
        'director' => ['director@demo.uz', 'Директор', 'Вся картина: финансы, продажи, аналитика'],
        'admin' => ['administrator@demo.uz', 'Администратор', 'Ученики, оплаты, долги, расписание (филиал Sardoba)'],
        'sales' => ['sales@demo.uz', 'Менеджер по продажам', 'Лиды, воронка, конверсия'],
        'teacher' => ['teacher1@demo.uz', 'Преподаватель', 'Только свои группы и посещаемость'],
        'accountant' => ['accountant@demo.uz', 'Бухгалтер', 'Оплаты, расходы, зарплаты'],
        'owner' => ['admin@demo.uz', 'Super Admin', 'Полный доступ, настройки'],
    ];

    public function login(string $role)
    {
        abort_unless(config('app.demo') && isset(self::ROLES[$role]), 404);
        $user = User::where('email', self::ROLES[$role][0])->firstOrFail();
        Auth::login($user);
        request()->session()->regenerate();

        return redirect()->route('home');
    }
}
