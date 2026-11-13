<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * In-trip chat between the assigned customer and driver. Messages are encrypted at
 * rest and only writable while the trip is active (driver assigned → in progress).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trip_messages', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('trip_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sender_id')->constrained('users');
            $table->string('sender_role', 10); // customer | driver
            $table->text('body');               // encrypted cast
            $table->string('client_id', 64)->nullable(); // de-duplicates retried sends
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index(['trip_id', 'id']);
            $table->unique(['trip_id', 'sender_id', 'client_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_messages');
    }
};
