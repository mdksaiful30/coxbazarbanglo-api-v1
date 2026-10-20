<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('role')->default('staff_admin');
            $t->boolean('active')->default(true);
        });
        Schema::create('properties', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('address')->nullable();
            $t->string('timezone')->default('Asia/Dhaka');
            $t->timestamps();
        });
        Schema::create('buildings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('property_id')->constrained();
            $t->string('name');
            $t->timestamps();
        });
        Schema::create('floors', function (Blueprint $t) {
            $t->id();
            $t->foreignId('building_id')->constrained();
            $t->unsignedInteger('number');
            $t->string('name');
            $t->timestamps();
            $t->unique(['building_id', 'number']);
        });
        Schema::create('rooms', function (Blueprint $t) {
            $t->id();
            $t->foreignId('floor_id')->constrained();
            $t->string('code')->unique();
            $t->string('name');
            $t->string('ac_type');
            $t->string('bed_type');
            $t->string('room_type')->default('standard');
            $t->unsignedInteger('capacity')->nullable();
            $t->boolean('bookable')->default(false);
            $t->string('housekeeping')->default('unverified');
            $t->timestamps();
        });
        Schema::create('guest_categories', function (Blueprint $t) {
            $t->id();
            $t->string('code')->unique();
            $t->string('name');
            $t->unsignedInteger('max_nights')->nullable();
            $t->timestamps();
        });
        Schema::create('guests', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('designation')->nullable();
            $t->string('organization')->nullable();
            $t->string('mobile')->index();
            $t->string('email')->nullable();
            $t->text('address')->nullable();
            $t->timestamps();
        });
        Schema::create('tariff_versions', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('reference');
            $t->date('effective_from')->nullable();
            $t->string('calculation_mode')->nullable();
            $t->boolean('active')->default(false);
            $t->boolean('vat_inclusive')->default(true);
            $t->json('policy')->nullable();
            $t->foreignId('approved_by')->nullable()->constrained('users');
            $t->timestamp('approved_at')->nullable();
            $t->timestamps();
        });
        Schema::create('tariff_rates', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tariff_version_id')->constrained();
            $t->foreignId('guest_category_id')->constrained();
            $t->string('ac_type');
            $t->unsignedInteger('min_nights');
            $t->unsignedInteger('max_nights')->nullable();
            $t->unsignedBigInteger('amount_minor');
            $t->timestamps();
            $t->unique(['tariff_version_id', 'guest_category_id', 'ac_type', 'min_nights'], 'tariff_band_unique');
        });
        Schema::create('bookings', function (Blueprint $t) {
            $t->id();
            $t->string('number')->unique();
            $t->foreignId('guest_id')->constrained();
            $t->foreignId('guest_category_id')->constrained();
            $t->json('guest_snapshot');
            $t->string('reference_letter')->nullable();
            $t->date('reference_date')->nullable();
            $t->text('purpose')->nullable();
            $t->text('notes')->nullable();
            $t->dateTime('check_in_at');
            $t->dateTime('check_out_at');
            $t->dateTime('actual_check_in_at')->nullable();
            $t->dateTime('actual_check_out_at')->nullable();
            $t->unsignedInteger('guest_count')->default(1);
            $t->string('status')->default('draft')->index();
            $t->unsignedInteger('version')->default(1);
            $t->foreignId('tariff_version_id')->nullable()->constrained();
            $t->json('quote')->nullable();
            $t->unsignedBigInteger('total_minor')->default(0);
            $t->foreignId('created_by')->constrained('users');
            $t->foreignId('approved_by')->nullable()->constrained('users');
            $t->timestamp('approved_at')->nullable();
            $t->timestamps();
            $t->index(['check_in_at', 'check_out_at']);
        });
        Schema::create('booking_rooms', function (Blueprint $t) {
            $t->id();
            $t->foreignId('booking_id')->constrained();
            $t->foreignId('room_id')->constrained();
            $t->timestamps();
            $t->unique(['booking_id', 'room_id']);
        });
        Schema::create('room_blocks', function (Blueprint $t) {
            $t->id();
            $t->string('kind');
            $t->string('reason');
            $t->string('status')->default('active');
            $t->foreignId('created_by')->constrained('users');
            $t->timestamps();
        });
        Schema::create('room_calendar_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('room_id')->constrained();
            $t->foreignId('booking_id')->nullable()->constrained();
            $t->foreignId('room_block_id')->nullable()->constrained();
            $t->string('kind');
            $t->dateTime('starts_at');
            $t->dateTime('ends_at');
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->index(['room_id', 'active', 'starts_at', 'ends_at'], 'room_overlap_index');
        });
        Schema::create('booking_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('booking_id')->constrained();
            $t->foreignId('actor_id')->constrained('users');
            $t->string('action');
            $t->text('reason')->nullable();
            $t->json('data')->nullable();
            $t->timestamps();
        });
        Schema::create('booking_amendments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('booking_id')->constrained();
            $t->unsignedInteger('base_version');
            $t->json('proposal');
            $t->string('reason');
            $t->string('status')->default('pending');
            $t->foreignId('created_by')->constrained('users');
            $t->foreignId('decided_by')->nullable()->constrained('users');
            $t->timestamps();
        });
        Schema::create('invoices', function (Blueprint $t) {
            $t->id();
            $t->string('number')->unique();
            $t->foreignId('booking_id')->constrained();
            $t->unsignedBigInteger('total_minor');
            $t->json('lines');
            $t->string('status')->default('issued');
            $t->timestamps();
        });
        Schema::create('payments', function (Blueprint $t) {
            $t->id();
            $t->string('receipt_number')->unique();
            $t->foreignId('booking_id')->constrained();
            $t->unsignedBigInteger('amount_minor');
            $t->string('method');
            $t->string('reference')->nullable()->unique();
            $t->string('status')->default('posted');
            $t->foreignId('created_by')->constrained('users');
            $t->text('reversal_reason')->nullable();
            $t->timestamps();
        });
        Schema::create('refunds', function (Blueprint $t) {
            $t->id();
            $t->foreignId('payment_id')->constrained();
            $t->unsignedBigInteger('amount_minor');
            $t->string('reason');
            $t->foreignId('created_by')->constrained('users');
            $t->timestamps();
        });
        Schema::create('attachments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('booking_id')->constrained();
            $t->string('name');
            $t->string('path');
            $t->string('mime');
            $t->unsignedBigInteger('size');
            $t->foreignId('created_by')->constrained('users');
            $t->timestamps();
        });
        Schema::create('notification_deliveries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('booking_id')->constrained();
            $t->string('channel');
            $t->string('destination');
            $t->string('dedupe_key')->unique();
            $t->string('status')->default('queued');
            $t->unsignedInteger('attempts')->default(0);
            $t->text('last_error')->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->timestamps();
        });
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('actor_id')->nullable()->constrained('users');
            $t->string('action');
            $t->string('entity');
            $t->unsignedBigInteger('entity_id')->nullable();
            $t->json('data')->nullable();
            $t->timestamps();
        });
        Schema::create('idempotency_keys', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained();
            $t->string('key', 100);
            $t->string('fingerprint', 64);
            $t->json('response')->nullable();
            $t->unsignedInteger('status_code')->default(200);
            $t->timestamps();
            $t->unique(['user_id', 'key']);
        });
        Schema::create('settings', function (Blueprint $t) {
            $t->id();
            $t->string('key')->unique();
            $t->json('value');
            $t->timestamps();
        });
        Schema::create('personal_access_tokens', function (Blueprint $t) {
            $t->id();
            $t->morphs('tokenable');
            $t->text('name');
            $t->string('token', 64)->unique();
            $t->text('abilities')->nullable();
            $t->timestamp('last_used_at')->nullable();
            $t->timestamp('expires_at')->nullable()->index();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['personal_access_tokens', 'settings', 'idempotency_keys', 'audit_logs', 'notification_deliveries', 'attachments', 'refunds', 'payments', 'invoices', 'booking_amendments', 'booking_events', 'room_calendar_entries', 'room_blocks', 'booking_rooms', 'bookings', 'tariff_rates', 'tariff_versions', 'guests', 'guest_categories', 'rooms', 'floors', 'buildings', 'properties'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['role', 'active']));
    }
};
