<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Notification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class Housekeeping extends Command
{
    protected $signature = 'housekeeping:run {--audit-days=730} {--notification-days=120}';
    protected $description = 'Очистка старых уведомлений и (по желанию) журнала действий, чтобы база не росла бесконечно';

    public function handle(): int
    {
        $n = Notification::withoutGlobalScopes()->where(fn ($q) => $q->whereNotNull('read_at')->orWhere('status', 'sent'))
            ->where('created_at', '<', now()->subDays((int) $this->option('notification-days')))->delete();
        $a = AuditLog::where('created_at', '<', now()->subDays((int) $this->option('audit-days')))->delete();
        DB::table('sessions')->where('last_activity', '<', now()->subDays(30)->timestamp)->delete();
        DB::table('password_reset_tokens')->where('created_at', '<', now()->subDay())->delete();
        $this->info("notifications: $n, audit: $a");

        return self::SUCCESS;
    }
}
