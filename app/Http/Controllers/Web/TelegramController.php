<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Guardian;
use App\Models\Notification;
use App\Models\Setting;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\TelegramAccount;
use App\Models\User;
use App\Services\TelegramService;
use Illuminate\Http\Request;

class TelegramController extends Controller
{
    public function __construct(protected TelegramService $tg) {}

    private const TYPES = [
        'student' => [Student::class, 'Ученик'], 'guardian' => [Guardian::class, 'Родитель'],
        'user' => [User::class, 'Сотрудник (пользователь)'], 'teacher' => [Teacher::class, 'Преподаватель'], 'employee' => [Employee::class, 'Сотрудник'],
    ];

    public function index(Request $request)
    {
        $q = Notification::where('channel', 'telegram')->latest('id');
        if ($request->filled('status')) {
            $q->where('status', $request->query('status'));
        }
        if ($request->filled('type')) {
            $q->where('type', $request->query('type'));
        }

        return view('telegram.index', [
            'items' => $q->paginate(30)->withQueryString(),
            'stats' => Notification::where('channel', 'telegram')->selectRaw('status, COUNT(*) c')->groupBy('status')->pluck('c', 'status'),
            'accounts' => TelegramAccount::with('linkable')->whereNotNull('chat_id')->latest('linked_at')->limit(50)->get(),
            'enabled' => $this->tg->enabled(),
            'botUsername' => Setting::get('telegram.bot_username') ?: config('services.telegram.bot_username'),
            'types' => self::TYPES,
            'link' => session('tg_link'),
        ]);
    }

    public function link(Request $request)
    {
        $d = $request->validate(['type' => 'required|in:'.implode(',', array_keys(self::TYPES)), 'id' => 'required|integer']);
        $class = self::TYPES[$d['type']][0];
        $entity = $class::findOrFail($d['id']);       // tenant-scoped lookup
        $acc = TelegramAccount::issueFor($entity);
        $bot = Setting::get('telegram.bot_username') ?: config('services.telegram.bot_username');
        $url = $bot ? "https://t.me/{$bot}?start={$acc->token}" : null;
        $name = $entity->full_name ?? $entity->name;

        return back()->with('tg_link', ['url' => $url, 'token' => $acc->token, 'name' => $name]);
    }

    public function process()
    {
        [$sent, $failed] = $this->tg->processQueue(100);

        return back()->with('ok', __('Отправлено').": $sent, ".__('ошибок').": $failed");
    }

    public function test(Request $request)
    {
        $d = $request->validate(['chat_id' => 'required|integer']);
        [$ok, $err] = $this->tg->send((int) $d['chat_id'], '✅ FLUGGI EDU ERP: тестовое сообщение');

        return back()->with($ok ? 'ok' : 'warn', $ok ? __('Тестовое сообщение отправлено') : __('Ошибка Telegram').': '.$err);
    }

    public function retry(Notification $notification)
    {
        abort_unless($notification->channel === 'telegram', 404);
        $this->tg->retry($notification);

        return back()->with('ok', __('Поставлено в очередь повторно'));
    }

    /** Bot webhook (set with `php artisan telegram:webhook`). Secret in the URL authenticates Telegram. */
    public function webhook(Request $request, string $secret)
    {
        $expected = (string) config('services.telegram.webhook_secret');
        abort_unless($expected !== '' && hash_equals($expected, $secret), 403);
        $this->tg->handleUpdate($request->json()->all());

        return response()->json(['ok' => true]);
    }
}
