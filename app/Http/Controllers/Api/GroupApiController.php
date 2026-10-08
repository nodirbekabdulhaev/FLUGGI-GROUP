<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Group;
use Illuminate\Http\Request;

class GroupApiController extends Controller
{
    public function index(Request $request)
    {
        $groups = Group::visibleTo($request->user())->with('course:id,name', 'teacher:id,full_name')->withCount(['activeEnrollments as students_count'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))->orderBy('name')->paginate(min(100, (int) $request->query('per_page', 50)));

        return response()->json(['data' => $groups->getCollection()->map(fn ($g) => [
            'id' => $g->id, 'name' => $g->name, 'status' => $g->status, 'course' => $g->course?->name, 'teacher' => $g->teacher?->full_name,
            'branch_id' => $g->branch_id, 'students' => $g->students_count, 'max_students' => $g->max_students,
        ]), 'meta' => ['total' => $groups->total(), 'page' => $groups->currentPage()]]);
    }
}
