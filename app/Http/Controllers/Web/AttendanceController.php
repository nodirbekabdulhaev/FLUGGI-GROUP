<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Group;
use App\Services\ReportService;
use App\Support\Period;
use Illuminate\Http\Request;

/** Attendance journal: a group × lessons matrix, or per-group summary when no group is chosen. */
class AttendanceController extends Controller
{
    public function index(Request $request, ReportService $reports)
    {
        [$from, $to, $key] = Period::resolve($request->query('period'), $request->query('from'), $request->query('to'));
        $groups = Group::visibleTo($request->user())->orderBy('name')->pluck('name', 'id');
        $group = $request->filled('group_id') ? Group::visibleTo($request->user())->findOrFail($request->query('group_id')) : null;

        $matrix = null;
        if ($group) {
            $lessons = $group->lessons()->whereBetween('lesson_date', [$from->toDateString(), $to->toDateString()])->whereIn('status', ['held', 'planned'])
                ->orderBy('lesson_date')->orderBy('start_time')->get();
            $marks = Attendance::whereIn('lesson_id', $lessons->pluck('id'))->get()->groupBy('student_id');
            $students = \App\Models\Student::withTrashed()->whereIn('id', $group->enrollments()->pluck('student_id')->merge($marks->keys())->unique())->orderBy('first_name')->get();
            $matrix = compact('lessons', 'marks', 'students');
        }

        $summary = $group ? null : $reports->attendance($from, $to);
        if ($summary && $request->user()->restrictedToOwnGroups()) {
            $summary['groups'] = $summary['groups']->whereIn('id', $groups->keys());
            $summary['percent'] = null;     // org-wide figure is not for teachers
        }

        return view('attendance.index', [
            'groups' => $groups, 'group' => $group, 'matrix' => $matrix, 'from' => $from, 'to' => $to, 'key' => $key,
            'summary' => $summary,
        ]);
    }
}
