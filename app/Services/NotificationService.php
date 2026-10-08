<?php

namespace App\Services;

use App\Models\Guardian;
use App\Models\Notification;
use App\Models\Setting;
use App\Models\Student;
use App\Models\TelegramAccount;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Database\Eloquent\Model;

class NotificationService
{
    public const DEFAULT_TEMPLATES = [
        'new_lead' => "🔔 Новая заявка\n\n👤 {name}\n📞 {phone}\n📚 {course}\n📍 {branch}\n📱 {source}",
        'lesson_reminder' => "📚 Сегодня занятие\n\n{group}\n{time}\n{room}",
        'payment_due' => "💳 Напоминание об оплате\n\nВаш долг:\n{amount} сум",
        'payment_received' => "✅ Оплата получена\n\n{name}\nСумма: {amount} сум",
        'absence' => "⚠️ Ученик отсутствовал\n\n{name}\n{group}\n{date}",
        'daily_report' => "📊 ОТЧЁТ ЗА {date}\n\n👥 Новых лидов: {leads}\n🎓 Новых учеников: {students}\n\n💰 Оплачено:\n{paid} сум\n\n⚠️ Новые долги:\n{debts} сум\n\n📚 Проведено занятий:\n{lessons}\n\n👨‍🎓 Посещаемость:\n{attendance}%\n\n❌ Отменено:\n{cancelled} занятия",
    ];

    public function template(string $type): string
    {
        return Setting::get('tpl.'.$type, self::DEFAULT_TEMPLATES[$type] ?? '{text}');
    }

    public function render(string $type, array $vars): string
    {
        $pairs = [];
        foreach ($vars as $k => $v) {
            $pairs['{'.$k.'}'] = (string) $v;
        }

        return trim(strtr($this->template($type), $pairs));
    }

    /** Telegram message to every linked chat of a student + his parents. Returns number queued. */
    public function toStudentAudience(Student $student, string $type, string $body, ?string $dedupe = null): int
    {
        $accounts = collect();
        $accounts->push(...TelegramAccount::where('linkable_type', $student->getMorphClass())->where('linkable_id', $student->id)->get());
        $guardianIds = $student->guardians()->pluck('parents.id');
        if ($guardianIds->isNotEmpty()) {
            $accounts->push(...TelegramAccount::where('linkable_type', (new Guardian)->getMorphClass())->whereIn('linkable_id', $guardianIds)->get());
        }

        $n = 0;
        foreach ($accounts->filter->isLinked()->unique('chat_id') as $acc) {
            $n += (int) (bool) $this->queueTelegram($type, $body, (int) $acc->chat_id, $student, $dedupe ? $dedupe.':'.$acc->chat_id : null);
        }

        return $n;
    }

    public function queueTelegram(string $type, string $body, int $chatId, ?Model $recipient = null, ?string $dedupe = null, ?\DateTimeInterface $at = null): ?Notification
    {
        if ($dedupe && Notification::where('dedupe_key', $dedupe)->exists()) {
            return null;
        }

        return Notification::create([
            'type' => $type,
            'channel' => 'telegram',
            'chat_id' => $chatId,
            'recipient_type' => $recipient?->getMorphClass(),
            'recipient_id' => $recipient?->getKey(),
            'body' => $body,
            'status' => 'pending',
            'scheduled_at' => $at ?? now(),
            'dedupe_key' => $dedupe,
        ]);
    }

    /** Bell notification + Telegram (if linked) for every staff user having the permission. */
    public function toStaff(string $permission, string $type, string $title, string $body, ?string $url = null, ?int $branchId = null, ?string $dedupe = null): int
    {
        $n = 0;
        $users = User::active()->with('roles.permissions')->get()
            ->filter(fn (User $u) => $u->hasPermission($permission))
            ->filter(fn (User $u) => ! $branchId || ! $u->branch_id || $u->branch_id === $branchId);

        foreach ($users as $user) {
            $key = $dedupe ? $dedupe.':u'.$user->id : null;
            if ($key && Notification::where('dedupe_key', $key)->exists()) {
                continue;
            }
            Notification::create([
                'type' => $type, 'channel' => 'web', 'user_id' => $user->id, 'title' => $title, 'body' => $body,
                'url' => $url, 'status' => 'sent', 'sent_at' => now(), 'dedupe_key' => $key,
            ]);
            $n++;

            $acc = TelegramAccount::where('linkable_type', $user->getMorphClass())->where('linkable_id', $user->id)->first();
            if ($acc?->isLinked()) {
                $this->queueTelegram($type, $title."\n\n".$body, (int) $acc->chat_id, $user, $key ? $key.':tg' : null);
            }
        }

        return $n;
    }

    /** System warnings (Telegram errors, schedule conflicts) for administrators. */
    public function system(string $title, string $body, ?string $url = null): void
    {
        $this->toStaff('settings.manage', 'system', $title, $body, $url);
    }

    public function unreadFor(User $user, int $limit = 15)
    {
        return Notification::where('channel', 'web')->where('user_id', $user->id)->latest('id')->limit($limit)->get();
    }
}
