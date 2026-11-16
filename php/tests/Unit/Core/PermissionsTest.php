<?php

namespace Tests\Unit\Core;

use App\Support\MySql;
use App\Support\Permissions;
use PHPUnit\Framework\TestCase;

class PermissionsTest extends TestCase
{
    public function test_scope_ranking(): void
    {
        $map = ['lead.read' => 'TEAM'];
        $this->assertTrue(Permissions::has($map, 'lead.read'));
        $this->assertTrue(Permissions::has($map, 'lead.read', 'TEAM'));
        $this->assertFalse(Permissions::has($map, 'lead.read', 'ALL'));
        $this->assertFalse(Permissions::has($map, 'deal.read'));
    }

    public function test_finance_and_attendance_defaults(): void
    {
        $d = Permissions::defaults();
        // Финансы проектов и компании — только CEO
        foreach (['ROP', 'MANAGER', 'EXECUTOR', 'HR_ADMIN', 'PROJECT_MANAGER'] as $role) {
            $this->assertArrayNotHasKey('finance.read', $d[$role], $role);
            $this->assertArrayNotHasKey('finance.company.read', $d[$role], $role);
        }
        // Посещаемость: отмечаются менеджеры и РОП, не исполнители и не PM
        $this->assertArrayHasKey('attendance.read', $d['MANAGER']);
        $this->assertArrayHasKey('attendance.read', $d['ROP']);
        $this->assertArrayNotHasKey('attendance.read', $d['EXECUTOR']);
        $this->assertArrayNotHasKey('attendance.read', $d['PROJECT_MANAGER']);
        $this->assertCount(count(Permissions::ALL), $d['CEO']);
    }

    public function test_skip_locked_support(): void
    {
        $this->assertTrue(MySql::supportsSkipLocked('8.0.46'));
        $this->assertTrue(MySql::supportsSkipLocked('8.4.2-log'));
        $this->assertFalse(MySql::supportsSkipLocked('5.7.44-log'));
        $this->assertTrue(MySql::supportsSkipLocked('10.6.12-MariaDB'));
        $this->assertFalse(MySql::supportsSkipLocked('10.3.39-MariaDB-0+deb10u1'));
        $this->assertTrue(MySql::supportsSkipLocked('11.4.2-MariaDB'));
    }
}
