<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\BranchScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Lead extends Model
{
    use \App\Models\Concerns\NormalizesPhone;
    use Auditable, BelongsToOrganization, BranchScoped, SoftDeletes;

    protected $guarded = ['id'];
    protected array $phoneFields = ['phone', 'parent_phone'];
    protected $casts = [
        'last_contact_at' => 'datetime',
        'next_contact_at' => 'datetime',
        'converted_at' => 'datetime',
    ];

    public function getFullNameAttribute(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    public function source()
    {
        return $this->belongsTo(LeadSource::class, 'source_id');
    }

    public function course()
    {
        return $this->belongsTo(Course::class);
    }

    public function manager()
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function status()
    {
        return $this->belongsTo(LeadStatus::class, 'status_id');
    }

    public function notes()
    {
        return $this->hasMany(LeadNote::class)->latest('id');
    }

    public function student()
    {
        return $this->belongsTo(Student::class, 'converted_student_id');
    }

    public function isConverted(): bool
    {
        return $this->converted_student_id !== null;
    }

    /** Sales managers without leads.view_all only see their own and unassigned leads. */
    public function scopeVisibleTo($q, User $user)
    {
        if ($user->hasPermission('leads.view_all')) {
            return $q;
        }

        $col = $this->qualifyColumn('manager_id');

        return $q->where(fn ($w) => $w->where($col, $user->id)->orWhereNull($col));
    }
}
