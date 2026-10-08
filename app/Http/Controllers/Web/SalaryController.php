<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\SalaryAccrual;
use App\Models\SalaryPayment;
use App\Models\Teacher;
use App\Services\SalaryService;
use App\Support\Lookup;
use Carbon\Carbon;
use Illuminate\Http\Request;

class SalaryController extends Controller
{
    public function __construct(protected SalaryService $svc) {}

    public function index(Request $request)
    {
        $period = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) $request->query('period')) ? $request->query('period') : now()->format('Y-m');

        $accrued = SalaryAccrual::selectRaw('payable_type, payable_id, SUM(amount) s')->groupBy('payable_type', 'payable_id')->get()->keyBy(fn ($r) => $r->payable_type.':'.$r->payable_id);
        $thisPeriod = SalaryAccrual::where('period', $period)->get()->keyBy(fn ($r) => $r->payable_type.':'.$r->payable_id);
        $paid = SalaryPayment::selectRaw('payable_type, payable_id, SUM(amount) s')->groupBy('payable_type', 'payable_id')->get()->keyBy(fn ($r) => $r->payable_type.':'.$r->payable_id);

        $payees = collect();
        foreach (Teacher::where('status', '!=', 'inactive')->orderBy('full_name')->get() as $t) {
            $payees->push(['model' => $t, 'kind' => 'teacher', 'label' => 'Преподаватель']);
        }
        foreach (Employee::where('status', 'active')->orderBy('full_name')->get() as $e) {
            $payees->push(['model' => $e, 'kind' => 'employee', 'label' => $e->position ?: 'Сотрудник']);
        }
        $rows = $payees->map(function ($p) use ($accrued, $thisPeriod, $paid) {
            $k = $p['kind'].':'.$p['model']->id;
            $a = (float) ($accrued[$k]->s ?? 0);
            $pd = (float) ($paid[$k]->s ?? 0);

            return $p + ['period' => $thisPeriod[$k] ?? null, 'accrued' => $a, 'paid' => $pd, 'debt' => $a - $pd];
        });

        return view('salaries.index', [
            'rows' => $rows, 'period' => $period, 'methods' => Lookup::methods(),
            'payments' => SalaryPayment::with('payable')->latest('paid_at')->latest('id')->limit(15)->get(),
            'totals' => ['accrued' => $rows->sum('accrued'), 'paid' => $rows->sum('paid'), 'debt' => $rows->sum('debt')],
        ]);
    }

    public function accrue(Request $request)
    {
        $data = $request->validate(['period' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/']]);
        $n = $this->svc->accrue($data['period'])->count();

        return redirect()->route('salaries.index', ['period' => $data['period']])->with('ok', __('Начислено записей').": $n");
    }

    public function pay(Request $request)
    {
        $data = $request->validate([
            'kind' => 'required|in:teacher,employee', 'id' => 'required|integer', 'amount' => 'required|numeric|min:1|max:999999999999',
            'method_id' => ['nullable', Lookup::exists('payment_methods')], 'paid_at' => 'nullable|date', 'comment' => 'nullable|string|max:255',
        ]);
        $payee = $data['kind'] === 'teacher' ? Teacher::findOrFail($data['id']) : Employee::findOrFail($data['id']);
        $this->svc->pay($payee, (float) $data['amount'], $data['method_id'] ?? null, $data['paid_at'] ?? null, $data['comment'] ?? null);

        return back()->with('ok', __('Выплата записана'));
    }
}
