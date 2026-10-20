<?php

namespace App\Http\Controllers\Automation;

use App\Http\Controllers\Controller;
use App\Services\Automation\Cron;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Фоновые задачи по внешнему cron (POST hooks/cron) — запасной вариант, если хостинг не даёт
 * запускать `php artisan schedule:run`. Без верного секрета адреса «нет» (404).
 */
class CronController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $expected = (string) config('fluggi.cron_secret');
        $given = (string) $request->header('x-cron-secret', '');
        abort_if($expected === '' || $given === '' || ! hash_equals($expected, $given), 404);
        @set_time_limit(60);

        return response()->json(Cron::all(40));
    }
}
