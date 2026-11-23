<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Manual payments from drivers (JazzCash transfer to the FemaleRider account + TID + screenshot).
 * An admin verifies each one; approval credits the driver's ledger balance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('driver_deposits', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('driver_id')->constrained('users');
            $table->decimal('amount', 12, 2);              // amount the driver says she sent
            $table->decimal('approved_amount', 12, 2)->nullable();
            $table->string('currency', 3)->default('PKR');
            $table->string('method', 20)->default('jazzcash');
            $table->string('transaction_ref', 40);           // JazzCash TID
            $table->string('sender_number', 20)->nullable();
            $table->string('screenshot_path');
            $table->string('status', 20)->default('pending'); // pending, approved, rejected
            $table->string('rejection_reason', 255)->nullable();
            $table->string('driver_note', 255)->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('admin_users');
            $table->timestamp('reviewed_at')->nullable();
            $table->uuid('ledger_transaction_id')->nullable();
            $table->timestamps();
            $table->unique(['method', 'transaction_ref']);
            $table->index(['status', 'created_at']);
            $table->index(['driver_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_deposits');
    }
};
