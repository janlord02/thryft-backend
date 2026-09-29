<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Give coupons a public URL segment, for /b/{business}/deals/{coupon}.
 *
 * Globally unique rather than unique-per-business: business_id is still
 * nullable during the extraction rollout, so a composite unique key would be
 * unenforceable for exactly the rows most likely to need it.
 *
 * The id suffix also stops a coupon title from leaking a guessable URL space —
 * "/deals/50-off" would be trivially enumerable across every business.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            $table->string('slug')->nullable()->unique()->after('code');
        });

        DB::table('coupons')->select('id', 'title')->orderBy('id')->chunk(200, function ($coupons) {
            foreach ($coupons as $coupon) {
                DB::table('coupons')->where('id', $coupon->id)->update([
                    'slug' => static::slugFor($coupon->title, $coupon->id),
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            $table->dropUnique(['slug']);
            $table->dropColumn('slug');
        });
    }

    public static function slugFor(?string $title, int $id): string
    {
        $base = Str::limit(Str::slug((string) $title), 60, '');

        return ($base !== '' ? $base : 'deal') . '-' . $id;
    }
};
