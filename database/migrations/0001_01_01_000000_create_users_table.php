<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('country_code', 6)->default('+92');
            $table->string('phone', 20)->unique()->comment('E.164 formatted phone number');
            $table->string('name')->nullable();
            $table->string('email')->nullable()->index();
            $table->string('photo_path')->nullable();
            $table->string('locale', 8)->default('en');
            $table->enum('account_status', ['active', 'suspended', 'pending_deletion', 'deleted'])->default('active')->index();
            $table->string('eligibility_status', 32)->default('draft')->index();
            $table->enum('active_mode', ['customer', 'driver'])->default('customer');
            $table->text('emergency_contact_name')->nullable();
            $table->text('emergency_contact_phone')->nullable()->comment('encrypted');
            $table->decimal('rating_avg', 3, 2)->default(5.00);
            $table->unsignedInteger('rating_count')->default(0);
            $table->json('notification_preferences')->nullable();
            $table->json('privacy_preferences')->nullable();
            $table->string('suspension_reason')->nullable();
            $table->timestamp('phone_verified_at')->nullable();
            $table->timestamp('profile_completed_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamp('deletion_requested_at')->nullable();
            $table->string('deletion_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
