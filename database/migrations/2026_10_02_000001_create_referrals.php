<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Referrals: every business gets a permanent code, and a signup that arrives
 * with it is attributed automatically instead of being reported by hand.
 *
 * One row per referred account (unique on referred_user_id), so a person can
 * only ever be someone's referral once, whichever link they clicked last.
 * Status moves pending → qualified on its own (a shopper's first redemption,
 * a business's first paid subscription) and → rewarded by an admin once the
 * credit has been applied. 'void' is for abuse or refunds.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->string('referral_code', 12)->nullable()->unique()->after('slug');
        });

        // Existing businesses get a code now; new ones get one on creation.
        DB::table('businesses')->whereNull('referral_code')->orderBy('id')->chunkById(500, function ($rows) {
            foreach ($rows as $row) {
                $base = Str::upper(Str::limit(preg_replace('/[^A-Z0-9]/', '', Str::upper($row->name ?? '')), 6, ''));
                do {
                    $code = ($base ?: 'THRYFT') . Str::upper(Str::random(4));
                } while (DB::table('businesses')->where('referral_code', $code)->exists());

                DB::table('businesses')->where('id', $row->id)->update(['referral_code' => $code]);
            }
        });

        Schema::create('referrals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('referred_user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->enum('kind', ['shopper', 'business']);
            $table->enum('status', ['pending', 'qualified', 'rewarded', 'void'])->default('pending');
            $table->timestamp('qualified_at')->nullable();
            $table->timestamp('rewarded_at')->nullable();
            $table->string('reward_note')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referrals');

        Schema::table('businesses', function (Blueprint $table) {
            $table->dropUnique(['referral_code']);
            $table->dropColumn('referral_code');
        });
    }
};
