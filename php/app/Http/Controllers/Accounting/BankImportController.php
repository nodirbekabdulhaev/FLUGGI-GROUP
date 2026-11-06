<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\AccAccount;
use App\Services\Accounting\BankImportService;
use App\Services\Accounting\BankStatement;
use App\Services\Import\ImportFile;
use App\Services\Import\ImportStore;
use App\Services\Import\RowError;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Загрузка банковской выписки: файл → предпросмотр с категориями и дублями → загрузка → итог со сверкой остатка. */
class BankImportController extends Controller
{
    public function show(): View
    {
        return view('accounting.bank-import', ['accounts' => BankImportService::accounts()]);
    }

    public function upload(Request $request): RedirectResponse
    {
        $mb = ImportFile::MAX_BYTES / 1048576;
        $request->validate([
            'file' => ['required', 'file', 'max:'.(ImportFile::MAX_BYTES / 1024), 'extensions:'.implode(',', BankStatement::EXTENSIONS)],
            'account_id' => ['nullable', 'uuid'],
        ], [
            'file.required' => t('import.errors.fileRequired'),
            'file.file' => t('import.errors.fileRequired'),
            'file.uploaded' => t('import.errors.fileSize', ['mb' => $mb]),
            'file.max' => t('import.errors.fileSize', ['mb' => $mb]),
            'file.extensions' => t('import.errors.fileType'),
        ]);
        try {
            $token = BankImportService::upload($request->file('file'), $request->input('account_id') ?: null);
        } catch (RowError $e) {
            return back()->withErrors(['file' => $e->getMessage()])->with('error', $e->getMessage());
        }

        return redirect('/accounting/bank-import/'.$token);
    }

    public function preview(string $token): View|RedirectResponse
    {
        $data = BankImportService::preview($token);
        if (! $data) {
            return $this->gone($token);
        }
        $account = $data['account_id'] ? AccAccount::find($data['account_id']) : null;
        $rows = BankImportService::analyze($data, $account);

        return view('accounting.bank-import-preview', [
            'token' => $token, 'data' => $data, 'account' => $account, 'rows' => $rows,
            'totals' => BankImportService::totals($rows), 'accounts' => BankImportService::accounts(),
            'groups' => BankImportService::choiceGroups(), 'bases' => BankImportService::choiceBases(),
            'matched' => $data['accountNumber'] && $account && $account->account_number === $data['accountNumber'],
        ]);
    }

    public function account(Request $request, string $token): RedirectResponse
    {
        $id = $request->validate(['account_id' => ['required', 'uuid']])['account_id'];
        $new = BankImportService::setAccount($token, $id);
        if (! $new) {
            return BankImportService::preview($token)
                ? back()->withErrors(['account_id' => t('accounting.bank.errors.account')])
                : $this->gone($token);
        }

        return redirect('/accounting/bank-import/'.$new);
    }

    public function confirm(Request $request, string $token): RedirectResponse
    {
        $payload = $request->validate(['payload' => ['nullable', 'string', 'max:2000000']])['payload'] ?? '';
        $choices = json_decode((string) $payload, true);
        $result = BankImportService::confirm($token, is_array($choices) ? $choices : []);
        if (! $result) {
            return $this->gone($token);
        }
        $t = $result['totals'];

        return redirect('/accounting/bank-import/'.$token.'/result')
            ->with('ok', t('accounting.bank.done', ['created' => $t['created'], 'duplicate' => $t['duplicate']]));
    }

    public function result(string $token): View|RedirectResponse
    {
        $result = BankImportService::result($token);
        if (! $result) {
            if (ImportStore::valid($token) && ImportStore::running($token)) {
                return redirect('/accounting/bank-import')->with('error', t('accounting.bank.running'));
            }

            return redirect('/accounting/bank-import')->with('error', t('import.expired'));
        }

        return view('accounting.bank-import-result', ['result' => $result]);
    }

    public function cancel(string $token): RedirectResponse
    {
        if (BankImportService::preview($token)) {
            ImportStore::forget($token);
        }

        return redirect('/accounting/bank-import')->with('ok', t('import.cancelled'));
    }

    private function gone(string $token): RedirectResponse
    {
        if (BankImportService::result($token)) {
            return redirect('/accounting/bank-import/'.$token.'/result')->with('ok', t('import.alreadyImported'));
        }

        return redirect('/accounting/bank-import')->with('error', t('import.expired'));
    }
}
