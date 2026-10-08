<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Models\GroupStudent;
use App\Models\Student;
use App\Services\EnrollmentService;
use App\Services\PaymentService;
use App\Support\Lookup;
use Illuminate\Http\Request;

class EnrollmentController extends Controller
{
    public function __construct(protected EnrollmentService $svc, protected PaymentService $payments) {}

    public function store(Request $request)
    {
        $data = $request->validate([
            'student_id' => ['required', Lookup::exists('students')],
            'group_id' => ['required', Lookup::exists('groups')],
            'price' => 'nullable|numeric|min:0',
            'discount' => 'nullable|numeric|min:0',
            'joined_at' => 'nullable|date',
            'next_payment_date' => 'nullable|date',
        ]);
        $gs = $this->svc->enroll(Student::findOrFail($data['student_id']), Group::findOrFail($data['group_id']),
            array_filter(\Illuminate\Support\Arr::except($data, ['student_id', 'group_id']), fn ($v) => $v !== null && $v !== ''));

        return back()->with('ok', __('Ученик записан в группу'));
    }

    public function transfer(Request $request, GroupStudent $enrollment)
    {
        $data = $request->validate(['group_id' => ['required', Lookup::exists('groups')], 'price' => 'nullable|numeric|min:0', 'note' => 'nullable|string|max:255']);
        $this->svc->transfer($enrollment, Group::findOrFail($data['group_id']), array_filter($data, fn ($v) => $v !== null && $v !== '' && ! is_array($v)));

        return back()->with('ok', __('Ученик переведён'));
    }

    public function action(GroupStudent $enrollment, string $action)
    {
        match ($action) {
            'freeze' => $this->svc->freeze($enrollment),
            'unfreeze' => $this->svc->unfreeze($enrollment),
            'complete' => $this->svc->complete($enrollment),
            'leave' => $this->svc->leave($enrollment),
            'renew' => $this->svc->renew($enrollment),
        };

        return back()->with('ok', __('Готово'));
    }

    /** Adjust price / discount / next payment date of one membership; the debt is recalculated. */
    public function update(Request $request, GroupStudent $enrollment)
    {
        $data = $request->validate(['price' => 'required|numeric|min:0', 'discount' => 'required|numeric|min:0', 'next_payment_date' => 'nullable|date']);
        $enrollment->update($data);
        $this->payments->refreshDebt($enrollment);

        return back()->with('ok', __('Сохранено'));
    }
}
