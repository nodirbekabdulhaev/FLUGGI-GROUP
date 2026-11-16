<?php

namespace App\Http\Controllers\Chat;

use App\Http\Controllers\Controller;
use App\Services\Chat\ChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Чат сотрудников (личная переписка): JSON для виджета, обновление опросом. */
class ChatController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(ChatService::list(access()->user));
    }

    public function unread(): JsonResponse
    {
        return response()->json(['count' => ChatService::unreadTotal(access()->id())]);
    }

    public function contacts(): JsonResponse
    {
        return response()->json(ChatService::contacts(access()->id()));
    }

    public function direct(Request $request): JsonResponse
    {
        $data = $request->validate(['userId' => ['required', 'uuid']]);

        return response()->json(['id' => ChatService::direct(access()->id(), $data['userId'])]);
    }

    public function messages(Request $request, string $id): JsonResponse
    {
        $q = $request->validate(['before' => ['nullable', 'date'], 'limit' => ['nullable', 'integer', 'min:1', 'max:100']]);

        return response()->json(ChatService::messages(access()->id(), $id, $q['before'] ?? null, (int) ($q['limit'] ?? 50)));
    }

    public function send(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:4000']]);
        $body = trim($data['body']);
        abort_if($body === '', 422);

        return response()->json(ChatService::send(access()->user, $id, $body), 201);
    }

    public function read(string $id): JsonResponse
    {
        ChatService::read(access()->id(), $id);

        return response()->json(null, 204);
    }
}
