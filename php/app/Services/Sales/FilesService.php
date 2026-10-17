<?php

namespace App\Services\Sales;

use App\Exceptions\BusinessRule;
use App\Models\Contract;
use App\Models\Payment;
use App\Models\Proposal;
use App\Models\StoredFile;
use App\Services\Crm\CrmAccess;
use App\Services\Projects\ProjectAccess;
use App\Support\Audit;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Файлы сделки, договоров, проекта и задач (ТЗ §22, §46, §52). Содержимое — в
 * storage/app/private/files/… (вне public), имя на диске генерирует сервер (нет path traversal).
 * Тип проверяется по сигнатуре содержимого, скачивание — только через контроллер с проверкой прав.
 */
final class FilesService
{
    public const CATEGORIES = ['PROPOSAL', 'CONTRACT', 'PAYMENT', 'PHOTO', 'VIDEO', 'DESIGN', 'DOCUMENT', 'OTHER'];

    public const DISK = 'local';

    /** Тип по содержимому (magic bytes) или null. */
    public static function detectMime(string $head): ?string
    {
        $hex = bin2hex(substr($head, 0, 12));

        return match (true) {
            str_starts_with($head, '%PDF-') => 'application/pdf',
            str_starts_with($hex, '89504e470d0a1a0a') => 'image/png',
            str_starts_with($hex, 'ffd8ff') => 'image/jpeg',
            substr($head, 0, 4) === 'RIFF' && substr($head, 8, 4) === 'WEBP' => 'image/webp',
            str_starts_with($hex, '504b0304') => 'application/zip',
            default => null,
        };
    }

    /** docx/xlsx — это zip-архивы: для них допускается zip-сигнатура. */
    public static function signatureMatches(string $declared, ?string $detected): bool
    {
        if (! $detected) {
            return false;
        }

        return $declared === $detected
            || ($detected === 'application/zip' && str_starts_with($declared, 'application/vnd.openxmlformats-officedocument'));
    }

    /** Безопасное имя для заголовка Content-Disposition. */
    public static function safeName(string $name): string
    {
        return mb_substr(preg_replace('/[\r\n"\\\\\/]/u', '_', $name), 0, 200);
    }

    /** Файл проекта/задачи: загружать и смотреть может каждый, кто видит задачу или проект. */
    private static function projectTarget(?string $projectId, ?string $taskId): string
    {
        return $taskId ? ProjectAccess::task($taskId)->project_id : ProjectAccess::project($projectId)->id;
    }

    /** Сделка, к которой относится файл: права на файл = права на сделку. */
    private static function dealOf(array $t): string
    {
        return match (true) {
            ! empty($t['deal_id']) => $t['deal_id'],
            ! empty($t['contract_id']) => Contract::findOrFail($t['contract_id'])->deal_id,
            ! empty($t['proposal_id']) => Proposal::findOrFail($t['proposal_id'])->deal_id,
            ! empty($t['payment_id']) => Payment::findOrFail($t['payment_id'])->deal_id,
            default => throw new BusinessRule(t('sales.files.errors.noTarget')),
        };
    }

    /** @param  array{deal_id?:?string, contract_id?:?string, proposal_id?:?string, payment_id?:?string, project_id?:?string, task_id?:?string}  $target */
    public static function upload(UploadedFile $file, array $target, string $category): StoredFile
    {
        $types = config('fluggi.files.types');
        if ($file->getSize() > config('fluggi.files.max_bytes')) {
            throw ValidationException::withMessages(['file' => t('sales.files.errors.tooBig')]);
        }
        $mime = (string) $file->getClientMimeType();
        $ext = Str::lower(pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION));
        if (! isset($types[$mime]) || ! in_array($ext, $types[$mime], true)) {
            throw ValidationException::withMessages(['file' => t('sales.files.errors.type')]);
        }
        $fh = fopen($file->getRealPath(), 'rb');
        $head = (string) fread($fh, 16);
        fclose($fh);
        if (! self::signatureMatches($mime, self::detectMime($head))) {
            throw ValidationException::withMessages(['file' => t('sales.files.errors.signature')]);
        }

        $dealId = null;
        $projectId = null;
        if (! empty($target['project_id']) || ! empty($target['task_id'])) {
            $projectId = self::projectTarget($target['project_id'] ?? null, $target['task_id'] ?? null);
        } else {
            try {
                $dealId = self::dealOf($target);
            } catch (ModelNotFoundException) {
                abort(404);
            }
            CrmAccess::deal($dealId, 'deal.read');
            // Загружать может тот, кто может менять сделку или договор.
            if (! access()->can('deal.update') && ! access()->can('contract.update')) {
                abort(404);
            }
            // Привязка к договору/КП/оплате — только той же сделки
            foreach (['contract_id' => Contract::class, 'proposal_id' => Proposal::class, 'payment_id' => Payment::class] as $k => $model) {
                if (! empty($target[$k]) && $model::whereKey($target[$k])->value('deal_id') !== $dealId) {
                    abort(404);
                }
            }
            self::authorizeDocument($target);
        }

        $now = now();
        $key = 'files/'.($projectId ? 'projects' : 'deals').'/'.$now->format('Y').'/'.$now->format('m').'/'.Str::uuid().'.'.$ext;
        $contents = file_get_contents($file->getRealPath());
        Storage::disk(self::DISK)->put($key, $contents);
        $actor = access()->id();

        try {
            return DB::transaction(function () use ($file, $key, $mime, $contents, $category, $actor, $dealId, $projectId, $target) {
                $saved = StoredFile::create([
                    'storage_key' => $key,
                    'original_name' => self::safeName($file->getClientOriginalName()),
                    'mime_type' => $mime,
                    'size_bytes' => strlen($contents),
                    'checksum' => hash('sha256', $contents),
                    'category' => $category,
                    'uploaded_by_id' => $actor,
                    'deal_id' => $dealId,
                    'project_id' => $projectId,
                    'task_id' => $target['task_id'] ?? null,
                    'contract_id' => $target['contract_id'] ?? null,
                    'proposal_id' => $target['proposal_id'] ?? null,
                    'payment_id' => $target['payment_id'] ?? null,
                ]);
                Audit::log('file.upload', 'file', $saved->id, [
                    'name' => ['old' => null, 'new' => $saved->original_name],
                    'size' => ['old' => null, 'new' => $saved->size_bytes],
                ], $actor);

                return $saved;
            });
        } catch (\Throwable $e) {
            Storage::disk(self::DISK)->delete($key);
            throw $e;
        }
    }

    /** Файл с проверкой прав на запись, к которой он относится; чужой — 404. */
    public static function open(string $id): StoredFile
    {
        $f = StoredFile::findOrFail($id);
        if ($f->deal_id) {
            CrmAccess::deal($f->deal_id, 'deal.read');
        } elseif ($f->project_id) {
            self::projectTarget($f->task_id ? null : $f->project_id, $f->task_id);
        } else {
            abort(404);
        }

        return $f;
    }

    /** @param  array{deal_id?:?string, project_id?:?string, task_id?:?string}  $q */
    public static function list(array $q): Collection
    {
        $query = StoredFile::with('uploadedBy:id,full_name');
        if (! empty($q['task_id'])) {
            self::projectTarget(null, $q['task_id']);
            $query->where('task_id', $q['task_id']);
        } elseif (! empty($q['project_id'])) {
            self::projectTarget($q['project_id'], null);
            $query->where('project_id', $q['project_id']);
        } elseif (! empty($q['deal_id'])) {
            CrmAccess::deal($q['deal_id'], 'deal.read');
            $query->where('deal_id', $q['deal_id']);
        } else {
            throw new BusinessRule(t('sales.files.errors.noList'));
        }

        return $query->orderByDesc('created_at')->get();
    }

    /** Файлы договора, КП и оплаты меняет тот, кто ими управляет (РОП и выше), а не менеджер сделки. */
    private static function authorizeDocument(array $target): void
    {
        foreach (['contract_id' => 'contract.update', 'proposal_id' => 'proposal.update', 'payment_id' => 'payment.create'] as $k => $perm) {
            if (! empty($target[$k])) {
                access()->authorize($perm);
            }
        }
    }

    /** Удалить: файл сделки — с правом менять сделку; файл проекта — автор или руководитель проекта. */
    public static function remove(string $id): void
    {
        $f = StoredFile::findOrFail($id);
        if ($f->deal_id) {
            CrmAccess::deal($f->deal_id, 'deal.update');
            self::authorizeDocument($f->only(['contract_id', 'proposal_id', 'payment_id']));
        } elseif ($f->project_id) {
            self::projectTarget($f->task_id ? null : $f->project_id, $f->task_id);
            if ($f->uploaded_by_id !== access()->id() && ! ProjectAccess::can($f->project_id, 'project.update')) {
                abort(404);
            }
        } else {
            abort(404);
        }
        DB::transaction(function () use ($f) {
            $f->delete();
            Audit::log('file.delete', 'file', $f->id, ['name' => ['old' => $f->original_name, 'new' => null]]);
        });
    }
}
