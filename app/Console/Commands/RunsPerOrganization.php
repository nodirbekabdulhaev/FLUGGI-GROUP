<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Support\Tenant;
use Illuminate\Support\Facades\Log;

/** Runs a callback for every active organization inside its tenant context and logs to the cron channel. */
trait RunsPerOrganization
{
    protected function eachOrganization(callable $fn): int
    {
        $name = $this->getName();
        $started = microtime(true);
        $ok = 0;

        foreach (Organization::where('is_active', true)->get() as $org) {
            try {
                Tenant::run($org, fn () => $fn($org));
                $ok++;
            } catch (\Throwable $e) {
                Log::channel('cron')->error("$name failed for org {$org->id}", ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
                $this->error("[{$org->name}] ".$e->getMessage());
            }
        }

        Log::channel('cron')->info("$name finished", ['orgs' => $ok, 'ms' => round((microtime(true) - $started) * 1000)]);

        return self::SUCCESS;
    }
}
