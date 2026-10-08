<?php

namespace App\Support;

use App\Models\Organization;
use Closure;

/**
 * Holds the "current organization" (and optionally branch) for the request.
 * Every tenant-owned model is scoped through this (see BelongsToOrganization).
 */
class Tenant
{
    protected static ?int $organizationId = null;
    protected static ?int $branchId = null;
    protected static ?Organization $organization = null;

    public static function set(?int $organizationId, ?int $branchId = null): void
    {
        if ($organizationId !== static::$organizationId) {
            static::$organization = null;
        }
        static::$organizationId = $organizationId;
        static::$branchId = $branchId;
    }

    public static function id(): ?int
    {
        return static::$organizationId;
    }

    public static function branchId(): ?int
    {
        return static::$branchId;
    }

    public static function organization(): ?Organization
    {
        if (static::$organizationId && ! static::$organization) {
            static::$organization = Organization::withoutGlobalScopes()->find(static::$organizationId);
        }

        return static::$organization;
    }

    public static function clear(): void
    {
        static::$organizationId = static::$branchId = null;
        static::$organization = null;
    }

    /** Run a callback inside an organization context (console jobs, webhooks). */
    public static function run(Organization $org, Closure $fn, ?int $branchId = null): mixed
    {
        $prev = [static::$organizationId, static::$branchId];
        static::set($org->id, $branchId);
        try {
            return $fn($org);
        } finally {
            static::set($prev[0], $prev[1]);
        }
    }
}
