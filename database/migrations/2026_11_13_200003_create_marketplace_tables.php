<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Coupons, ride requests, dispatch log, offers (negotiation), trips and trip telemetry.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('description')->nullable();
            $table->enum('type', ['fixed', 'percent']);
            $table->decimal('value', 10, 2);
            $table->decimal('max_discount', 10, 2)->nullable();
            $table->decimal('min_fare', 10, 2)->default(0);
            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('per_user_limit')->default(1);
            $table->unsignedInteger('used_count')->default(0);
            $table->decimal('budget', 12, 2)->nullable();
            $table->decimal('budget_used', 12, 2)->default(0);
            $table->foreignId('city_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('vehicle_category_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('first_ride_only')->default(false);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('ride_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('customer_id')->constrained('users');
            $table->foreignId('vehicle_category_id')->constrained();
            $table->foreignId('service_zone_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('pickup_lat', 10, 7);
            $table->decimal('pickup_lng', 10, 7);
            $table->string('pickup_address');
            $table->decimal('dropoff_lat', 10, 7);
            $table->decimal('dropoff_lng', 10, 7);
            $table->string('dropoff_address');
            $table->string('pickup_note', 255)->nullable();
            $table->unsignedInteger('distance_m');
            $table->unsignedInteger('duration_s');
            $table->text('route_polyline')->nullable();
            $table->decimal('suggested_fare', 10, 2);
            $table->decimal('min_fare', 10, 2);
            $table->decimal('max_fare', 10, 2);
            $table->decimal('proposed_fare', 10, 2);
            $table->decimal('initial_proposed_fare', 10, 2);
            $table->decimal('surge_multiplier', 4, 2)->default(1.00);
            $table->string('currency', 3)->default('PKR');
            $table->string('payment_method', 20)->default('cash');
            $table->foreignId('coupon_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('discount_amount', 10, 2)->default(0);
            $table->string('status', 32)->default('searching')->index();
            $table->unsignedBigInteger('accepted_offer_id')->nullable();
            $table->foreignId('driver_id')->nullable()->constrained('users');
            $table->decimal('search_radius_km', 5, 2)->default(3);
            $table->unsignedTinyInteger('broadcast_round')->default(0);
            $table->timestamp('expires_at')->index();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancelled_by', 20)->nullable();
            $table->string('cancel_reason')->nullable();
            $table->string('idempotency_key', 80)->nullable();
            $table->timestamps();
            $table->unique(['customer_id', 'idempotency_key']);
            $table->index(['customer_id', 'status']);
        });

        Schema::create('ride_dispatches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ride_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('driver_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedTinyInteger('round')->default(1);
            $table->unsignedInteger('distance_m')->nullable();
            $table->unsignedInteger('eta_s')->nullable();
            $table->string('status', 20)->default('notified'); // notified, seen, declined, offered, closed
            $table->timestamp('notified_at');
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();
            $table->unique(['ride_request_id', 'driver_id']);
            $table->index(['driver_id', 'status']);
        });

        Schema::create('ride_offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ride_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('driver_id')->constrained('users');
            $table->foreignId('vehicle_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 10, 2);
            $table->enum('proposed_by', ['driver', 'customer']);
            $table->string('status', 20)->default('pending')->index(); // pending, countered, accepted, rejected, withdrawn, expired, closed
            $table->unsignedBigInteger('parent_offer_id')->nullable();
            $table->unsignedSmallInteger('sequence')->default(1);
            $table->unsignedInteger('eta_s')->nullable();
            $table->unsignedInteger('distance_to_pickup_m')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();
            $table->unique(['ride_request_id', 'driver_id', 'sequence']);
            $table->index(['ride_request_id', 'status']);
        });

        Schema::create('trips', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('ride_request_id')->unique()->constrained()->comment('unique: one trip per ride (no duplicate assignment)');
            $table->foreignId('ride_offer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->constrained('users');
            $table->foreignId('driver_id')->constrained('users');
            $table->foreignId('vehicle_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('vehicle_category_id')->constrained();
            $table->foreignId('service_zone_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 32)->default('driver_assigned')->index();
            $table->decimal('agreed_fare', 10, 2);
            $table->decimal('final_fare', 10, 2)->nullable();
            $table->decimal('discount_amount', 10, 2)->default(0);
            $table->decimal('waiting_charge', 10, 2)->default(0);
            $table->decimal('cancellation_fee', 10, 2)->default(0);
            $table->decimal('amount_due', 10, 2)->nullable()->comment('customer payable after discount');
            $table->decimal('commission_rate', 5, 2)->default(0);
            $table->decimal('commission_amount', 10, 2)->default(0);
            $table->decimal('driver_earning', 10, 2)->default(0);
            $table->json('fare_breakdown')->nullable();
            $table->string('currency', 3)->default('PKR');
            $table->string('payment_method', 20)->default('cash');
            $table->string('payment_status', 20)->default('pending');
            $table->text('pickup_pin')->comment('encrypted 4-digit PIN');
            $table->unsignedTinyInteger('pin_attempts')->default(0);
            $table->unsignedInteger('actual_distance_m')->nullable();
            $table->unsignedInteger('actual_duration_s')->nullable();
            $table->timestamp('assigned_at');
            $table->timestamp('en_route_at')->nullable();
            $table->timestamp('arrived_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancelled_by', 20)->nullable();
            $table->string('cancel_reason')->nullable();
            $table->string('share_token', 64)->nullable()->unique();
            $table->timestamp('share_expires_at')->nullable();
            $table->timestamps();
            $table->index(['driver_id', 'status']);
            $table->index(['customer_id', 'status']);
        });

        // Database-level guarantee that a driver / customer has at most one active trip.
        $active = "'driver_assigned','driver_en_route','arrived','in_progress'";
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE trips ADD COLUMN active_driver_lock BIGINT UNSIGNED GENERATED ALWAYS AS (IF(status IN ($active), driver_id, NULL)) STORED, ADD UNIQUE INDEX trips_active_driver_unique (active_driver_lock)");
            DB::statement("ALTER TABLE trips ADD COLUMN active_customer_lock BIGINT UNSIGNED GENERATED ALWAYS AS (IF(status IN ($active), customer_id, NULL)) STORED, ADD UNIQUE INDEX trips_active_customer_unique (active_customer_lock)");
        } elseif (in_array(DB::getDriverName(), ['pgsql', 'sqlite'])) {
            DB::statement("CREATE UNIQUE INDEX trips_active_driver_unique ON trips (driver_id) WHERE status IN ($active)");
            DB::statement("CREATE UNIQUE INDEX trips_active_customer_unique ON trips (customer_id) WHERE status IN ($active)");
        }

        Schema::create('trip_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('ride_request_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('event', 40);
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32)->nullable();
            $table->string('actor_type', 20)->nullable(); // customer, driver, admin, system
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['trip_id', 'created_at']);
        });

        Schema::create('trip_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained();
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->decimal('accuracy', 8, 2)->nullable();
            $table->decimal('heading', 6, 2)->nullable();
            $table->decimal('speed', 6, 2)->nullable();
            $table->unsignedBigInteger('sequence')->default(0);
            $table->timestamp('recorded_at');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['trip_id', 'recorded_at']);
        });

        Schema::create('coupon_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ride_request_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('trip_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('discount_amount', 10, 2);
            $table->string('status', 20)->default('reserved'); // reserved, redeemed, released
            $table->timestamps();
            $table->unique(['coupon_id', 'ride_request_id']);
        });
    }

    public function down(): void
    {
        foreach (['coupon_redemptions', 'trip_locations', 'trip_events', 'trips', 'ride_offers', 'ride_dispatches', 'ride_requests', 'coupons'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
