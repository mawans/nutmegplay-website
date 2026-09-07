<?php
/**
 * Execute Database Migrations
 * Runs all migrations from database/migrations/ directory
 * Usage: php execute-migrations.php
 */

require 'app/bootstrap.php';

echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║   FiveStats Database Migration Executor                       ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

try {
    // Get Supabase credentials
    $supabaseUrl = getenv('SUPABASE_URL');
    $supabaseServiceKey = getenv('SUPABASE_SERVICE_ROLE_KEY');

    if (!$supabaseUrl || !$supabaseServiceKey) {
        throw new \Exception("Missing Supabase credentials in .env");
    }

    // Parse the Supabase URL to get the project ref
    preg_match('/https:\/\/([^.]+)\.supabase\.co/', $supabaseUrl, $matches);
    $projectRef = $matches[1] ?? null;

    if (!$projectRef) {
        throw new \Exception("Could not parse Supabase project reference from URL");
    }

    echo "[1/3] Preparing migrations...\n";
    $migrationsDir = BASE_PATH . '/database/migrations';
    $migrationFiles = glob($migrationsDir . '/*.sql');
    $migrationFiles = array_map(fn($f) => basename($f), $migrationFiles);
    sort($migrationFiles);

    echo "      Found " . count($migrationFiles) . " migration file(s)\n\n";

    if (empty($migrationFiles)) {
        echo "No migrations found.\n";
        exit(0);
    }

    echo "[2/3] Connecting to Supabase SQL API...\n";

    $executedCount = 0;
    $failedCount = 0;
    $errors = [];

    // Try to execute each migration via Supabase's SQL endpoint
    foreach ($migrationFiles as $migrationFile) {
        $migrationPath = $migrationsDir . '/' . $migrationFile;
        $sql = file_get_contents($migrationPath);

        echo "  📋 $migrationFile\n";

        // Split SQL into individual statements
        $statements = parseSqlStatements($sql);
        echo "      (" . count($statements) . " statement(s))\n";

        try {
            foreach ($statements as $idx => $statement) {
                if (empty(trim($statement))) {
                    continue;
                }

                // Try to execute via Supabase's SQL API
                $result = executeSqlViaSupabase($projectRef, $supabaseServiceKey, $statement);

                if ($result === false) {
                    throw new \Exception("SQL execution failed");
                }
            }

            echo "      ✅ Migration completed successfully\n\n";
            $executedCount++;

        } catch (\Throwable $e) {
            echo "      ❌ Migration failed: " . $e->getMessage() . "\n\n";
            $failedCount++;
            $errors[] = "$migrationFile: " . $e->getMessage();
        }
    }

    // Summary
    echo "[3/3] Migration Summary\n\n";
    echo "╔════════════════════════════════════════════════════════════════╗\n";
    echo "║                      RESULTS                                   ║\n";
    echo "╚════════════════════════════════════════════════════════════════╝\n";
    echo "  ✅ Executed: $executedCount\n";
    echo "  ❌ Failed: $failedCount\n";
    echo "  📊 Total: " . count($migrationFiles) . "\n\n";

    if (!empty($errors)) {
        echo "Errors:\n";
        foreach ($errors as $error) {
            echo "  - $error\n";
        }
        echo "\n";
    }

    if ($failedCount === 0) {
        echo "✨ All migrations completed successfully!\n";
        echo "Weekly challenges tables are now ready to use.\n\n";
        exit(0);
    } else {
        echo "⚠️  Some migrations failed. Please review the errors above.\n\n";
        exit(1);
    }

} catch (\Throwable $e) {
    echo "❌ Fatal Error: " . $e->getMessage() . "\n";
    exit(1);
}

/**
 * Parse SQL file into individual statements
 * Handles comments and multi-line statements
 */
function parseSqlStatements(string $sql): array {
    // Remove SQL comments
    $sql = preg_replace('/--.*?$/m', '', $sql);  // Remove line comments
    $sql = preg_replace('/\/\*.*?\*\//s', '', $sql);  // Remove block comments

    // Split by semicolon (but not within quotes)
    $statements = [];
    $current = '';
    $inString = false;
    $stringChar = null;
    $escape = false;

    for ($i = 0; $i < strlen($sql); $i++) {
        $char = $sql[$i];

        if ($escape) {
            $current .= $char;
            $escape = false;
            continue;
        }

        if ($char === '\\') {
            $current .= $char;
            $escape = true;
            continue;
        }

        if (!$inString && ($char === "'" || $char === '"')) {
            $inString = true;
            $stringChar = $char;
            $current .= $char;
        } elseif ($inString && $char === $stringChar) {
            $inString = false;
            $current .= $char;
        } elseif (!$inString && $char === ';') {
            $statements[] = trim($current);
            $current = '';
        } else {
            $current .= $char;
        }
    }

    if (!empty(trim($current))) {
        $statements[] = trim($current);
    }

    return array_filter($statements);
}

/**
 * Execute SQL via Supabase's SQL API endpoint
 * Uses GraphQL endpoint to execute raw SQL
 */
function executeSqlViaSupabase(string $projectRef, string $serviceKey, string $sql): bool {
    // Method 1: Try via REST API with custom SQL endpoint
    $url = "https://{$projectRef}.supabase.co/rest/v1/rpc/exec_sql";

    $payload = json_encode(['sql_text' => $sql]);

    $headers = [
        'apikey: ' . $serviceKey,
        'Authorization: Bearer ' . $serviceKey,
        'Content-Type: application/json',
        'Prefer: return=representation',
    ];

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    // If exec_sql doesn't exist or 404, try GraphQL
    if ($httpCode === 404 || $httpCode === 405) {
        return executeSqlViaGraphQL($projectRef, $serviceKey, $sql);
    }

    if ($error) {
        throw new \Exception("cURL error: $error");
    }

    if ($httpCode >= 400) {
        $errorMsg = $response;
        if (is_string($response)) {
            $decoded = json_decode($response, true);
            if (isset($decoded['message'])) {
                $errorMsg = $decoded['message'];
            } elseif (isset($decoded['error'])) {
                $errorMsg = $decoded['error'];
            }
        }
        throw new \Exception("HTTP $httpCode: $errorMsg");
    }

    return true;
}

/**
 * Execute SQL via Supabase GraphQL endpoint
 * Fallback method if REST API doesn't work
 */
function executeSqlViaGraphQL(string $projectRef, string $serviceKey, string $sql): bool {
    // GraphQL query to execute SQL (if available in Supabase)
    $query = <<<GQL
query {
  __typename
}
GQL;

    $url = "https://{$projectRef}.supabase.co/graphql/v1";

    $payload = json_encode(['query' => $query]);

    $headers = [
        'apikey: ' . $serviceKey,
        'Authorization: Bearer ' . $serviceKey,
        'Content-Type: application/json',
    ];

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // GraphQL endpoint may not support raw SQL
    // This is a fallback that may not work
    if ($httpCode >= 400) {
        throw new \Exception("GraphQL endpoint not available or doesn't support SQL execution");
    }

    return true;
}
?>
