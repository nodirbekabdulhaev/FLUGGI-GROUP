<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function org(Blueprint $t, bool $branch = false): void
    {
        $t->foreignId('organization_id')->constrained()->cascadeOnDelete();
        if ($branch) {
            $t->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
        }
    }

    public function up(): void
    {
        Schema::create('rooms', function (Blueprint $t) {
            $t->id();
            $this->org($t, true);
            $t->string('name');
            $t->string('floor', 16)->nullable();
            $t->unsignedSmallInteger('capacity')->default(0);
            $t->string('equipment')->nullable();
            $t->string('status', 16)->default('active');
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('subjects', function (Blueprint $t) {
            $t->id();
            $this->org($t);
            $t->string('name');
            $t->timestamps();
        });

        Schema::create('courses', function (Blueprint $t) {
            $t->id();
            $this->org($t);
            $t->foreignId('subject_id')->nullable()->constrained()->nullOnDelete();
            $t->string('name');
            $t->text('description')->nullable();
            $t->unsignedSmallInteger('duration_months')->default(1);
            $t->unsignedSmallInteger('lessons_count')->default(0);
            $t->decimal('price', 14, 2)->default(0);
            $t->string('status', 16)->default('active');
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('branch_course', function (Blueprint $t) {
            $t->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $t->foreignId('course_id')->constrained()->cascadeOnDelete();
            $t->primary(['branch_id', 'course_id']);
        });

        Schema::create('teachers', function (Blueprint $t) {
            $t->id();
            $this->org($t, true);
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('subject_id')->nullable()->constrained()->nullOnDelete();
            $t->string('full_name');
            $t->string('phone', 32)->nullable();
            $t->string('telegram')->nullable();
            $t->string('pay_type', 16)->default('fixed');   // fixed|per_lesson|per_student|percent
            $t->decimal('rate', 14, 2)->default(0);
            $t->date('hired_at')->nullable();
            $t->string('status', 16)->default('active');    // active|vacation|inactive
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('employees', function (Blueprint $t) {
            $t->id();
            $this->org($t, true);
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('full_name');
            $t->string('position')->nullable();
            $t->string('phone', 32)->nullable();
            $t->string('telegram')->nullable();
            $t->string('pay_type', 16)->default('fixed');
            $t->decimal('rate', 14, 2)->default(0);
            $t->date('hired_at')->nullable();
            $t->string('status', 16)->default('active');
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('lead_sources', function (Blueprint $t) {
            $t->id();
            $this->org($t);
            $t->string('name');
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('lead_statuses', function (Blueprint $t) {
            $t->id();
            $this->org($t);
            $t->string('name');
            $t->string('slug', 32);
            // funnel stage: 0 none, 1 new, 2 contacted, 3 booked, 4 attended, 5 sold
            $t->unsignedTinyInteger('stage')->default(1);
            $t->boolean('is_lost')->default(false);
            $t->string('color', 16)->default('slate');
            $t->unsignedSmallInteger('sort')->default(0);
            $t->timestamps();
            $t->unique(['organization_id', 'slug']);
        });

        Schema::create('expulsion_reasons', function (Blueprint $t) {
            $t->id();
            $this->org($t);
            $t->string('name');
            $t->timestamps();
        });

        Schema::create('leads', function (Blueprint $t) {
            $t->id();
            $this->org($t, true);
            $t->string('first_name');
            $t->string('last_name')->nullable();
            $t->string('phone', 32);
            $t->string('telegram')->nullable();
            $t->string('instagram')->nullable();
            $t->unsignedSmallInteger('age')->nullable();
            $t->string('parent_name')->nullable();
            $t->string('parent_phone', 32)->nullable();
            $t->foreignId('source_id')->nullable()->constrained('lead_sources')->nullOnDelete();
            $t->foreignId('course_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('manager_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('status_id')->nullable()->constrained('lead_statuses')->nullOnDelete();
            $t->unsignedTinyInteger('max_stage')->default(1);
            $t->text('comment')->nullable();
            $t->string('campaign')->nullable();
            $t->string('utm_source')->nullable();
            $t->string('utm_medium')->nullable();
            $t->string('utm_campaign')->nullable();
            $t->string('utm_content')->nullable();
            $t->string('utm_term')->nullable();
            $t->timestamp('last_contact_at')->nullable();
            $t->timestamp('next_contact_at')->nullable();
            $t->unsignedBigInteger('converted_student_id')->nullable()->index();
            $t->timestamp('converted_at')->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->index(['organization_id', 'created_at']);
            $t->index(['organization_id', 'status_id']);
            $t->index('phone');
        });

        Schema::create('lead_notes', function (Blueprint $t) {
            $t->id();
            $this->org($t);
            $t->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('type', 16)->default('note');   // system|note|call
            $t->text('body');
            $t->timestamps();
        });

        Schema::create('students', function (Blueprint $t) {
            $t->id();
            $this->org($t, true);
            $t->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('source_id')->nullable()->constrained('lead_sources')->nullOnDelete();
            $t->foreignId('manager_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('first_name');
            $t->string('last_name')->nullable();
            $t->date('birth_date')->nullable();
            $t->string('gender', 8)->nullable();
            $t->string('phone', 32)->nullable();
            $t->string('telegram')->nullable();
            $t->string('address')->nullable();
            $t->string('photo_path')->nullable();
            $t->string('status', 16)->default('active'); // active|frozen|completed|expelled|archived
            $t->foreignId('expulsion_reason_id')->nullable()->constrained('expulsion_reasons')->nullOnDelete();
            $t->date('registered_at')->nullable();
            $t->date('status_changed_at')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->index(['organization_id', 'status']);
            $t->index('phone');
        });

        Schema::create('parents', function (Blueprint $t) {
            $t->id();
            $this->org($t);
            $t->string('full_name');
            $t->string('phone', 32)->nullable();
            $t->string('telegram')->nullable();
            $t->string('address')->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->index('phone');
        });

        Schema::create('student_parents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('student_id')->constrained()->cascadeOnDelete();
            $t->foreignId('parent_id')->constrained('parents')->cascadeOnDelete();
            $t->string('relation', 32)->nullable();
            $t->boolean('is_primary')->default(false);
            $t->unique(['student_id', 'parent_id']);
        });

        Schema::create('student_events', function (Blueprint $t) {
            $t->id();
            $this->org($t);
            $t->foreignId('student_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('type', 32);
            $t->string('title');
            $t->json('meta')->nullable();
            $t->timestamps();
            $t->index(['student_id', 'created_at']);
        });

        Schema::create('groups', function (Blueprint $t) {
            $t->id();
            $this->org($t, true);
            $t->foreignId('course_id')->constrained()->restrictOnDelete();
            $t->foreignId('teacher_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('room_id')->nullable()->constrained()->nullOnDelete();
            $t->string('name');
            $t->unsignedSmallInteger('max_students')->default(15);
            $t->decimal('price', 14, 2)->nullable();
            $t->date('start_date')->nullable();
            $t->date('end_date')->nullable();
            $t->string('status', 16)->default('enrolling'); // enrolling|active|completed|archived
            $t->timestamps();
            $t->softDeletes();
            $t->index(['organization_id', 'status']);
        });

        Schema::create('group_students', function (Blueprint $t) {
            $t->id();
            $this->org($t, true);
            $t->foreignId('group_id')->constrained()->cascadeOnDelete();
            $t->foreignId('student_id')->constrained()->cascadeOnDelete();
            $t->string('status', 16)->default('active');   // active|frozen|completed|left|transferred
            $t->date('joined_at');
            $t->date('left_at')->nullable();
            $t->decimal('price', 14, 2)->default(0);
            $t->decimal('discount', 14, 2)->default(0);
            $t->date('next_payment_date')->nullable();
            $t->foreignId('transferred_from_id')->nullable()->constrained('group_students')->nullOnDelete();
            $t->string('note')->nullable();
            $t->timestamps();
            $t->index(['group_id', 'status']);
            $t->index(['student_id', 'status']);
        });

        Schema::create('schedules', function (Blueprint $t) {
            $t->id();
            $this->org($t, true);
            $t->foreignId('group_id')->constrained()->cascadeOnDelete();
            $t->foreignId('teacher_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('room_id')->nullable()->constrained()->nullOnDelete();
            $t->unsignedTinyInteger('weekday');     // 1=Mon .. 7=Sun
            $t->time('start_time');
            $t->time('end_time');
            $t->date('start_date');
            $t->date('end_date')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->index(['organization_id', 'weekday']);
        });

        Schema::create('lessons', function (Blueprint $t) {
            $t->id();
            $this->org($t, true);
            $t->foreignId('group_id')->constrained()->cascadeOnDelete();
            $t->foreignId('schedule_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('teacher_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('room_id')->nullable()->constrained()->nullOnDelete();
            $t->date('lesson_date');
            $t->time('start_time');
            $t->time('end_time');
            $t->string('status', 16)->default('planned');  // planned|held|cancelled|rescheduled
            $t->string('topic')->nullable();
            $t->string('cancel_reason')->nullable();
            $t->timestamp('held_at')->nullable();
            $t->timestamps();
            $t->index(['group_id', 'lesson_date']);
            $t->index(['organization_id', 'lesson_date']);
            $t->index(['teacher_id', 'lesson_date']);
            $t->unique(['schedule_id', 'lesson_date']);
        });

        Schema::create('attendance', function (Blueprint $t) {
            $t->id();
            $this->org($t, true);
            $t->foreignId('lesson_id')->constrained()->cascadeOnDelete();
            $t->foreignId('student_id')->constrained()->cascadeOnDelete();
            $t->string('status', 16);                      // present|absent|late|excused
            $t->string('comment')->nullable();
            $t->foreignId('marked_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['lesson_id', 'student_id']);
            $t->index(['student_id', 'status']);
            // covering index: attendance aggregates by lesson/status/student are answered from the index alone
            $t->index(['lesson_id', 'status', 'student_id'], 'attendance_report_idx');
        });
    }

    public function down(): void
    {
        foreach (['attendance', 'lessons', 'schedules', 'group_students', 'groups', 'student_events', 'student_parents', 'parents', 'students', 'lead_notes', 'leads', 'expulsion_reasons', 'lead_statuses', 'lead_sources', 'employees', 'teachers', 'branch_course', 'courses', 'subjects', 'rooms'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
