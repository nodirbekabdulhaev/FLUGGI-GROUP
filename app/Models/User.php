<?php

namespace App\Models;

use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Сотрудник с доступом в систему. Пароль — argon2id (password_hash), сессии — таблица sessions.
 */
class User extends Model implements AuthenticatableContract
{
    use Authenticatable, SoftDeletes;

    protected $hidden = ['password_hash'];

    protected $table = 'users';

    protected $casts = [
        'failed_login_count' => 'integer',
        'locked_until' => 'datetime',
        'last_login_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'team_id');
    }

    public function headedTeams(): HasMany
    {
        return $this->hasMany(Team::class, 'head_id');
    }

    public function directions(): HasMany
    {
        return $this->hasMany(UserDirection::class, 'user_id');
    }

    public function leadForms(): HasMany
    {
        return $this->hasMany(LeadForm::class, 'owner_id');
    }

    public function socialThreads(): HasMany
    {
        return $this->hasMany(SocialThread::class, 'owner_id');
    }

    public function socialReplies(): HasMany
    {
        return $this->hasMany(SocialMessage::class, 'author_id');
    }

    public function employee(): HasOne
    {
        return $this->hasOne(Employee::class, 'user_id');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(Session::class, 'user_id');
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class, 'actor_id');
    }

    public function ownedLeads(): HasMany
    {
        return $this->hasMany(Lead::class, 'owner_id');
    }

    public function createdLeads(): HasMany
    {
        return $this->hasMany(Lead::class, 'created_by_id');
    }

    public function ownedClients(): HasMany
    {
        return $this->hasMany(Client::class, 'owner_id');
    }

    public function ownedDeals(): HasMany
    {
        return $this->hasMany(Deal::class, 'owner_id');
    }

    public function createdDeals(): HasMany
    {
        return $this->hasMany(Deal::class, 'created_by_id');
    }

    public function managedMeetings(): HasMany
    {
        return $this->hasMany(Meeting::class, 'manager_id');
    }

    public function ropMeetings(): HasMany
    {
        return $this->hasMany(Meeting::class, 'rop_id');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class, 'actor_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class, 'author_id');
    }

    public function stageChanges(): HasMany
    {
        return $this->hasMany(StageHistory::class, 'changed_by_id');
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class, 'user_id');
    }

    public function exchangeRatesSet(): HasMany
    {
        return $this->hasMany(ExchangeRate::class, 'set_by_id');
    }

    public function proposalsManaged(): HasMany
    {
        return $this->hasMany(Proposal::class, 'manager_id');
    }

    public function proposalsApproved(): HasMany
    {
        return $this->hasMany(Proposal::class, 'approved_by_id');
    }

    public function proposalVersions(): HasMany
    {
        return $this->hasMany(ProposalVersion::class, 'author_id');
    }

    public function contractsCreated(): HasMany
    {
        return $this->hasMany(Contract::class, 'created_by_id');
    }

    public function paymentsCreated(): HasMany
    {
        return $this->hasMany(Payment::class, 'created_by_id');
    }

    public function paymentsConfirmed(): HasMany
    {
        return $this->hasMany(Payment::class, 'confirmed_by_id');
    }

    public function projectsAsRop(): HasMany
    {
        return $this->hasMany(Project::class, 'rop_id');
    }

    public function projectsAsManager(): HasMany
    {
        return $this->hasMany(Project::class, 'manager_id');
    }

    public function commissions(): HasMany
    {
        return $this->hasMany(Commission::class, 'user_id');
    }

    public function filesUploaded(): HasMany
    {
        return $this->hasMany(StoredFile::class, 'uploaded_by_id');
    }

    public function projectMemberships(): HasMany
    {
        return $this->hasMany(ProjectMember::class, 'user_id');
    }

    public function projectAssignments(): HasMany
    {
        return $this->hasMany(ProjectMember::class, 'assigned_by_id');
    }

    public function tasksAssigned(): HasMany
    {
        return $this->hasMany(Task::class, 'assignee_id');
    }

    public function tasksCreated(): HasMany
    {
        return $this->hasMany(Task::class, 'creator_id');
    }

    public function taskStatusChanges(): HasMany
    {
        return $this->hasMany(TaskStatusHistory::class, 'changed_by_id');
    }

    public function taskComments(): HasMany
    {
        return $this->hasMany(TaskComment::class, 'author_id');
    }

    public function expensesCreated(): HasMany
    {
        return $this->hasMany(Expense::class, 'created_by_id');
    }

    public function expensesPaid(): HasMany
    {
        return $this->hasMany(Expense::class, 'payee_user_id');
    }

    public function commissionsApproved(): HasMany
    {
        return $this->hasMany(Commission::class, 'approved_by_id');
    }

    public function commissionsPaidOut(): HasMany
    {
        return $this->hasMany(Commission::class, 'paid_by_id');
    }

    public function kpiTargets(): HasMany
    {
        return $this->hasMany(KpiTarget::class, 'user_id');
    }

    public function kpiTargetsSet(): HasMany
    {
        return $this->hasMany(KpiTarget::class, 'set_by_id');
    }

    public function attendance(): HasMany
    {
        return $this->hasMany(Attendance::class, 'user_id');
    }

    public function attendanceEdited(): HasMany
    {
        return $this->hasMany(Attendance::class, 'edited_by_id');
    }

    public function payroll(): HasMany
    {
        return $this->hasMany(PayrollEntry::class, 'user_id');
    }

    public function todosOwned(): HasMany
    {
        return $this->hasMany(Todo::class, 'owner_id');
    }

    public function workRates(): HasMany
    {
        return $this->hasMany(EmployeeRate::class, 'user_id');
    }

    public function costLines(): HasMany
    {
        return $this->hasMany(ProjectCostLine::class, 'assignee_id');
    }

    public function otherIncomes(): HasMany
    {
        return $this->hasMany(OtherIncome::class, 'created_by_id');
    }

    public function todosCreated(): HasMany
    {
        return $this->hasMany(Todo::class, 'creator_id');
    }

    public function recurringOwned(): HasMany
    {
        return $this->hasMany(RecurringTodo::class, 'owner_id');
    }

    public function recurringCreated(): HasMany
    {
        return $this->hasMany(RecurringTodo::class, 'created_by_id');
    }

    public function chatMemberships(): HasMany
    {
        return $this->hasMany(ConversationMember::class, 'user_id');
    }

    public function chatMessages(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'author_id');
    }

    public function payrollApproved(): HasMany
    {
        return $this->hasMany(PayrollEntry::class, 'approved_by_id');
    }

    public function telegramLinkTokens(): HasMany
    {
        return $this->hasMany(TelegramLinkToken::class, 'user_id');
    }

    public function notificationSettings(): HasMany
    {
        return $this->hasMany(NotificationSetting::class, 'user_id');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(NotificationDelivery::class, 'user_id');
    }

    public function followUpsOwned(): HasMany
    {
        return $this->hasMany(FollowUp::class, 'owner_id');
    }

    public function followUpsCompleted(): HasMany
    {
        return $this->hasMany(FollowUp::class, 'completed_by_id');
    }
}
