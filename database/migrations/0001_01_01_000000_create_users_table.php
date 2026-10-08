<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('slug')->unique();
            $t->string('logo_path')->nullable();
            $t->string('currency', 8)->default('UZS');
            $t->string('timezone', 64)->default('Asia/Tashkent');
            $t->string('locale', 5)->default('ru');
            $t->string('plan', 32)->default('start');
            $t->string('webhook_key', 64)->nullable()->unique();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('branches', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->string('address')->nullable();
            $t->string('phone', 32)->nullable();
            $t->string('manager_name')->nullable();
            $t->string('status', 16)->default('active');
            $t->timestamps();
            $t->softDeletes();
            $t->index(['organization_id', 'status']);
        });

        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $t->string('name');
            $t->string('email')->nullable()->unique();
            $t->string('phone', 32)->nullable()->unique();
            $t->string('password');
            $t->string('locale', 5)->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamp('last_login_at')->nullable();
            $t->rememberToken();
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('password_reset_tokens', function (Blueprint $t) {
            $t->string('email')->primary();
            $t->string('token');
            $t->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $t) {
            $t->string('id')->primary();
            $t->foreignId('user_id')->nullable()->index();
            $t->string('ip_address', 45)->nullable();
            $t->text('user_agent')->nullable();
            $t->longText('payload');
            $t->integer('last_activity')->index();
        });

        Schema::create('roles', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->string('slug');
            $t->string('description')->nullable();
            $t->boolean('is_system')->default(false);
            $t->timestamps();
            $t->unique(['organization_id', 'slug']);
        });

        Schema::create('permissions', function (Blueprint $t) {
            $t->id();
            $t->string('slug')->unique();
            $t->string('name');
            $t->string('group', 64);
            $t->timestamps();
        });

        Schema::create('role_user', function (Blueprint $t) {
            $t->foreignId('role_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->primary(['role_id', 'user_id']);
        });

        Schema::create('permission_role', function (Blueprint $t) {
            $t->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $t->foreignId('role_id')->constrained()->cascadeOnDelete();
            $t->primary(['permission_id', 'role_id']);
        });
    }

    public function down(): void
    {
        foreach (['permission_role', 'role_user', 'permissions', 'roles', 'sessions', 'password_reset_tokens', 'users', 'branches', 'organizations'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
