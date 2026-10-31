<?php

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Services\Integrations\MetaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Webhook Meta (Instagram/Facebook): GET — проверка токена, POST — события с подписью X-Hub-Signature-256. */
class MetaWebhookController extends Controller
{
    public function verify(Request $request): Response
    {
        // PHP превращает «hub.mode» в «hub_mode»
        $challenge = MetaService::verify($request->query('hub_mode'), $request->query('hub_verify_token'), $request->query('hub_challenge'));
        abort_if($challenge === null, 403);

        return response($challenge, 200, ['Content-Type' => 'text/plain']);
    }

    public function handle(Request $request): JsonResponse
    {
        if (! MetaService::signatureValid($request->getContent(), $request->header('X-Hub-Signature-256'))) {
            return response()->json(['message' => t('integrations.errors.signature')], 403);
        }
        MetaService::handle((array) json_decode($request->getContent(), true));

        return response()->json(['ok' => true]);
    }
}
