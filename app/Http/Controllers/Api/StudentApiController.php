<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Services\EnrollmentService;
use App\Support\Lookup;
use Illuminate\Http\Request;

class StudentApiController extends Controller
{
    protected function shape(Student $s): array
    {
        return [
            'id' => $s->id, 'first_name' => $s->first_name, 'last_name' => $s->last_name, 'phone' => $s->phone, 'telegram' => $s->telegram,
            'status' => $s->status, 'branch_id' => $s->branch_id, 'birth_date' => $s->birth_date?->toDateString(), 'registered_at' => $s->registered_at?->toDateString(),
            'balance' => array_key_exists('f_charged', $s->getAttributes()) ? $s->balance : null,
        ];
    }

    public function index(Request $request)
    {
        $q = Student::visibleTo($request->user())->withFinance();
        if ($t = trim((string) $request->query('q'))) {
            $q->where(fn ($w) => $w->where('first_name', 'like', "%$t%")->orWhere('last_name', 'like', "%$t%")->orWhere('phone', 'like', '%'.preg_replace('/\D+/', '', $t).'%'));
        }
        if ($request->filled('status')) {
            $q->where('status', $request->query('status'));
        }
        $p = $q->orderByDesc('id')->paginate(min(100, (int) $request->query('per_page', 25)));

        return response()->json(['data' => $p->getCollection()->map(fn ($s) => $this->shape($s)), 'meta' => ['total' => $p->total(), 'page' => $p->currentPage(), 'last_page' => $p->lastPage()]]);
    }

    public function show(Request $request, int $id)
    {
        $s = Student::visibleTo($request->user())->withFinance()->with('guardians:id,full_name,phone')->findOrFail($id);

        return response()->json(['data' => $this->shape($s) + ['guardians' => $s->guardians->map->only(['id', 'full_name', 'phone'])]]);
    }

    public function store(Request $request)
    {
        $d = $request->validate([
            'first_name' => 'required|string|max:100', 'last_name' => 'nullable|string|max:100', 'phone' => 'nullable|string|max:32',
            'telegram' => 'nullable|string|max:100', 'birth_date' => 'nullable|date|before:today', 'gender' => 'nullable|in:male,female',
            'branch_id' => ['required', Lookup::exists('branches')], 'group_id' => ['nullable', Lookup::exists('groups')],
        ]);
        $group = $d['group_id'] ?? null;
        unset($d['group_id']);
        $student = Student::create($d + ['status' => 'active', 'registered_at' => today()->toDateString(), 'status_changed_at' => today()->toDateString()]);
        if ($group) {
            app(EnrollmentService::class)->enroll($student, \App\Models\Group::findOrFail($group));
        }

        return response()->json(['data' => $this->shape($student)], 201);
    }
}
