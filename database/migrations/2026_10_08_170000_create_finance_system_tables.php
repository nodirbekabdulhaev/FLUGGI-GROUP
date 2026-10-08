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
        Schema::create('payment_methods', function (Blueprint $t) {
            $t->id();
            $this->org($t);
            $t->string('name');
            $t->string('code', 32);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('payments', function (Blueprint $t) {
            $t->id();
            $this->org($t, true);
            $t->foreignId('student_id')->constrained()->restrictOnDelete();
            $t->foreignId('group_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('course_id')->nullable()->constrained()->nullOnDelete();
            $t->unsignedBigInteger('group_student_id')->nullable()->index();
            $t->foreignId('method_id')->nullable()->constrained('payment_methods')->nullOnDelete();
            $t->string('type', 16)->default('payment');   // payment|refund|correction
            $t->decimal('amount', 14, 2);
            $t->timestamp('paid_at');
            $t->foreignId('cashier_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('comment')->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->index(['organization_id', 'paid_at']);
            $t->index(['student_id', 'paid_at']);
        });

        Schema::create('debts', function (Blueprint $t) {
            $t->id();
            $this->org($t, true);
            $t->foreignId('student_id')->constrained()->cascadeOnDelete();
            $t->foreignId('group_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('course_id')->nullable()->constrained()->nullOnDelete();
            $t->unsignedBigInteger('group_student_id')->unique();
            $t->decimal('charged', 14, 2)->default(0);
            $t->decimal('paid', 14, 2)->default(0);
            $t->decimal('balance', 14, 2)->default(0);   // charged - paid (>0 = debt)
            $t->timestamp('last_payment_at')->nullable();
            $t->date('next_payment_date')->nullable();
            $t->timestamps();
            $t->index(['organization_id', 'balance']);
        });

        Schema::create('expense_categories', function (Blueprint $t) {
            $t->id();
            $this->org($t);
            $t->string('name');
            $t->timestamps();
        });

        Schema::create('expenses', function (Blueprint $t) {
            $t->id();
            $this->org($t, true);
            $t->foreignId('category_id')->nullable()->constrained('expense_categories')->nullOnDelete();
            $t->foreignId('method_id')->nullable()->constrained('payment_methods')->nullOnDelete();
            $t->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $t->date('spent_at');
            $t->decimal('amount', 14, 2);
            $t->string('description')->nullable();
            $t->string('file_path')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->softDeletes();
            $t->index(['organization_id', 'spent_at']);
        });

        Schema::create('salary_rules', function (Blueprint $t) {
            $t->id();
            $this->org($t);
            $t->morphs('payable');
            $t->string('type', 16);          // fixed|per_lesson|per_student|percent
            $t->decimal('rate', 14, 2)->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('salary_accruals', function (Blueprint $t) {
            $t->id();
            $this->org($t, true);
            $t->morphs('payable');
            $t->string('period', 7);         // YYYY-MM
            $t->string('rule_type', 16);
            $t->decimal('rate', 14, 2)->default(0);
            $t->decimal('units', 14, 2)->default(0);
            $t->decimal('amount', 14, 2);
            $t->string('note')->nullable();
            $t->timestamps();
            $t->unique(['payable_type', 'payable_id', 'period']);
        });

        Schema::create('salary_payments', function (Blueprint $t) {
            $t->id();
            $this->org($t, true);
            $t->morphs('payable');
            $t->decimal('amount', 14, 2);
            $t->date('paid_at');
            $t->foreignId('method_id')->nullable()->constrained('payment_methods')->nullOnDelete();
            $t->unsignedBigInteger('expense_id')->nullable();
            $t->string('comment')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('telegram_accounts', function (Blueprint $t) {
            $t->id();
            $this->org($t);
            $t->nullableMorphs('linkable');   // student|parent|employee|teacher|user
            $t->string('token', 32)->nullable()->unique();
            $t->unsignedBigInteger('telegram_user_id')->nullable()->unique();
            $t->unsignedBigInteger('chat_id')->nullable();
            $t->string('username')->nullable();
            $t->timestamp('linked_at')->nullable();
            $t->timestamps();
        });

        Schema::create('notifications', function (Blueprint $t) {
            $t->id();
            $this->org($t);
            $t->string('type', 32);          // lesson_reminder|payment_due|payment_received|absence|new_lead|system
            $t->string('channel', 16);       // telegram|web
            $t->nullableMorphs('recipient');
            $t->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();   // web bell owner
            $t->unsignedBigInteger('chat_id')->nullable();
            $t->string('title')->nullable();
            $t->text('body');
            $t->string('url')->nullable();
            $t->string('status', 16)->default('pending');  // pending|sent|failed
            $t->unsignedTinyInteger('attempts')->default(0);
            $t->timestamp('scheduled_at')->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->timestamp('read_at')->nullable();
            $t->text('error')->nullable();
            $t->string('dedupe_key', 120)->nullable()->unique();
            $t->timestamps();
            $t->index(['channel', 'status', 'scheduled_at']);
            $t->index(['user_id', 'read_at']);
        });

        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('organization_id')->nullable()->index();
            $t->unsignedBigInteger('user_id')->nullable()->index();
            $t->string('user_name')->nullable();
            $t->string('event', 32);
            $t->string('auditable_type')->nullable();
            $t->unsignedBigInteger('auditable_id')->nullable();
            $t->json('old_values')->nullable();
            $t->json('new_values')->nullable();
            $t->string('ip', 45)->nullable();
            $t->string('user_agent', 255)->nullable();
            $t->timestamp('created_at')->nullable()->index();
            $t->index(['auditable_type', 'auditable_id']);
        });

        Schema::create('settings', function (Blueprint $t) {
            $t->id();
            $this->org($t);
            $t->string('key', 80);
            $t->text('value')->nullable();
            $t->timestamps();
            $t->unique(['organization_id', 'key']);
        });
    }

    public function down(): void
    {
        foreach (['settings', 'audit_logs', 'notifications', 'telegram_accounts', 'salary_payments', 'salary_accruals', 'salary_rules', 'expenses', 'expense_categories', 'debts', 'payments', 'payment_methods'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
