<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * starts_at is stored in UTC. Without the host's zone, the server-rendered
 * page could only print UTC — a 6:30 PM event in Illinois read 11:30 PM.
 * Null means the app timezone, which is what every existing row was shown in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->string('timezone', 64)->nullable()->after('ends_at');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('timezone');
        });
    }
};
