<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gift certificates and memberships. Both are paid for at the business —
 * no money passes through Thryft — and both are shown at the till by code.
 *
 * A gift certificate is either issued by the business or requested by a
 * shopper, in which case it waits as pending_payment until staff take the
 * money and mark it paid. It belongs to whoever it was for: an account when
 * one exists for that email, otherwise it is picked up when one is made.
 *
 * A membership is a period of benefits on a plan the business defines.
 * Coupons can be limited to members.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->boolean('sells_gift_certificates')->default(false)->after('page_updated_at');
        });

        Schema::create('gift_certificates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->string('code', 20)->unique();
            $table->unsignedInteger('initial_cents');
            $table->unsignedInteger('balance_cents');
            $table->enum('status', ['pending_payment', 'active', 'void'])->default('active');
            $table->foreignId('recipient_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('recipient_email')->nullable();
            $table->string('recipient_name', 120)->nullable();
            $table->foreignId('purchaser_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('from_name', 120)->nullable();
            $table->string('message', 500)->nullable();
            $table->foreignId('issued_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'status']);
            $table->index(['recipient_email', 'recipient_user_id']);
        });

        Schema::create('gift_certificate_uses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gift_certificate_id')->constrained('gift_certificates')->cascadeOnDelete();
            $table->unsignedInteger('amount_cents');
            $table->foreignId('staff_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('membership_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->string('name', 120);
            $table->text('benefits')->nullable();
            $table->string('price_label', 60)->nullable();
            $table->unsignedSmallInteger('duration_days');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['business_id', 'is_active']);
        });

        Schema::create('memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('membership_plans')->cascadeOnDelete();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('code', 20)->unique();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->enum('status', ['active', 'cancelled'])->default('active');
            $table->timestamps();

            $table->unique(['plan_id', 'user_id']);
            $table->index(['business_id', 'user_id', 'status', 'ends_at']);
        });

        Schema::table('coupons', function (Blueprint $table) {
            $table->boolean('members_only')->default(false)->after('is_flash');
        });
    }

    public function down(): void
    {
        Schema::table('coupons', fn (Blueprint $table) => $table->dropColumn('members_only'));
        Schema::dropIfExists('memberships');
        Schema::dropIfExists('membership_plans');
        Schema::dropIfExists('gift_certificate_uses');
        Schema::dropIfExists('gift_certificates');
        Schema::table('businesses', fn (Blueprint $table) => $table->dropColumn('sells_gift_certificates'));
    }
};
