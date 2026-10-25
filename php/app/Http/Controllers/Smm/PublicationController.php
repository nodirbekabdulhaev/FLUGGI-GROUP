<?php

namespace App\Http\Controllers\Smm;

use App\Http\Controllers\Controller;
use App\Models\SmmPublication;
use App\Services\Smm\SmmMedia;
use App\Services\Smm\SmmPublicationService;
use App\Support\Format;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Автопубликация идеи в Instagram: загрузка файла частями (JSON), подпись и расписание (форма), повтор, отмена.
 * Права — как на редактирование идеи (SmmPublicationService::editableIdea: чужая — 404, без права — 403).
 */
class PublicationController extends Controller
{
    public function start(Request $request, string $id): JsonResponse
    {
        $idea = SmmPublicationService::editableIdea($id);
        $data = $request->validate([
            'purpose' => ['required', Rule::in(['media', 'cover'])],
            'name' => ['required', 'string', 'max:255'],
            'size' => ['required', 'integer', 'min:1'],
        ]);
        $publication = SmmPublicationService::for($idea);
        if ($publication && ! in_array($publication->status, SmmPublicationService::EDITABLE, true)) {
            return response()->json(['message' => t('smm.ig.errors.locked')], 422);
        }

        return response()->json(SmmMedia::start($idea->id, access()->id(), $data['purpose'], $data['name'], (int) $data['size']), 201);
    }

    /** Часть файла — тело запроса (application/octet-stream), номер — ?index=. */
    public function chunk(Request $request, string $id, string $upload): JsonResponse
    {
        $idea = SmmPublicationService::editableIdea($id);
        $data = $request->validate(['index' => ['required', 'integer', 'min:0', 'max:100000']]);

        return response()->json(SmmMedia::chunk($upload, $idea->id, access()->id(), (int) $data['index'], $request->getContent()));
    }

    public function finish(string $id, string $upload): JsonResponse
    {
        $idea = SmmPublicationService::editableIdea($id);
        $file = SmmMedia::finish($upload, $idea->id, access()->id());
        try {
            $p = SmmPublicationService::attach($idea, $file);
        } catch (\Throwable $e) {
            SmmMedia::delete($file['path']);
            throw $e;
        }

        return response()->json([
            'ok' => true, 'kind' => $p->kind, 'name' => $file['name'], 'size' => SmmMedia::human($file['size']), 'info' => $file['info'],
            'message' => t($file['purpose'] === 'cover' ? 'smm.ig.coverUploaded' : 'smm.ig.uploaded'),
        ]);
    }

    public function save(Request $request, string $id): RedirectResponse
    {
        $idea = SmmPublicationService::editableIdea($id);
        $data = $request->validate([
            'caption' => ['nullable', 'string', 'max:'.(SmmPublicationService::CAPTION_MAX + 200)],
            'share_to_feed' => ['sometimes', 'boolean'],
            'thumb_offset' => ['nullable', 'numeric', 'min:0', 'max:900'],
            'scheduled_at' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'intent' => ['required', Rule::in(['draft', 'schedule'])],
        ], [], ['caption' => t('smm.ig.caption'), 'scheduled_at' => t('smm.ig.scheduledAt'), 'thumb_offset' => t('smm.ig.thumbOffset')]);
        SmmPublicationService::save($idea, [
            'caption' => $data['caption'] ?? null,
            'share_to_feed' => $request->boolean('share_to_feed'),
            'thumb_offset_ms' => isset($data['thumb_offset']) && $data['thumb_offset'] !== '' ? (int) round((float) $data['thumb_offset'] * 1000) : null,
            'scheduled_at' => Format::fromLocalInput($data['scheduled_at'] ?? null),
        ], $data['intent'] === 'schedule');

        return redirect('/smm/'.$idea->id.'#instagram')->with('ok', t($data['intent'] === 'schedule' ? 'smm.ig.scheduled' : 'smm.ig.saved'));
    }

    public function retry(string $id): RedirectResponse
    {
        SmmPublicationService::retry(SmmPublicationService::editableIdea($id));

        return redirect('/smm/'.$id.'#instagram')->with('ok', t('smm.ig.retried'));
    }

    public function cancel(string $id): RedirectResponse
    {
        SmmPublicationService::cancel(SmmPublicationService::editableIdea($id));

        return redirect('/smm/'.$id.'#instagram')->with('ok', t('smm.ig.cancelled'));
    }

    /** Файл для Meta по временной подписанной ссылке (без входа). Подпись проверяет middleware signed. */
    public function media(string $id, string $kind): BinaryFileResponse
    {
        $p = SmmPublication::find($id);
        $path = $p ? SmmMedia::path($kind === 'cover' ? $p->cover_path : $p->media_path) : null;
        abort_unless($p && in_array($p->status, ['SCHEDULED', 'PUBLISHING'], true) && $path && is_file($path), 404);

        return response()->file($path, [
            'Content-Type' => $kind === 'cover' ? 'image/jpeg' : (string) $p->media_mime,
            'Cache-Control' => 'private, max-age=600',
        ]);
    }
}
