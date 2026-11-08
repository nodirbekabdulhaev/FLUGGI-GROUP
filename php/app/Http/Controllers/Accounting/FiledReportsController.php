<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Services\Accounting\Accounting;
use App\Services\Accounting\FiledReports;
use App\Services\Accounting\TaxesService;
use App\Services\Finance\Period;
use App\Services\Import\ImportStore;
use App\Services\Import\RowError;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Сданные в налоговую отчёты: сальдо НДФЛ по месяцам и загрузка файлов отчётов (люди, доход и НДФЛ за прошлые годы). */
class FiledReportsController extends Controller
{
    public function index(Request $request): View
    {
        $to = Period::month($request->query('to'));
        $ledger = TaxesService::ndflLedger($to);

        return view('accounting.filed-reports', [
            'to' => $to, 'ledger' => $ledger, 'compare' => TaxesService::actCompare($to), 'canManage' => access()->can('accounting.manage'),
            'settings' => Accounting::settings(), 'filedCount' => count(FiledReports::map()['filed']),
        ]);
    }

    public function upload(Request $request): RedirectResponse
    {
        $mb = FiledReports::MAX_BYTES / 1048576;
        $request->validate([
            'files' => ['required', 'array', 'min:1', 'max:'.FiledReports::MAX_FILES],
            'files.*' => ['file', 'max:'.(FiledReports::MAX_BYTES / 1024 * 4), 'extensions:'.implode(',', FiledReports::EXTENSIONS)],
        ], [
            'files.required' => t('import.errors.fileRequired'),
            'files.max' => t('accounting.filed.errors.tooMany', ['max' => FiledReports::MAX_FILES]),
            'files.*.file' => t('import.errors.fileRequired'),
            'files.*.uploaded' => t('import.errors.fileSize', ['mb' => $mb * 4]),
            'files.*.max' => t('import.errors.fileSize', ['mb' => $mb * 4]),
            'files.*.extensions' => t('accounting.filed.errors.type'),
        ]);
        try {
            $token = FiledReports::upload($request->file('files'));
        } catch (RowError $e) {
            return back()->withErrors(['files' => $e->getMessage()])->with('error', $e->getMessage());
        }

        return redirect('/accounting/filed-reports/'.$token);
    }

    public function preview(string $token): View|RedirectResponse
    {
        $data = FiledReports::preview($token);
        if (! $data) {
            return redirect('/accounting/filed-reports')->with('error', t('import.expired'));
        }

        return view('accounting.filed-reports-preview', ['token' => $token, 'data' => $data, 'plan' => FiledReports::plan($data['months'], $data['acts'] ?? [])]);
    }

    public function confirm(string $token): RedirectResponse
    {
        $r = FiledReports::confirm($token);
        if (! $r) {
            return redirect('/accounting/filed-reports')->with('error', t('import.expired'));
        }

        return redirect('/accounting/filed-reports')->with('ok', t('accounting.filed.done', ['months' => $r['months'], 'created' => $r['created'], 'updated' => $r['updated'], 'acts' => $r['acts']]));
    }

    public function cancel(string $token): RedirectResponse
    {
        if (FiledReports::preview($token)) {
            ImportStore::forget($token);
        }

        return redirect('/accounting/filed-reports')->with('ok', t('import.cancelled'));
    }
}
