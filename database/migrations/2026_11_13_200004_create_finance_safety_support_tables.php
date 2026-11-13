<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payments, ledger, commissions, refunds, payouts, ratings, incidents, support,
 * notifications, CMS, settings, audit and idempotency.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('trip_id')->constrained();
            $table->foreignId('payer_id')->constrained('users');
            $table->string('purpose', 20)->default('fare'); // fare, cancellation_fee
            $table->decimal('amount', 10, 2);
            $table->decimal('refunded_amount', 10, 2)->default(0);
            $table->string('currency', 3)->default('PKR');
            $table->string('method', 20); // cash, card, wallet
            $table->string('provider', 30)->default('cash');
            $table->string('provider_ref')->nullable()->index();
            $table->string('status', 20)->default('pending')->index(); // pending, authorized, paid, failed, refunded, partially_refunded
            $table->timestamp('paid_at')->nullable();
            $table->string('failure_reason')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->unique(['trip_id', 'purpose'], 'payments_trip_purpose_unique');
        });

        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->uuid('transaction_id')->index()->comment('groups balanced double-entry lines');
            $table->string('account', 40)->index(); // customer_receivable, cash_clearing, driver_payable, platform_revenue, refunds_payable, payout_clearing, promotions_expense
            $table->foreignId('user_id')->nullable()->constrained();
            $table->foreignId('trip_id')->nullable()->constrained();
            $table->foreignId('payment_id')->nullable()->constrained();
            $table->unsignedBigInteger('payout_id')->nullable()->index();
            $table->unsignedBigInteger('refund_id')->nullable()->index();
            $table->enum('direction', ['debit', 'credit']);
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('PKR');
            $table->string('entry_type', 40); // trip_fare, commission, cash_collection, refund, payout, adjustment, promotion
            $table->string('description');
            $table->string('actor_type', 20)->default('system');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['user_id', 'account']);
        });

        Schema::create('commissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_id')->unique()->constrained();
            $table->foreignId('driver_id')->constrained('users');
            $table->decimal('gross_fare', 10, 2);
            $table->decimal('rate', 5, 2);
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3)->default('PKR');
            $table->timestamps();
        });

        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('payment_id')->constrained();
            $table->foreignId('trip_id')->constrained();
            $table->foreignId('user_id')->constrained();
            $table->decimal('amount', 10, 2);
            $table->string('reason');
            $table->string('status', 20)->default('requested')->index(); // requested, approved, rejected, processed
            $table->foreignId('maker_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->foreignId('checker_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->text('admin_notes')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('driver_payouts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('driver_id')->constrained('users');
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('PKR');
            $table->string('method', 30);
            $table->string('account_title')->nullable();
            $table->string('account_number_masked', 40)->nullable();
            $table->string('status', 20)->default('requested')->index(); // requested, approved, processing, paid, failed, rejected
            $table->foreignId('maker_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->foreignId('checker_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('provider_ref')->nullable();
            $table->string('failure_reason')->nullable();
            $table->text('admin_notes')->nullable();
            $table->timestamps();
        });

        Schema::create('payment_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 30);
            $table->string('event_id', 120);
            $table->string('type', 80)->nullable();
            $table->json('payload');
            $table->timestamp('processed_at')->nullable();
            $table->string('error')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'event_id']);
        });

        Schema::create('ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rater_id')->constrained('users');
            $table->foreignId('ratee_id')->constrained('users');
            $table->enum('rater_role', ['customer', 'driver']);
            $table->unsignedTinyInteger('score');
            $table->json('tags')->nullable();
            $table->string('comment', 500)->nullable();
            $table->string('moderation_status', 20)->default('visible'); // visible, hidden, flagged
            $table->timestamps();
            $table->unique(['trip_id', 'rater_id']);
            $table->index('ratee_id');
        });

        Schema::create('incidents', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('reference', 20)->unique();
            $table->foreignId('trip_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('reporter_id')->constrained('users');
            $table->foreignId('against_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 30); // sos, safety_report, harassment, accident, unsafe_driving, other
            $table->string('severity', 20)->default('medium')->index(); // low, medium, high, critical
            $table->string('status', 20)->default('open')->index(); // open, acknowledged, investigating, escalated, resolved, closed
            $table->text('description')->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->foreignId('assigned_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->text('restricted_notes')->nullable()->comment('encrypted, safety officers only');
            $table->text('resolution')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });

        Schema::create('incident_evidence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incident_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by')->constrained('users');
            $table->string('file_path');
            $table->string('mime', 60)->nullable();
            $table->timestamps();
        });

        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 20)->unique();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('trip_id')->nullable()->constrained()->nullOnDelete();
            $table->string('category', 30); // general, payment, lost_item, complaint, account, driver, safety, refund
            $table->string('subject');
            $table->string('priority', 10)->default('normal'); // low, normal, high, urgent
            $table->string('status', 20)->default('open')->index(); // open, pending, awaiting_user, resolved, closed
            $table->foreignId('assigned_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });

        Schema::create('support_ticket_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('support_ticket_id')->constrained()->cascadeOnDelete();
            $table->enum('sender_type', ['user', 'admin']);
            $table->unsignedBigInteger('sender_id');
            $table->text('body');
            $table->string('attachment_path')->nullable();
            $table->boolean('is_internal')->default(false);
            $table->timestamps();
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 50)->index();
            $table->string('title');
            $table->string('body', 500);
            $table->json('data')->nullable();
            $table->string('push_status', 20)->default('pending'); // pending, sent, partial, failed, skipped
            $table->unsignedTinyInteger('push_attempts')->default(0);
            $table->string('push_error')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'read_at']);
        });

        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('body');
            $table->enum('audience', ['all', 'customers', 'drivers'])->default('all');
            $table->foreignId('city_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('send_push')->default(true);
            $table->timestamp('sent_at')->nullable();
            $table->unsignedInteger('recipients_count')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('faqs', function (Blueprint $table) {
            $table->id();
            $table->string('category', 40)->default('general');
            $table->enum('audience', ['all', 'customers', 'drivers'])->default('all');
            $table->string('question');
            $table->text('answer');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_published')->default(true);
            $table->timestamps();
        });

        Schema::create('cms_pages', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('title');
            $table->longText('body');
            $table->string('version', 20)->default('1.0');
            $table->boolean('is_published')->default(true);
            $table->timestamps();
        });

        Schema::create('contact_messages', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('subject');
            $table->text('message');
            $table->string('status', 20)->default('new');
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();
        });

        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->json('value')->nullable();
            $table->string('group', 40)->default('general');
            $table->string('type', 20)->default('string');
            $table->string('description')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('actor_type', 20); // admin, user, system
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('action', 80)->index();
            $table->string('auditable_type')->nullable();
            $table->unsignedBigInteger('auditable_id')->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->json('meta')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
            $table->index(['auditable_type', 'auditable_id']);
            $table->index(['actor_type', 'actor_id']);
        });

        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('key', 80);
            $table->string('route', 120);
            $table->string('request_hash', 64);
            $table->unsignedSmallInteger('response_code')->nullable();
            $table->longText('response_body')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'key', 'route']);
        });
    }

    public function down(): void
    {
        foreach (['idempotency_keys', 'audit_logs', 'system_settings', 'contact_messages', 'cms_pages', 'faqs', 'announcements', 'notifications', 'support_ticket_messages', 'support_tickets', 'incident_evidence', 'incidents', 'ratings', 'payment_webhook_events', 'driver_payouts', 'refunds', 'commissions', 'ledger_entries', 'payments'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
