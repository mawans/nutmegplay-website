<?php
/**
 * Database Migration Runner
 * Prepares and guides execution of SQL migrations against Supabase
 * Usage: php run-migrations.php
 */

require 'app/bootstrap.php';

echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║      FiveStats Database Migration Runner                      ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

try {
    $sb = \App\Core\SupabaseClient::getInstance();

    echo "[1/2] Connecting to Supabase...\n";
    // Test connection
    try {
        $test = $sb->from('accounts')->select('id')->limit(1)->execute();
        echo "      ✓ Connection successful\n\n";
    } catch (\Throwable $e) {
        throw new \Exception("Cannot connect to Supabase: " . $e->getMessage());
    }

    // Get list of migration files
    echo "[2/2] Preparing migrations...\n";
    $migrationsDir = BASE_PATH . '/database/migrations';
    $migrationFiles = glob($migrationsDir . '/*.sql');
    $migrationFiles = array_map(fn($f) => basename($f), $migrationFiles);
    sort($migrationFiles);

    echo "      Found " . count($migrationFiles) . " migration file(s)\n\n";

    if (empty($migrationFiles)) {
        echo "No migrations found.\n";
        exit(0);
    }

    // For each migration, display SQL and instructions
    foreach ($migrationFiles as $migrationFile) {
        $migrationPath = $migrationsDir . '/' . $migrationFile;
        $sql = file_get_contents($migrationPath);

        echo "╔════════════════════════════════════════════════════════════════╗\n";
        echo "║ Migration: $migrationFile\n";
        echo "╚════════════════════════════════════════════════════════════════╝\n\n";

        echo "SQL to execute:\n";
        echo "───────────────────────────────────────────────────────────────\n";
        echo $sql;
        echo "\n───────────────────────────────────────────────────────────────\n\n";

        echo "📋 INSTRUCTIONS:\n";
        echo "   1. Go to https://supabase.com → Your Project → SQL Editor\n";
        echo "   2. Click \"New Query\"\n";
        echo "   3. Copy and paste the SQL above\n";
        echo "   4. Click \"Run\"\n";
        echo "   5. Confirm all tables were created successfully\n\n";
    }

    echo "╔════════════════════════════════════════════════════════════════╗\n";
    echo "║                    NEXT STEPS                                  ║\n";
    echo "╚════════════════════════════════════════════════════════════════╝\n";
    echo "✅ Migration SQL prepared above\n";
    echo "📍 Execute in Supabase SQL Editor (link above)\n";
    echo "🔍 Verify by checking tables appear in Supabase Table Editor\n";
    echo "🚀 Weekly Challenges will then be fully functional\n\n";

} catch (\Throwable $e) {
    echo "❌ Error: " . $e->getMessage() . "\n\n";
    exit(1);
}
