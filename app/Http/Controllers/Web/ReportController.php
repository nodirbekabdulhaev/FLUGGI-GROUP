<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\ExportService;
use App\Services\ReportService;
use App\Support\Period;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ReportController extends Controller
{
    public function __construct(protected ReportService $reports) {}

    protected function period(Request $request): array
    {
        return Period::resolve($request->query('period'), $request->query('from'), $request->query('to'));
    }

    public function sales(Request $request)
    {
        [$from, $to, $key] = $this->period($request);

        return view('reports.sales', ['d' => $this->reports->sales($from, $to, $request->user()), 'from' => $from, 'to' => $to, 'key' => $key]);
    }

    public function sources(Request $request)
    {
        [$from, $to, $key] = $this->period($request);

        return view('reports.sources', ['d' => $this->reports->sales($from, $to, $request->user()), 'from' => $from, 'to' => $to, 'key' => $key]);
    }

    public function managers(Request $request)
    {
        [$from, $to, $key] = $this->period($request);

        return view('reports.managers', ['d' => $this->reports->sales($from, $to, $request->user()), 'from' => $from, 'to' => $to, 'key' => $key]);
    }

    public function finance(Request $request)
    {
        [$from, $to, $key] = $this->period($request);

        return view('reports.finance', ['d' => $this->reports->finance($from, $to), 'from' => $from, 'to' => $to, 'key' => $key]);
    }

    public function students(Request $request)
    {
        [$from, $to, $key] = $this->period($request);

        return view('reports.students', ['d' => $this->reports->students($from, $to), 'from' => $from, 'to' => $to, 'key' => $key]);
    }

    public function attendance(Request $request)
    {
        [$from, $to, $key] = $this->period($request);

        return view('reports.attendance', ['d' => $this->reports->attendance($from, $to), 'from' => $from, 'to' => $to, 'key' => $key]);
    }

    public function teachers(Request $request)
    {
        [$from, $to, $key] = $this->period($request);

        return view('reports.teachers', ['rows' => $this->reports->teachers($from, $to), 'from' => $from, 'to' => $to, 'key' => $key]);
    }

    /** CSV / PDF export of the report tables: /reports/export/{type}?format=csv|pdf&period=… */
    public function export(Request $request, string $type, ExportService $export)
    {
        [$from, $to] = $this->period($request);
        $pct = fn ($v) => $v === null ? '' : $v.'%';

        [$perm, $title, $head, $rows, $summary] = match ($type) {
            'sources' => (function () use ($from, $to, $request, $pct) {
                $d = $this->reports->sales($from, $to, $request->user());

                return ['reports.sales', 'Источники лидов', ['Источник', 'Лиды', 'Продажи', 'Конверсия', 'Выручка', 'Средний чек'],
                    $d['sources']->map(fn ($s) => [$s['name'], $s['leads'], $s['sales'], $pct($s['conversion']), $s['revenue'], $s['avg_check']]), []];
            })(),
            'managers' => (function () use ($from, $to, $request, $pct) {
                $d = $this->reports->sales($from, $to, $request->user());

                return ['reports.sales', 'KPI менеджеров', ['Менеджер', 'Новые лиды', 'Обработано', 'Продажи', 'Сумма продаж', 'Конверсия', 'Средний чек'],
                    $d['managers']->map(fn ($s) => [$s['name'], $s['leads'], $s['processed'], $s['sales'], $s['revenue'], $pct($s['conversion']), $s['avg_check']]), []];
            })(),
            'finance' => (function () use ($from, $to) {
                $d = $this->reports->finance($from, $to);

                return ['reports.finance', 'Финансовый отчёт', ['Показатель', 'Значение'], collect([
                    ['Выручка', $d['revenue']], ['Расходы', $d['expenses']], ['Прибыль', $d['profit']], ['Долги', $d['debts']],
                    ['Количество оплат', $d['payments_count']], ['Средний чек', $d['avg_check']],
                ])->concat($d['by_method']->map(fn ($m) => ['Способ: '.$m->name, $m->total]))->concat($d['by_category']->map(fn ($m) => ['Расход: '.$m->name, $m->total])), []];
            })(),
            'attendance' => (function () use ($from, $to, $pct) {
                $d = $this->reports->attendance($from, $to);

                return ['reports.attendance', 'Посещаемость по группам', ['Группа', 'Занятий', 'Отметок', 'Был', 'Опоздал', 'Не был', 'Уваж.', '%'],
                    $d['groups']->map(fn ($g) => [$g->name, $g->lessons, $g->total, $g->present, $g->late, $g->absent, $g->excused, $pct($g->percent)]), ['Общая посещаемость' => $pct($d['percent'])]];
            })(),
            'teachers' => (function () use ($from, $to, $pct) {
                return ['reports.teachers', 'KPI преподавателей', ['Преподаватель', 'Групп', 'Учеников', 'Занятий', 'Посещаемость', 'Ср. по группам', 'Отмен', 'Начислено'],
                    $this->reports->teachers($from, $to)->map(fn ($t) => [$t['name'], $t['groups'], $t['students'], $t['lessons'], $pct($t['attendance']), $pct($t['avg_group_attendance']), $t['cancelled'], $t['salary']]), []];
            })(),
            'students' => (function () use ($from, $to, $pct) {
                $d = $this->reports->students($from, $to);

                return ['reports.students', 'Ученики', ['Показатель', 'Значение'], collect([
                    ['Новые', $d['new']], ['Активные', $d['active']], ['Замороженные', $d['frozen']], ['Отчисленные', $d['expelled']],
                    ['Завершили', $d['completed']], ['Retention', $pct($d['retention'])], ['Churn', $pct($d['churn'])],
                ])->concat($d['reasons']->map(fn ($r) => ['Причина: '.$r->name, $r->c])), []];
            })(),
            default => abort(404),
        };
        Gate::authorize($perm);

        $suffix = ' '.$from->format('d.m.Y').' — '.$to->format('d.m.Y');

        return $request->query('format') === 'pdf'
            ? $export->pdf('report_'.$type, $title.$suffix, $head, $rows, $summary)
            : $export->csv('report_'.$type, $head, $rows);
    }
}
