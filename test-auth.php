<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

use App\Core\SupabaseClient;

echo "=== Testing Supabase Authentication ===\n\n";

echo "1. Checking environment variables...\n";
echo "   SUPABASE_URL: " . getenv('SUPABASE_URL') . "\n";
echo "   SUPABASE_KEY: " . (strlen(getenv('SUPABASE_KEY') ?? '') > 0 ? " Set" : " Not set") . "\n";

echo "\n2. Instantiating SupabaseClient...\n";
try {
    $sb = SupabaseClient::getInstance();
    echo "    SupabaseClient created successfully\n";
} catch (\Throwable $e) {
    echo "    Error: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\n3. Testing sign up (testuser2026@example.com)...\n";
try {
    $result = $sb->authSignUp('testuser2026@example.com', 'TestPassword123');
    if ($result) {
        if (isset($result['error']) && $result['error']) {
            echo "    Sign up failed: " . ($result['message'] ?? 'Unknown error') . "\n";
            echo "   Response: " . json_encode($result) . "\n";
        } else {
            echo "    Sign up successful\n";
            echo "   Response: " . json_encode($result) . "\n";
        }
    } else {
        echo "    Sign up returned null\n";
    }
} catch (\Throwable $e) {
    echo "    Exception: " . $e->getMessage() . "\n";
}

echo "\n4. Testing sign up (admin@nutmegplay.co)...\n";
try {
    $result = $sb->authSignUp('admin@nutmegplay.co', 'AdminPassword123');
    if ($result) {
        if (isset($result['error']) && $result['error']) {
            echo "    Sign up failed: " . ($result['message'] ?? 'Unknown error') . "\n";
            if (strpos(($result['message'] ?? ''), 'rate') !== false) {
                echo "    Rate limit detected. Attempting sign in instead...\n";
                
                echo "\n5. Testing sign in (admin@nutmegplay.co / admin123)...\n";
                try {
                    $signin = $sb->authSignIn('admin@nutmegplay.co', 'admin123');
                    if ($signin) {
                        if (isset($signin['error']) && $signin['error']) {
                            echo "    Sign in failed: " . ($signin['message'] ?? 'Unknown error') . "\n";
                            echo "   Response: " . json_encode($signin) . "\n";
                        } else {
                            echo "    Sign in successful\n";
                            echo "   Response: " . json_encode($signin) . "\n";
                        }
                    } else {
                        echo "    Sign in returned null\n";
                    }
                } catch (\Throwable $e2) {
                    echo "    Sign in exception: " . $e2->getMessage() . "\n";
                }
            }
        } else {
            echo "    Sign up successful\n";
            echo "   Response: " . json_encode($result) . "\n";
        }
    } else {
        echo "    Sign up returned null\n";
    }
} catch (\Throwable $e) {
    echo "    Exception: " . $e->getMessage() . "\n";
}
