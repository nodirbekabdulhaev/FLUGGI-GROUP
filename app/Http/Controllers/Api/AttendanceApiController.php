<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lesson;
use App\Services\AttendanceService;
use Illuminate\Http\Request;

class AttendanceApiController extends Controller
{
    /** POST /api/v1/attendance {lesson_id, rows:[{student_id,status,comment}]} */
    public function store(Request $request, AttendanceService $svc)
    {
        $d = $request->validate([
            'lesson_id' => 'required|integer', 'rows' => 'required|array|min:1',
            'rows.*.student_id' => 'required|integer', 'rows.*.status' => 'required|in:present,absent,late,excused', 'rows.*.comment' => 'nullable|string|max:255',
        ]);
        $lesson = Lesson::visibleTo($request->user())->findOrFail($d['lesson_id']);
        $rows = collect($d['rows'])->mapWithKeys(fn ($r) => [$r['student_id'] => ['status' => $r['status'], 'comment' => $r['comment'] ?? null]])->all();
        $lesson = $svc->mark($lesson, $rows, $request->user()->id);

        return response()->json(['data' => ['lesson_id' => $lesson->id, 'status' => $lesson->status, 'marked' => count($rows)]]);
    }
}
