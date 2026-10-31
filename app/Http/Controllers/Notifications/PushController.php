<?php

namespace App\Http\Controllers\Notifications;

use App\Http\Controllers\Controller;
use App\Services\Push\WebPush;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Подписка браузера на Web Push — личная: каждый подписывает и отписывает только свои браузеры. */
class PushController extends Controller
{
    public function subscribe(Request $request): JsonResponse
    {
        abort_unless(WebPush::configured(), 404);
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'url', 'max:2000'],
            'keys' => ['required', 'array'],
            'keys.p256dh' => ['required', 'string', 'max:200'],
            'keys.auth' => ['required', 'string', 'max:64'],
        ]);
        $sub = WebPush::subscribe(access()->user, $data['endpoint'], $data['keys']['p256dh'], $data['keys']['auth'], $request->userAgent());

        return response()->json(['id' => $sub->id], 201);
    }

    public function unsubscribe(Request $request): JsonResponse
    {
        $data = $request->validate(['endpoint' => ['required', 'string', 'max:2000']]);
        WebPush::unsubscribe(access()->user, $data['endpoint']);

        return response()->json(null, 204);
    }
}
