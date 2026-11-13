<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Identity, eligibility, roles, OTP, consents, devices and admin users.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->boolean('is_active')->default(true);
            $table->text('app_authentication_secret')->nullable();
            $table->text('app_authentication_recovery_codes')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('user_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('role', ['customer', 'driver']);
            $table->enum('status', ['pending', 'active', 'suspended', 'revoked'])->default('pending');
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'role']);
        });

        Schema::create('otp_codes', function (Blueprint $table) {
            $table->id();
            $table->string('phone', 20)->index();
            $table->string('purpose', 32)->default('login');
            $table->string('code_hash');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('ip_address', 45)->nullable();
            $table->string('device_id', 120)->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('consents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 40); // terms, privacy, location, eligibility, driver_agreement
            $table->string('version', 20);
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('accepted_at');
            $table->timestamps();
            $table->index(['user_id', 'type']);
        });

        Schema::create('eligibility_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 32)->default('draft')->index();
            $table->string('method', 40)->default('document_review');
            $table->string('document_type', 40)->nullable();
            $table->string('document_path')->nullable();
            $table->string('document_back_path')->nullable();
            $table->string('selfie_path')->nullable();
            $table->text('declaration')->nullable();
            $table->string('consent_version', 20)->nullable();
            $table->timestamp('consented_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('reviewer_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('reviewer_notes')->nullable()->comment('internal only');
            $table->string('user_facing_reason')->nullable();
            $table->text('appeal_reason')->nullable();
            $table->timestamp('appealed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('device_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token', 512);
            $table->string('token_hash', 64)->unique();
            $table->enum('platform', ['android', 'ios', 'web'])->default('android');
            $table->string('device_id', 120)->nullable();
            $table->string('device_name', 120)->nullable();
            $table->string('app_version', 20)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->unsignedSmallInteger('failure_count')->default(0);
            $table->timestamps();
            $table->index(['user_id', 'revoked_at']);
        });

        Schema::create('saved_places', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('label', 20)->default('other'); // home, work, other
            $table->string('name', 120);
            $table->string('address');
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->timestamps();
        });

        Schema::create('user_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('blocker_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('blocked_id')->constrained('users')->cascadeOnDelete();
            $table->string('reason')->nullable();
            $table->timestamps();
            $table->unique(['blocker_id', 'blocked_id']);
        });
    }

    public function down(): void
    {
        foreach (['user_blocks', 'saved_places', 'device_tokens', 'eligibility_verifications', 'consents', 'otp_codes', 'user_roles', 'admin_users'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
