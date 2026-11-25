<?php

namespace App\Http\Controllers\Telegram;

use App\Http\Controllers\Controller;
use App\Services\Telegram\TelegramService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Webhook Telegram: секретный заголовок X-Telegram-Bot-Api-Secret-Token сверяется с .env. */
class TelegramWebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $expected = (string) config('fluggi.telegram.webhook_secret');
        $given = (string) $request->header('X-Telegram-Bot-Api-Secret-Token', '');
        if ($expected === '' || $given === '' || ! hash_equals($expected, $given)) {
            return response()->json(['message' => t('telegram.errors.forbidden')], 403);
        }
        TelegramService::handleUpdate((array) $request->json()->all());

        return response()->json(['ok' => true]);
    }
}
