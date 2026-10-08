<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PaymentService;
use App\Support\Lookup;
use Illuminate\Http\Request;

class PaymentApiController extends Controller
{
    public function store(Request $request, PaymentService $svc)
    {
        $d = $request->validate([
            'student_id' => ['required', Lookup::exists('students')], 'group_id' => ['nullable', Lookup::exists('groups')],
            'amount' => 'required|numeric|gt:0|max:999999999', 'method_id' => ['required', Lookup::exists('payment_methods')],
            'paid_at' => 'nullable|date|before_or_equal:now', 'comment' => 'nullable|string|max:255',
        ]);
        $p = $svc->record($d, $request->user()->id);

        return response()->json(['data' => $p->only(['id', 'student_id', 'group_id', 'amount', 'method_id']) + ['paid_at' => $p->paid_at->toIso8601String()]], 201);
    }
}
