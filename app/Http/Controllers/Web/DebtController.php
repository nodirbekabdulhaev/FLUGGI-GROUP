<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Debt;
use App\Services\ExportService;
use App\Support\Lookup;
use Illuminate\Http\Request;

class DebtController extends Controller
{
    public function index(Request $request)
    {
        $q = Debt::with(['student' => fn ($s) => $s->withTrashed()->with('guardians:id,full_name,phone', 'manager:id,name'), 'group:id,name', 'course:id,name', 'branch:id,name'])
            ->where('balance', '>', 0);

        foreach (['course_id', 'group_id'] as $f) {
            if ($request->filled($f)) {
                $q->where($f, $request->query($f));
            }
        }
        if ($request->filled('min')) {
            $q->where('balance', '>=', (float) $request->query('min'));
        }
        if ($request->filled('from')) {
            $q->whereDate('next_payment_date', '>=', $request->query('from'));
        }
        if ($request->filled('to')) {
            $q->whereDate('next_payment_date', '<=', $request->query('to'));
        }
        if ($request->boolean('overdue')) {
            $q->whereNotNull('next_payment_date')->where('next_payment_date', '<', today()->toDateString());
        }
        if ($term = trim((string) $request->query('q'))) {
            $q->whereHas('student', fn ($s) => $s->withTrashed()->where(fn ($w) => $w->where('first_name', 'like', "%$term%")->orWhere('last_name', 'like', "%$term%")->orWhere('phone', 'like', '%'.preg_replace('/\D+/', '', $term).'%')));
        }

        $sorts = ['balance', 'next_payment_date', 'last_payment_at'];
        $sort = in_array($request->query('sort'), $sorts, true) ? $request->query('sort') : 'balance';
        $dir = $request->query('dir') === 'asc' ? 'asc' : 'desc';
        $q->orderBy($sort, $dir);

        $total = (float) (clone $q)->reorder()->sum('balance');
        $count = (clone $q)->reorder()->count();

        if (in_array($request->query('export'), ['csv', 'pdf'], true)) {
            $rows = $q->get()->map(fn ($d) => [$d->student?->full_name, $d->student?->phone, $d->student?->guardians->first()?->full_name, $d->group?->name,
                $d->balance, fdate($d->last_payment_at), fdate($d->next_payment_date), $d->student?->manager?->name, $d->branch?->name]);
            $head = ['Ученик', 'Телефон', 'Родитель', 'Группа', 'Долг', 'Последняя оплата', 'След. оплата', 'Менеджер', 'Филиал'];

            return $request->query('export') === 'csv'
                ? app(ExportService::class)->csv('debts', $head, $rows)
                : app(ExportService::class)->pdf('debts', 'Должники на '.now()->format('d.m.Y'), $head, $rows, ['Должников' => $count, 'Сумма' => money($total)]);
        }

        return view('debts.index', [
            'debts' => $q->paginate(30)->withQueryString(), 'total' => $total, 'count' => $count, 'sort' => $sort, 'dir' => $dir,
            'groups' => Lookup::groups(), 'courses' => Lookup::courses(),
        ]);
    }
}
