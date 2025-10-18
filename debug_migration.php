<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Add this to a temporary route or run as a standalone script
// This will help identify the exact issue

echo "=== Migration Conflict Analysis ===\n";

// Check if product_favorites table exists
if (Schema::hasTable('product_favorites')) {
    echo "✓ product_favorites table exists\n";

    $connection = DB::connection();
    $tableName = 'product_favorites';
    $databaseName = $connection->getDatabaseName();

    // Get all constraints
    $constraints = $connection->select("
        SELECT CONSTRAINT_NAME, CONSTRAINT_TYPE
        FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
        WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
    ", [$databaseName, $tableName]);

    echo "\n--- Existing Constraints ---\n";
    foreach ($constraints as $constraint) {
        echo "- {$constraint->CONSTRAINT_NAME} ({$constraint->CONSTRAINT_TYPE})\n";
    }

    // Get all indexes
    $indexes = $connection->select("
        SELECT INDEX_NAME
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
        GROUP BY INDEX_NAME
    ", [$databaseName, $tableName]);

    echo "\n--- Existing Indexes ---\n";
    foreach ($indexes as $index) {
        echo "- {$index->INDEX_NAME}\n";
    }

    // Check for potential conflicts
    echo "\n--- Potential Conflicts ---\n";
    $constraintNames = array_column($constraints, 'CONSTRAINT_NAME');
    $indexNames = array_column($indexes, 'INDEX_NAME');

    // Look for duplicate unique constraints
    $uniqueConstraints = array_filter($constraintNames, function ($name) {
        return str_contains($name, 'user_id') && str_contains($name, 'product_id');
    });

    if (count($uniqueConstraints) > 1) {
        echo "⚠️  Multiple unique constraints found:\n";
        foreach ($uniqueConstraints as $constraint) {
            echo "   - {$constraint}\n";
        }
    }

    // Look for duplicate indexes
    $userIndexes = array_filter($indexNames, function ($name) {
        return str_contains($name, 'user_id') && str_contains($name, 'created_at');
    });

    if (count($userIndexes) > 1) {
        echo "⚠️  Multiple user_id indexes found:\n";
        foreach ($userIndexes as $index) {
            echo "   - {$index}\n";
        }
    }

    $productIndexes = array_filter($indexNames, function ($name) {
        return str_contains($name, 'product_id') && str_contains($name, 'created_at');
    });

    if (count($productIndexes) > 1) {
        echo "⚠️  Multiple product_id indexes found:\n";
        foreach ($productIndexes as $index) {
            echo "   - {$index}\n";
        }
    }

} else {
    echo "✗ product_favorites table does not exist\n";
}

echo "\n=== Analysis Complete ===\n";
