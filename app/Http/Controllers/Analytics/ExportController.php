<?php

namespace App\Http\Controllers\Analytics;

use App\Http\Controllers\Controller;
use App\Services\Analytics\AccountantPackage;
use App\Services\Analytics\ExportService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/** Экспорт списков в Excel / CSV и годовой пакет для бухгалтера (ТЗ §44). */
class ExportController extends Controller
{
    public function export(Request $request, string $entity): Response
    {
        if (! in_array($entity, ExportService::ENTITIES, true)) {
            abort(422, t('export.unknown'));
        }
        $q = $request->validate([
            'format' => ['nullable', Rule::in(['csv', 'xlsx'])],
            'period' => ['nullable', Rule::in(['today', 'week', 'month', 'quarter', 'year', 'custom', 'all'])],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $f = ExportService::file(access(), $entity, $q['format'] ?? 'xlsx', $q['period'] ?? 'all', $q['from'] ?? null, $q['to'] ?? null);

        return response($f['body'], 200, [
            'Content-Type' => $f['contentType'],
            'Content-Disposition' => 'attachment; filename="'.$f['filename'].'"',
            'X-Export-Rows' => (string) $f['rows'],
            'Cache-Control' => 'no-store',
        ]);
    }

    /** Годовой пакет для бухгалтера (CEO): договоры, оплаты, расчёты с клиентами, расходы, зарплата. */
    public function accountant(Request $request): Response
    {
        $q = $request->validate(['year' => ['required', 'integer', 'min:2020', 'max:2100']]);
        $f = AccountantPackage::file((int) $q['year']);

        return response($f['body'], 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$f['filename'].'"',
            'Cache-Control' => 'no-store',
        ]);
    }
}
