<?php

namespace App\Services;

use App\Models\Guardian;
use App\Models\Lead;
use App\Models\LeadNote;
use App\Models\LeadStatus;
use App\Models\Student;
use App\Models\StudentEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LeadService
{
    public function __construct(
        protected NotificationService $notifications,
        protected EnrollmentService $enrollments,
    ) {}

    public function statusBySlug(string $slug): ?LeadStatus
    {
        return LeadStatus::where('slug', $slug)->first();
    }

    public function create(array $data, ?int $userId = null, bool $notify = true): Lead
    {
        return DB::transaction(function () use ($data, $userId, $notify) {
            $status = isset($data['status_id']) ? LeadStatus::find($data['status_id']) : $this->statusBySlug('new');
            $data['status_id'] = $status?->id;
            $data['max_stage'] = max(1, (int) $status?->stage);

            $lead = Lead::create($data);
            $this->note($lead, 'Лид создан', 'system', $userId);

            if ($notify) {
                $lead->loadMissing('course', 'branch', 'source');
                $this->notifications->toStaff(
                    'leads.manage', 'new_lead', 'Новая заявка',
                    $this->notifications->render('new_lead', [
                        'name' => $lead->full_name, 'phone' => $lead->phone, 'course' => $lead->course?->name ?? '—',
                        'branch' => $lead->branch?->name ?? '—', 'source' => $lead->source?->name ?? '—',
                    ]),
                    '/leads/'.$lead->id, $lead->branch_id
                );
            }

            return $lead;
        });
    }

    public function note(Lead $lead, string $body, string $type = 'note', ?int $userId = null): LeadNote
    {
        $userId ??= auth()->id();
        $note = $lead->notes()->create(['body' => $body, 'type' => $type, 'user_id' => $userId, 'organization_id' => $lead->organization_id]);
        if ($type === 'call' || $type === 'note') {
            $lead->forceFill(['last_contact_at' => now()])->save();
        }

        return $note;
    }

    public function changeStatus(Lead $lead, LeadStatus $status, ?string $comment = null): Lead
    {
        return DB::transaction(function () use ($lead, $status, $comment) {
            if ($lead->isConverted() && $status->slug !== 'converted') {
                throw ValidationException::withMessages(['status_id' => 'Лид уже конвертирован в ученика.']);
            }
            $old = $lead->status?->name;
            $lead->status_id = $status->id;
            $lead->max_stage = max((int) $lead->max_stage, (int) $status->stage);
            if ($status->stage >= 2) {
                $lead->last_contact_at = now();
            }
            $lead->save();
            $this->note($lead, "Статус: {$old} → {$status->name}".($comment ? ". {$comment}" : ''), 'system');

            return $lead;
        });
    }

    /**
     * Convert lead → student (spec §12). The lead is kept and marked as converted.
     * Copies name, phone, parent, source, manager, course/branch. Optionally enrolls into a group.
     */
    public function convert(Lead $lead, array $extra = []): Student
    {
        return DB::transaction(function () use ($lead, $extra) {
            $lead = Lead::lockForUpdate()->findOrFail($lead->id);
            if ($lead->isConverted()) {
                throw ValidationException::withMessages(['lead' => 'Лид уже конвертирован.']);
            }

            $student = Student::create([
                'branch_id' => $lead->branch_id,
                'lead_id' => $lead->id,
                'source_id' => $lead->source_id,
                'manager_id' => $lead->manager_id,
                'first_name' => $lead->first_name,
                'last_name' => $lead->last_name,
                'phone' => $lead->phone,
                'telegram' => $lead->telegram,
                'birth_date' => $extra['birth_date'] ?? null,
                'gender' => $extra['gender'] ?? null,
                'status' => 'active',
                'registered_at' => now()->toDateString(),
                'status_changed_at' => now()->toDateString(),
                'notes' => $lead->comment,
            ]);

            if ($lead->parent_name || $lead->parent_phone) {
                $guardian = ($lead->parent_phone ? Guardian::where('phone', $lead->parent_phone)->first() : null)
                    ?? Guardian::create(['full_name' => $lead->parent_name ?: 'Родитель', 'phone' => $lead->parent_phone]);
                $student->guardians()->attach($guardian->id, ['relation' => $extra['relation'] ?? null, 'is_primary' => true]);
            }

            StudentEvent::log($student, 'created', 'Создан из лида #'.$lead->id, ['lead_id' => $lead->id]);

            if (! empty($extra['group_id'])) {
                $group = \App\Models\Group::findOrFail($extra['group_id']);
                $this->enrollments->enroll($student, $group, [
                    'price' => $extra['price'] ?? null,
                    'discount' => $extra['discount'] ?? 0,
                ]);
            }

            $converted = $this->statusBySlug('converted');
            $lead->forceFill([
                'status_id' => $converted?->id ?? $lead->status_id,
                'max_stage' => 5,
                'converted_student_id' => $student->id,
                'converted_at' => now(),
            ])->save();
            $this->note($lead, 'Конвертирован в ученика #'.$student->id, 'system');

            return $student;
        });
    }
}
