<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\LeadStatus;
use App\Services\LeadService;
use App\Support\Lookup;
use Illuminate\Http\Request;

class LeadApiController extends Controller
{
    protected function shape(Lead $l): array
    {
        return $l->only(['id', 'first_name', 'last_name', 'phone', 'telegram', 'instagram', 'source_id', 'course_id', 'branch_id', 'manager_id', 'status_id'])
            + ['created_at' => $l->created_at->toIso8601String(), 'converted_student_id' => $l->converted_student_id];
    }

    public function index(Request $request)
    {
        $q = Lead::visibleTo($request->user());
        foreach (['status_id', 'source_id', 'manager_id'] as $f) {
            if ($request->filled($f)) {
                $q->where($f, $request->query($f));
            }
        }
        $p = $q->orderByDesc('id')->paginate(min(100, (int) $request->query('per_page', 25)));

        return response()->json(['data' => $p->getCollection()->map(fn ($l) => $this->shape($l)), 'meta' => ['total' => $p->total(), 'page' => $p->currentPage(), 'last_page' => $p->lastPage()]]);
    }

    public function show(Request $request, int $id)
    {
        return response()->json(['data' => $this->shape(Lead::visibleTo($request->user())->findOrFail($id))]);
    }

    public function store(Request $request, LeadService $leads)
    {
        $d = $request->validate([
            'first_name' => 'required|string|max:100', 'last_name' => 'nullable|string|max:100', 'phone' => 'required|string|max:32|regex:/^[+\d\s\-()]{7,20}$/',
            'telegram' => 'nullable|string|max:100', 'instagram' => 'nullable|string|max:100', 'comment' => 'nullable|string|max:2000',
            'source_id' => ['nullable', Lookup::exists('lead_sources')], 'course_id' => ['nullable', Lookup::exists('courses')],
            'branch_id' => ['nullable', Lookup::exists('branches')], 'manager_id' => ['nullable', Lookup::exists('users')],
            'status_id' => ['nullable', Lookup::exists('lead_statuses')],
        ]);
        $d['branch_id'] = $d['branch_id'] ?? Lookup::defaultBranch();
        $lead = $leads->create($d, $request->user()->id);

        return response()->json(['data' => $this->shape($lead)], 201);
    }
}
