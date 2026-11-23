<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Email + password registration, with email OTP for verification and every login. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable()->after('email');
            $table->timestamp('email_verified_at')->nullable()->after('password');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['email']);
            $table->unique('email');
        });
        // OTP identifier is now a phone number or an email address.
        Schema::table('otp_codes', function (Blueprint $table) {
            $table->string('phone', 191)->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['email']);
            $table->index('email');
            $table->dropColumn(['password', 'email_verified_at']);
        });
    }
};
