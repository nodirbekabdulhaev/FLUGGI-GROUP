<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lesson;
use Carbon\Carbon;
use Illuminate\Http\Request;

class LessonApiController extends Controller
{
    public function index(Request $request)
    {
        $from = $request->filled('from') ? Carbon::parse($request->query('from')) : today();
        $to = $request->filled('to') ? Carbon::parse($request->query('to')) : $from->copy()->addDays(6);
        abort_if($from->diffInDays($to) > 93, 422, 'Range too long (max 93 days)');

        $lessons = Lesson::visibleTo($request->user())->with('group:id,name', 'teacher:id,full_name', 'room:id,name')
            ->whereBetween('lesson_date', [$from->toDateString(), $to->toDateString()])
            ->when($request->filled('group_id'), fn ($q) => $q->where('group_id', $request->query('group_id')))
            ->orderBy('lesson_date')->orderBy('start_time')->get();

        return response()->json(['data' => $lessons->map(fn ($l) => [
            'id' => $l->id, 'date' => $l->lesson_date->toDateString(), 'start' => ftime($l->start_time), 'end' => ftime($l->end_time),
            'status' => $l->status, 'group' => $l->group?->name, 'group_id' => $l->group_id, 'teacher' => $l->teacher?->full_name, 'room' => $l->room?->name,
        ])]);
    }
}
