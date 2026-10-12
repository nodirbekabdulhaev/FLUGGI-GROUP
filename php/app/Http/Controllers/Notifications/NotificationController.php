<?php

namespace App\Http\Controllers\Notifications;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Services\Notifications\NotificationCatalog;
use App\Services\Notifications\NotificationService;
use App\Services\Telegram\TelegramService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Уведомления — личные: каждый видит только свои. */
class NotificationController extends Controller
{
    public function index(): View
    {
        $a = access();

        return view('notifications.index', [
            'items' => NotificationService::list($a->id()),
            'telegram' => TelegramService::status($a->user),
        ]);
    }

    public function unread(): JsonResponse
    {
        return response()->json(['count' => NotificationService::unread(access()->id())]);
    }

    public function readAll(Request $request): JsonResponse|RedirectResponse
    {
        NotificationService::markRead(access()->id());

        return $request->expectsJson() ? response()->json(null, 204) : back();
    }

    public function read(Request $request, string $id): JsonResponse|RedirectResponse
    {
        NotificationService::markRead(access()->id(), $id);

        return $request->expectsJson() ? response()->json(null, 204) : back();
    }

    /** Открыть: отметить прочитанным и перейти по ссылке (только внутренние адреса). */
    public function open(string $id): RedirectResponse
    {
        $n = Notification::where('user_id', access()->id())->findOrFail($id);
        NotificationService::markRead(access()->id(), $id);
        $link = (string) $n->link;

        return redirect(str_starts_with($link, '/') && ! str_starts_with($link, '//') ? $link : '/notifications');
    }

    /** Личные настройки каналов (ТЗ §14: «индивидуальные»). */
    public function saveSettings(Request $request): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'settings' => ['required', 'array', 'max:200'],
            'settings.*.eventType' => ['required', 'string', 'max:60'],
            'settings.*.channel' => ['required', Rule::in(NotificationCatalog::CHANNELS)],
            'settings.*.enabled' => ['required', 'boolean'],
        ]);
        $a = access();
        NotificationService::saveSettings($a->id(), array_map(fn ($s) => ['eventType' => $s['eventType'], 'channel' => $s['channel'], 'enabled' => filter_var($s['enabled'], FILTER_VALIDATE_BOOLEAN)], $data['settings']));
        $settings = NotificationService::settings($a->id(), $a->roleCode);

        return $request->expectsJson() ? response()->json($settings) : back()->with('ok', t('telegram.settingsSaved'));
    }
}
