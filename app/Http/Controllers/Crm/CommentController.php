<?php

namespace App\Http\Controllers\Crm;

use App\Services\Crm\TimelineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Комментарии карточек лида, сделки и клиента; доступ — по самой записи. */
class CommentController extends CrmController
{
    public function __construct(private readonly TimelineService $timeline) {}

    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $data = $this->check($request, [
            'lead_id' => ['nullable', 'uuid'],
            'deal_id' => ['nullable', 'uuid'],
            'client_id' => ['nullable', 'uuid'],
            'body' => ['required', 'string', 'max:4000'],
        ], ['body.required' => t('crm.errors.commentRequired')]);
        $body = $data['body'];
        unset($data['body']);
        $comment = $this->timeline->addComment($data, $body);

        return $this->done($request, t('crm.common.commentAdded'), null, ['id' => $comment->id]);
    }

    public function destroy(Request $request, string $id): RedirectResponse|JsonResponse
    {
        $this->timeline->deleteComment($id);

        return $this->done($request, t('crm.common.deleted'));
    }
}
