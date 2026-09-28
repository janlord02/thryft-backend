<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Step 2: one business row per existing business user, with businesses.id
 * seeded to equal users.id.
 *
 * That equality is the whole trick. Every FK already pointing at a business
 * user — products.user_id, coupons.user_id, claimed_coupons.business_id — is
 * then ALREADY a valid businesses.id, so step 3's data migration is a straight
 * copy rather than a remap, and any code path missed during the transition
 * still resolves to the right row instead of silently pointing somewhere else.
 *
 * AUTO_INCREMENT is then pushed past the users range so that every business
 * created from here on gets an id that obviously is NOT a user id. If some
 * stale code path still conflates the two, it will fail loudly on a missing
 * row rather than quietly reading the wrong business.
 */
return new class extends Migration {
    private const ID_FLOOR = 1000000;

    public function up(): void
    {
        foreach ($this->businessUsers() as $user) {
            $name = $user->business_name ?: ($user->name ?: 'Business ' . $user->id);

            DB::table('businesses')->insert([
                'id' => $user->id,                       // deliberate: see class docblock
                'owner_user_id' => $user->id,
                'slug' => $this->uniqueSlug($name, $user->id),
                'name' => $name,
                'description' => $user->business_description ?? null,
                'phone' => $user->phone ?? null,
                // NOT $user->email. businesses.email is a PUBLISHED contact
                // address, surfaced to anonymous callers by
                // PublicBusinessResource; users.email is the account login.
                // Copying it here would republish every existing owner's login
                // on the public API — the exact leak the resource layer exists
                // to prevent. Merchants fill this in themselves.
                'email' => null,
                'status' => 'active',
                'created_at' => $user->created_at ?? now(),
                'updated_at' => now(),
            ]);

            // One primary location carrying whatever address the user row had.
            DB::table('business_locations')->insert([
                'business_id' => $user->id,
                'label' => 'Main',
                'address' => $user->address ?? null,
                'city' => $user->city ?? null,
                'state' => $user->state ?? null,
                'zipcode' => $user->zipcode ?? null,
                'country' => $user->country ?? null,
                'latitude' => $user->latitude ?? null,
                'longitude' => $user->longitude ?? null,
                'is_primary' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->raiseAutoIncrementFloor();
    }

    public function down(): void
    {
        // business_locations cascades from businesses.
        DB::table('business_locations')->delete();
        DB::table('businesses')->delete();
    }

    /**
     * Every user who needs a business row.
     *
     * Deliberately WIDER than role = 'business'. claimed_coupons.business_id
     * has its foreign key repointed at businesses in the next migration, and
     * products/coupons get a business_id copied from user_id. If any of those
     * reference a user whose role is no longer 'business' — a demoted account,
     * an admin who once created a coupon — a role-only backfill would leave
     * them without a business row and the foreign key would fail to apply, or
     * their records would be orphaned.
     *
     * @return \Illuminate\Support\Collection
     */
    private function businessUsers()
    {
        $ids = DB::table('users')->where('role', 'business')->pluck('id')
            ->merge(DB::table('products')->distinct()->pluck('user_id'))
            ->merge(DB::table('coupons')->distinct()->pluck('user_id'))
            ->merge(DB::table('claimed_coupons')->distinct()->pluck('business_id'))
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        // Only users that actually still exist — a pluck can surface an id whose
        // row was hard-deleted without cascading.
        return DB::table('users')->whereIn('id', $ids)->orderBy('id')->get();
    }

    /**
     * businesses.slug is unique and will become a public URL segment in Phase 4.
     * Suffix with the id rather than probing for collisions: deterministic,
     * single-pass, and guaranteed unique because ids are.
     */
    private function uniqueSlug(string $name, int $id): string
    {
        $base = Str::slug($name);

        if ($base === '') {
            $base = 'business';
        }

        return Str::limit($base, 80, '') . '-' . $id;
    }

    /**
     * MySQL/MariaDB only. SQLite assigns max(id)+1 on its own, and the test
     * suite starts from an empty table, so the distinction does not arise there.
     */
    private function raiseAutoIncrementFloor(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        $maxId = (int) DB::table('businesses')->max('id');
        $floor = max(self::ID_FLOOR, $maxId + 1);

        DB::statement("ALTER TABLE businesses AUTO_INCREMENT = {$floor}");
    }
};
