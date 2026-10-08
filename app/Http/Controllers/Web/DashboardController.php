<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Debt;
use App\Models\Lesson;
use App\Services\ReportService;
use App\Support\Period;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class DashboardController extends Controller
{
    public function index(Request $request, ReportService $reports)
    {
        [$from, $to, $key] = Period::resolve($request->query('period'), $request->query('from'), $request->query('to'));
        $user = $request->user();

        $data = $reports->dashboard($from, $to, $user);
        $showFinance = Gate::any(['reports.finance', 'payments.view']);
        $showSales = Gate::allows('leads.view');
        $showStudents = Gate::allows('students.view');

        $topDebts = $showFinance && Gate::allows('debts.view')
            ? Debt::with('student:id,first_name,last_name', 'group:id,name')->where('balance', '>', 0)->orderByDesc('balance')->limit(6)->get()
            : collect();
        $todayLessons = Gate::any(['schedule.view', 'attendance.mark'])
            ? Lesson::visibleTo($user)->with('group:id,name', 'room:id,name', 'teacher:id,full_name')->whereDate('lesson_date', today())->orderBy('start_time')->limit(8)->get()
            : collect();

        return view('dashboard', compact('data', 'from', 'to', 'key', 'showFinance', 'showSales', 'showStudents', 'topDebts', 'todayLessons'));
    }
}
