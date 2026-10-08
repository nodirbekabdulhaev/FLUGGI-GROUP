<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\Course;
use App\Models\ExpenseCategory;
use App\Models\Group;
use App\Models\LeadSource;
use App\Models\LeadStatus;
use App\Models\PaymentMethod;
use App\Models\Room;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/** id => label option lists and tenant-aware "exists" validation rules. */
class Lookup
{
    private const SOFT = ['branches', 'rooms', 'courses', 'teachers', 'employees', 'leads', 'students', 'parents', 'groups', 'payments', 'expenses', 'users'];

    /** exists:table,id restricted to the current organization (and not soft-deleted). */
    public static function exists(string $table): Exists
    {
        $rule = Rule::exists($table, 'id')->where('organization_id', Tenant::id());
        if (in_array($table, self::SOFT, true)) {
            $rule->whereNull('deleted_at');
        }

        return $rule;
    }

    public static function branches(): array
    {
        return Branch::active()->orderBy('name')->pluck('name', 'id')->all();
    }

    public static function sources(): array
    {
        return LeadSource::where('is_active', true)->orderBy('name')->pluck('name', 'id')->all();
    }

    public static function statuses(): array
    {
        return LeadStatus::orderBy('sort')->pluck('name', 'id')->all();
    }

    public static function courses(): array
    {
        return Course::where('status', 'active')->orderBy('name')->pluck('name', 'id')->all();
    }

    public static function subjects(): array
    {
        return Subject::orderBy('name')->pluck('name', 'id')->all();
    }

    public static function methods(): array
    {
        return PaymentMethod::where('is_active', true)->orderBy('id')->pluck('name', 'id')->all();
    }

    public static function categories(): array
    {
        return ExpenseCategory::orderBy('name')->pluck('name', 'id')->all();
    }

    public static function teachers(): array
    {
        return Teacher::where('status', '!=', 'inactive')->orderBy('full_name')->pluck('full_name', 'id')->all();
    }

    public static function rooms(): array
    {
        return Room::where('status', 'active')->orderBy('name')->pluck('name', 'id')->all();
    }

    public static function groups(): array
    {
        return Group::whereIn('status', ['enrolling', 'active'])->orderBy('name')->pluck('name', 'id')->all();
    }

    /** Staff who can own leads (anyone with leads.manage). */
    public static function managers(): array
    {
        return User::active()->with('roles.permissions')->orderBy('name')->get()
            ->filter(fn (User $u) => $u->hasPermission('leads.manage'))->pluck('name', 'id')->all();
    }

    public static function users(): array
    {
        return User::active()->orderBy('name')->pluck('name', 'id')->all();
    }

    public static function defaultBranch(): ?int
    {
        return Tenant::branchId() ?? auth()->user()?->branch_id ?? array_key_first(self::branches());
    }
}
