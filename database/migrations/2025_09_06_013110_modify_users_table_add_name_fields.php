<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Make name column nullable
            $table->string('name')->nullable()->change();

            // Add firstname and lastname columns
            $table->string('firstname')->nullable()->after('name');
            $table->string('lastname')->nullable()->after('firstname');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Remove firstname and lastname columns
            $table->dropColumn(['firstname', 'lastname']);

            // Make name column not nullable again
            $table->string('name')->nullable(false)->change();
        });
    }
};
