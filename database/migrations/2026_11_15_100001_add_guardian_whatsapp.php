<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Driver guardian (family member) who receives the driver's live location on WhatsApp
 * when she picks up a rider and when she completes the ride.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->string('guardian_name', 80)->nullable()->after('emergency_contact_phone');
            $table->string('guardian_relationship', 30)->nullable()->after('guardian_name');
            $table->text('guardian_whatsapp')->nullable()->after('guardian_relationship')->comment('encrypted, E.164 digits');
            $table->boolean('guardian_alerts_enabled')->default(true)->after('guardian_whatsapp');
            $table->timestamp('guardian_consent_at')->nullable()->after('guardian_alerts_enabled');
        });

        Schema::create('guardian_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('trip_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event', 30); // trip_started, trip_completed, test
            $table->string('channel', 20)->default('whatsapp');
            $table->string('status', 20)->default('queued'); // queued, sent, failed, skipped
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->string('location_source', 20)->nullable(); // live, pickup, dropoff
            $table->string('provider_message_id')->nullable();
            $table->string('error', 500)->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->unique(['trip_id', 'event']);
            $table->index(['driver_profile_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guardian_alerts');
        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->dropColumn(['guardian_name', 'guardian_relationship', 'guardian_whatsapp', 'guardian_alerts_enabled', 'guardian_consent_at']);
        });
    }
};
