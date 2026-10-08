<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Debt;
use App\Models\Payment;
use App\Models\Student;
use App\Services\ExportService;
use App\Services\PaymentService;
use App\Support\Lookup;
use App\Support\Period;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class PaymentController extends Controller
{
    public function __construct(protected PaymentService $svc) {}

    public function index(Request $request)
    {
        [$from, $to, $key] = Period::resolve($request->query('period'), $request->query('from'), $request->query('to'));
        $q = Payment::with(['student:id,first_name,last_name,phone', 'group:id,name', 'course:id,name', 'method:id,name', 'cashier:id,name', 'branch:id,name'])
            ->whereBetween('paid_at', [$from, $to]);

        if ($term = trim((string) $request->query('q'))) {
            $digits = preg_replace('/\D+/', '', $term);
            $q->whereHas('student', function ($s) use ($term, $digits) {
                $s->where(function ($w) use ($term, $digits) {
                    $w->where('first_name', 'like', "%$term%")->orWhere('last_name', 'like', "%$term%");
                    if (strlen($digits) >= 3) {
                        $w->orWhere('phone', 'like', "%$digits%");
                    }
                    if (ctype_digit(ltrim($term, '#'))) {
                        $w->orWhere('id', (int) ltrim($term, '#'));
                    }
                });
            });
        }
        foreach (['method_id', 'type', 'group_id', 'course_id', 'cashier_id'] as $f) {
            if ($request->filled($f)) {
                $q->where($f, $request->query($f));
            }
        }
        if ($request->filled('min')) {
            $q->where('amount', '>=', (float) $request->query('min'));
        }
        if ($request->filled('max')) {
            $q->where('amount', '<=', (float) $request->query('max'));
        }

        $total = (float) (clone $q)->sum(DB::raw(Payment::SIGNED_SQL));
        $q->orderByDesc('paid_at')->orderByDesc('id');

        if (in_array($request->query('export'), ['csv', 'pdf'], true)) {
            $rows = $q->get()->map(fn ($p) => [$p->id, $p->paid_at->format('d.m.Y H:i'), $p->student?->full_name, $p->group?->name, $p->course?->name,
                $p->method?->name, __(Payment::TYPES[$p->type]), ($p->type === 'refund' ? -1 : 1) * $p->amount, $p->branch?->name, $p->cashier?->name, $p->comment]);
            $head = ['ID', 'Дата', 'Ученик', 'Группа', 'Курс', 'Способ', 'Тип', 'Сумма', 'Филиал', 'Кассир', 'Комментарий'];

            return $request->query('export') === 'csv'
                ? app(ExportService::class)->csv('payments', $head, $rows)
                : app(ExportService::class)->pdf('payments', 'Оплаты '.$from->format('d.m.Y').' — '.$to->format('d.m.Y'), $head, $rows, ['Итого' => money($total)]);
        }

        return view('payments.index', [
            'payments' => $q->paginate(30)->withQueryString(), 'total' => $total, 'from' => $from, 'to' => $to, 'key' => $key,
            'cashiers' => Lookup::users(),
        ]);
    }

    public function create(Request $request)
    {
        $student = $request->filled('student_id') ? Student::withTrashed()->find($request->query('student_id')) : null;

        return view('payments.form', [
            'student' => $student, 'methods' => Lookup::methods(), 'canCorrect' => Gate::allows('payments.correct'),
            'defaultMethod' => array_key_first(array_filter(Lookup::methods(), fn ($n) => in_array($n, ['Наличные', 'Cash']))) ?? array_key_first(Lookup::methods()),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'student_id' => ['required', Lookup::exists('students')],
            'group_id' => ['nullable', Lookup::exists('groups')],
            'amount' => 'required|numeric|min:-999999999|max:999999999',
            'method_id' => ['required', Lookup::exists('payment_methods')],
            'paid_at' => 'nullable|date|before_or_equal:now',
            'comment' => 'nullable|string|max:255',
            'type' => 'nullable|in:payment,refund,correction',
        ]);
        $data['type'] = $data['type'] ?? 'payment';
        if ($data['type'] !== 'payment') {
            Gate::authorize('payments.correct');
        }
        $payment = $this->svc->record($data, $request->user()->id);

        return redirect()->route('students.show', $payment->student_id)->with('ok', __('Оплата принята').': '.money($payment->amount));
    }

    /** JSON for the payment form: balance and open memberships of a student. */
    public function balance(int $student)
    {
        $s = Student::withTrashed()->findOrFail($student);
        $f = $s->financeSummary();

        return response()->json([
            'name' => $s->full_name, 'phone' => $s->phone, 'charged' => $f['charged'], 'paid' => $f['paid'], 'balance' => $f['balance'],
            'groups' => Debt::with('group:id,name')->where('student_id', $s->id)->get()->map(fn ($d) => [
                'group_id' => $d->group_id, 'name' => $d->group?->name, 'balance' => $d->balance,
            ])->values(),
        ]);
    }

    public function edit(Payment $payment)
    {
        return view('payments.edit', ['payment' => $payment->load('student'), 'methods' => Lookup::methods()]);
    }

    public function update(Request $request, Payment $payment)
    {
        $data = $request->validate([
            'amount' => 'required|numeric|min:-999999999|max:999999999', 'method_id' => ['required', Lookup::exists('payment_methods')],
            'paid_at' => 'required|date', 'comment' => 'nullable|string|max:255',
        ]);
        $this->svc->update($payment, $data);

        return redirect()->route('payments.index')->with('ok', __('Оплата изменена'));
    }

    public function destroy(Payment $payment)
    {
        $this->svc->delete($payment);

        return redirect()->route('payments.index')->with('ok', __('Оплата удалена'));
    }
}
