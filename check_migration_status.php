<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Run this to check migration status
echo "=== Migration Status Check ===\n";

// Check migration table
try {
    $migrations = DB::table('migrations')->orderBy('batch', 'desc')->get();
    echo "Total migrations run: " . $migrations->count() . "\n";

    echo "\n--- Recent Migrations ---\n";
    foreach ($migrations->take(10) as $migration) {
        echo "- {$migration->migration} (batch: {$migration->batch})\n";
    }

} catch (Exception $e) {
    echo "Error checking migrations: " . $e->getMessage() . "\n";
}

// Check specific tables
$tables = ['product_favorites', 'coupons', 'products', 'users'];
echo "\n--- Table Status ---\n";
foreach ($tables as $table) {
    if (Schema::hasTable($table)) {
        $count = DB::table($table)->count();
        echo "✓ {$table}: exists ({$count} records)\n";
    } else {
        echo "✗ {$table}: missing\n";
    }
}

echo "\n=== Check Complete ===\n";
