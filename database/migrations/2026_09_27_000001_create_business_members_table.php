<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who may act for a business, and in what capacity.
 *
 * users.role is a single string with three values, so today the only way to
 * give an employee access is to share the owner's login. This table separates
 * "who you are" from "what you may do for which business", which is the
 * prerequisite for staff accounts, multi-location management and eventually
 * transferring a business between owners.
 *
 * users.role is NOT changed here. It narrows to a platform role
 * (user | super-admin) only once every read path has moved over; until then
 * role === 'business' remains a valid fallback and the middleware honours it.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('business_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('role', ['owner', 'admin', 'manager', 'staff'])->default('staff');
            // Per-member overrides on top of the role's ability set. Null means
            // "just use the role", which is the normal case.
            $table->json('permissions')->nullable();
            $table->enum('status', ['invited', 'active', 'revoked'])->default('invited');
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            // One membership row per person per business.
            $table->unique(['business_id', 'user_id'], 'business_members_business_user_unique');
            $table->index(['user_id', 'status']);
        });

        $this->backfillOwners();
    }

    public function down(): void
    {
        Schema::dropIfExists('business_members');
    }

    /**
     * Every existing business owner becomes an active owner-member, so
     * membership can be the primary resolution path from day one rather than
     * something only new businesses have.
     */
    private function backfillOwners(): void
    {
        $now = now();

        DB::table('businesses')
            ->whereNotNull('owner_user_id')
            ->orderBy('id')
            ->chunk(200, function ($businesses) use ($now) {
                $rows = [];

                foreach ($businesses as $business) {
                    $rows[] = [
                        'business_id' => $business->id,
                        'user_id' => $business->owner_user_id,
                        'role' => 'owner',
                        'permissions' => null,
                        'status' => 'active',
                        'invited_at' => $business->created_at ?? $now,
                        'accepted_at' => $business->created_at ?? $now,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if ($rows) {
                    DB::table('business_members')->insert($rows);
                }
            });
    }
};
