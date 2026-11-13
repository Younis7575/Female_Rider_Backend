<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cities, zones, vehicle categories, fare rules, drivers, vehicles, documents.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cities', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('country_code', 2)->default('PK');
            $table->string('currency', 3)->default('PKR');
            $table->string('timezone', 40)->default('Asia/Karachi');
            $table->decimal('center_lat', 10, 7);
            $table->decimal('center_lng', 10, 7);
            $table->string('emergency_number', 20)->default('15');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('service_zones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('city_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->json('polygon')->comment('[[lat,lng],...]');
            $table->decimal('center_lat', 10, 7);
            $table->decimal('center_lng', 10, 7);
            $table->boolean('is_active')->default(true);
            $table->boolean('surge_enabled')->default(false);
            $table->decimal('surge_multiplier', 4, 2)->default(1.00);
            $table->decimal('surge_cap', 4, 2)->default(2.00);
            $table->timestamps();
        });

        Schema::create('vehicle_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('icon', 40)->default('car');
            $table->string('description')->nullable();
            $table->unsignedTinyInteger('capacity')->default(4);
            $table->decimal('base_fare', 10, 2);
            $table->decimal('per_km', 10, 2);
            $table->decimal('per_minute', 10, 2);
            $table->decimal('minimum_fare', 10, 2);
            $table->decimal('booking_fee', 10, 2)->default(0);
            $table->decimal('waiting_per_minute', 10, 2)->default(0);
            $table->decimal('cancellation_fee', 10, 2)->default(0);
            $table->decimal('min_offer_ratio', 4, 2)->default(0.70)->comment('lowest allowed offer vs suggested');
            $table->decimal('max_offer_ratio', 4, 2)->default(2.50)->comment('highest allowed offer vs suggested');
            $table->decimal('commission_percent', 5, 2)->default(15.00);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('fare_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_category_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_zone_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->decimal('base_fare', 10, 2)->nullable();
            $table->decimal('per_km', 10, 2)->nullable();
            $table->decimal('per_minute', 10, 2)->nullable();
            $table->decimal('minimum_fare', 10, 2)->nullable();
            $table->decimal('commission_percent', 5, 2)->nullable();
            $table->decimal('time_multiplier', 4, 2)->default(1.00);
            $table->time('applies_from')->nullable();
            $table->time('applies_to')->nullable();
            $table->json('days_of_week')->nullable();
            $table->timestamp('effective_from')->nullable();
            $table->timestamp('effective_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('driver_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('status', 32)->default('not_submitted')->index();
            $table->string('full_name')->nullable();
            $table->text('cnic_number')->nullable()->comment('encrypted');
            $table->date('date_of_birth')->nullable();
            $table->text('license_number')->nullable()->comment('encrypted');
            $table->date('license_expiry')->nullable()->index();
            $table->string('address')->nullable();
            $table->string('emergency_contact_name')->nullable();
            $table->text('emergency_contact_phone')->nullable()->comment('encrypted');
            $table->foreignId('service_zone_id')->nullable()->constrained()->nullOnDelete();
            $table->string('agreement_version', 20)->nullable();
            $table->timestamp('agreement_accepted_at')->nullable();
            $table->decimal('rating_avg', 3, 2)->default(5.00);
            $table->unsignedInteger('rating_count')->default(0);
            $table->unsignedInteger('completed_trips')->default(0);
            $table->unsignedInteger('cancelled_trips')->default(0);
            $table->boolean('is_online')->default(false)->index();
            $table->timestamp('last_online_at')->nullable();
            $table->string('payout_method', 30)->nullable();
            $table->string('payout_account_title')->nullable();
            $table->text('payout_account_number')->nullable()->comment('encrypted');
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('reviewer_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable()->comment('internal only');
            $table->string('user_facing_reason')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });

        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_category_id')->constrained();
            $table->string('make', 60);
            $table->string('model', 60);
            $table->string('color', 30);
            $table->unsignedSmallInteger('year');
            $table->string('plate_number', 20)->unique();
            $table->unsignedTinyInteger('capacity')->default(4);
            $table->string('status', 20)->default('pending')->index();
            $table->boolean('is_primary')->default(true);
            $table->date('registration_expiry')->nullable();
            $table->date('insurance_expiry')->nullable();
            $table->string('rejection_reason')->nullable();
            $table->timestamps();
        });

        Schema::create('driver_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 40);
            $table->string('file_path');
            $table->string('mime', 60)->nullable();
            $table->unsignedInteger('size')->nullable();
            $table->date('expiry_date')->nullable()->index();
            $table->string('status', 20)->default('pending')->index();
            $table->foreignId('reviewer_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('rejection_reason')->nullable();
            $table->timestamp('expiry_reminded_at')->nullable();
            $table->timestamps();
            $table->index(['driver_profile_id', 'type']);
        });

        Schema::create('driver_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_profile_id')->unique()->constrained()->cascadeOnDelete();
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->decimal('heading', 6, 2)->nullable();
            $table->decimal('speed', 6, 2)->nullable();
            $table->decimal('accuracy', 8, 2)->nullable();
            $table->unsignedBigInteger('sequence')->default(0);
            $table->timestamp('recorded_at')->index();
            $table->timestamps();
        });

        Schema::create('driver_online_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_profile_id')->constrained()->cascadeOnDelete();
            $table->timestamp('online_at');
            $table->timestamp('offline_at')->nullable();
            $table->string('ended_reason', 40)->nullable();
            $table->timestamps();
            $table->index(['driver_profile_id', 'online_at']);
        });
    }

    public function down(): void
    {
        foreach (['driver_online_sessions', 'driver_locations', 'driver_documents', 'vehicles', 'driver_profiles', 'fare_rules', 'vehicle_categories', 'service_zones', 'cities'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
