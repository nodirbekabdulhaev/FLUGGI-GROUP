<?php

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Services\Integrations\InboxService;
use App\Support\Format;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** «Входящие»: Директ и комментарии Instagram/Facebook в одном окне. */
class InboxController extends Controller
{
    public function index(Request $request): View|JsonResponse
    {
        $a = access();
        $channel = in_array($request->query('channel'), InboxService::CHANNELS, true) ? $request->query('channel') : null;
        $list = InboxService::list($a, $channel, $request->query('unread') === 'true');
        $threadId = $request->query('thread');
        $active = $threadId && $list['items']->firstWhere('id', $threadId) ? $list['items']->firstWhere('id', $threadId) : null;
        if (! $active && $threadId && preg_match('/^[0-9a-f-]{36}$/i', $threadId)) {
            $active = InboxService::scope($a)->with(['lead:id,number,title', 'owner:id,full_name'])->find($threadId);
        }

        return view('integrations.inbox', ['list' => $list, 'channel' => $channel, 'active' => $active]);
    }

    private static function messageDto($m): array
    {
        return ['id' => $m->id, 'direction' => $m->direction, 'text' => $m->text, 'externalId' => $m->external_id, 'mediaId' => $m->media_id,
            'author' => $m->author?->full_name, 'createdAt' => dt($m->created_at)];
    }

    public function messages(string $id): JsonResponse
    {
        return response()->json(InboxService::messages(access(), $id)->map(fn ($m) => self::messageDto($m))->all());
    }

    public function reply(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'text' => ['required', 'string', 'max:1000'],
            'commentId' => ['nullable', 'string', 'max:100'],
            'mode' => ['sometimes', Rule::in(['public', 'private'])],
        ]);
        $rows = InboxService::reply(access(), $id, trim($data['text']), $data['commentId'] ?? null, $data['mode'] ?? 'public');

        return response()->json($rows->map(fn ($m) => self::messageDto($m))->all());
    }

    public function lead(Request $request, string $id): JsonResponse|\Illuminate\Http\RedirectResponse
    {
        $t = InboxService::createLead(access(), $id);
        $lead = $t->lead ? ['id' => $t->lead->id, 'number' => Format::code('L', $t->lead->number)] : null;

        return $request->expectsJson() ? response()->json(['id' => $t->id, 'lead' => $lead]) : back()->with('ok', t('inbox.leadCreated'));
    }
}
