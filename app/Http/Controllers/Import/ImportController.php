<?php

namespace App\Http\Controllers\Import;

use App\Http\Controllers\Controller;
use App\Services\Import\ImportFile;
use App\Services\Import\ImportService;
use App\Services\Import\ImportStore;
use App\Services\Import\RowError;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Импорт лидов, сделок, клиентов, проектов и посещаемости из Excel/CSV: шаблон → предпросмотр → импорт → итог.
 * Маршрут передаёт параметры по порядку: {token} из адреса, затем entity (значение по умолчанию маршрута).
 */
class ImportController extends Controller
{
    /** Сколько строк показывать в таблицах предпросмотра и итога. */
    private const SHOW_ROWS = 500;

    public function show(string $entity): View
    {
        $importer = ImportService::importer($entity);

        return view('import.show', [
            'entity' => $entity,
            'importer' => $importer,
            'back' => $importer::backUrl(),
        ]);
    }

    public function template(string $entity): Response
    {
        $f = ImportService::template($entity);

        return response($f['body'], 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$f['filename'].'"',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function upload(Request $request, string $entity): RedirectResponse
    {
        ImportService::importer($entity);
        $request->validate([
            'file' => ['required', 'file', 'max:'.(ImportFile::MAX_BYTES / 1024), 'extensions:'.implode(',', ImportFile::EXTENSIONS)],
            'options' => ['nullable', 'array'],
            'options.*' => ['nullable', Rule::in(['1', 'on', 'true'])],
        ], [
            'file.required' => t('import.errors.fileRequired'),
            'file.file' => t('import.errors.fileRequired'),
            'file.uploaded' => t('import.errors.fileSize', ['mb' => ImportFile::MAX_BYTES / 1048576]),
            'file.max' => t('import.errors.fileSize', ['mb' => ImportFile::MAX_BYTES / 1048576]),
            'file.extensions' => t('import.errors.fileType'),
        ]);
        $options = [];
        foreach (ImportService::OPTIONS as $key) {
            $options[$key] = $request->boolean('options.'.$key);
        }
        try {
            $token = ImportService::upload($entity, $request->file('file'), $options);
        } catch (RowError $e) {
            return back()->withErrors(['file' => $e->getMessage()])->with('error', $e->getMessage());
        }

        return redirect()->route("import.$entity.preview", ['token' => $token]);
    }

    public function preview(Request $request, string $token, string $entity): View|RedirectResponse
    {
        $importer = ImportService::importer($entity);
        $data = ImportStore::valid($token) ? ImportStore::get($token, $entity, access()->id()) : null;
        if (! $data) {
            return $this->gone($entity, $token);
        }
        $filter = $request->validate(['status' => ['nullable', Rule::in(['create', 'update', 'duplicate', 'error'])]])['status'] ?? null;
        $rows = collect($data['preview'])->when($filter, fn ($c) => $c->where('status', $filter))->values();

        return view('import.preview', [
            'entity' => $entity,
            'importer' => $importer,
            'token' => $token,
            'data' => $data,
            'totals' => ImportService::totals($data['preview']),
            'rows' => $rows->take(self::SHOW_ROWS),
            'hidden' => max(0, $rows->count() - self::SHOW_ROWS),
            'filter' => $filter,
            'back' => $importer::backUrl(),
        ]);
    }

    public function confirm(string $token, string $entity): RedirectResponse
    {
        ImportService::importer($entity);
        if (! ImportStore::valid($token)) {
            abort(404);
        }
        $result = ImportService::confirm($entity, $token);
        if (! $result) {
            return $this->gone($entity, $token);
        }
        $t = $result['totals'];

        return redirect()->route("import.$entity.result", ['token' => $token])
            ->with('ok', t('import.done', ['created' => $t['created'] + $t['updated'], 'skipped' => $t['duplicate'], 'errors' => $t['error']]));
    }

    public function cancel(string $token, string $entity): RedirectResponse
    {
        ImportService::importer($entity);
        if (ImportStore::valid($token) && ImportStore::get($token, $entity, access()->id())) {
            ImportStore::forget($token);
        }

        return redirect()->route("import.$entity")->with('ok', t('import.cancelled'));
    }

    public function result(Request $request, string $token, string $entity): View|RedirectResponse
    {
        $importer = ImportService::importer($entity);
        $result = ImportStore::valid($token) ? ImportStore::result($token, $entity, access()->id()) : null;
        if (! $result) {
            if (ImportStore::valid($token) && ImportStore::running($token)) {
                return view('import.running', ['entity' => $entity, 'importer' => $importer, 'token' => $token, 'back' => $importer::backUrl()]);
            }

            return redirect()->route("import.$entity")->with('error', t('import.expired'));
        }
        $filter = $request->validate(['status' => ['nullable', Rule::in(['created', 'updated', 'duplicate', 'error'])]])['status'] ?? null;
        $rows = collect($result['rows'])->when($filter, fn ($c) => $c->where('status', $filter))->values();

        return view('import.result', [
            'entity' => $entity,
            'importer' => $importer,
            'token' => $token,
            'result' => $result,
            'totals' => $result['totals'],
            'rows' => $rows->take(self::SHOW_ROWS),
            'hidden' => max(0, $rows->count() - self::SHOW_ROWS),
            'filter' => $filter,
            'back' => $importer::backUrl(),
        ]);
    }

    /** Предпросмотр уже импортирован (повторный клик), импорт идёт или срок истёк. */
    private function gone(string $entity, string $token): RedirectResponse
    {
        $uid = access()->id();
        if (ImportStore::valid($token) && (ImportStore::result($token, $entity, $uid) || ImportStore::running($token))) {
            return redirect()->route("import.$entity.result", ['token' => $token])->with('ok', t('import.alreadyImported'));
        }

        return redirect()->route("import.$entity")->with('error', t('import.expired'));
    }
}
