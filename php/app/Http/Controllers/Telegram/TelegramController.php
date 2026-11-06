<?php

namespace App\Http\Controllers\Telegram;

use App\Exceptions\BusinessRule;
use App\Http\Controllers\Controller;
use App\Services\Notifications\Notifier;
use App\Services\Telegram\TelegramService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Привязка Telegram к своему аккаунту (ТЗ §53). */
class TelegramController extends Controller
{
    public function status(): JsonResponse
    {
        return response()->json(TelegramService::status(access()->user->fresh()));
    }

    public function link(): JsonResponse
    {
        return response()->json(TelegramService::createLink(access()->user));
    }

    public function unlink(Request $request): JsonResponse|RedirectResponse
    {
        TelegramService::unlink(access()->user);

        return $request->expectsJson() ? response()->json(null, 204) : back()->with('ok', t('telegram.unlinked'));
    }

    /** Тестовое сообщение себе — проверить, что уведомления доходят (только Telegram, без in-app). */
    public function test(Request $request): JsonResponse|RedirectResponse
    {
        $user = access()->user->fresh();
        if (! TelegramService::status($user)['linked']) {
            throw new BusinessRule(t('telegram.errors.linkFirst'));
        }
        Notifier::notify([$user->id], 'test', fn () => [t('telegram.testTitle'), t('telegram.testBody', ['app' => brand()->name()])], '/profile', null, inApp: false);

        return $request->expectsJson() ? response()->json(['queued' => true]) : back()->with('ok', t('telegram.testSent'));
    }
}
