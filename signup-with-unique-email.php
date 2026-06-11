<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

use App\Core\SupabaseClient;

echo "=== Signup with Unique Email ===\n\n";

// Create unique email using current timestamp
$timestamp = time();
$uniqueEmail = "user" . $timestamp . "@example.com";
$password = "SecurePassword" . $timestamp;

echo "Email: " . $uniqueEmail . "\n";
echo "Password: " . $password . "\n";
echo "\n--- Attempting Sign Up ---\n";

try {
    $sb = SupabaseClient::getInstance();
    
    $result = $sb->authSignUp($uniqueEmail, $password);
    
    if ($result) {
        if (isset($result['error']) && $result['error']) {
            echo " Sign up failed: " . ($result['message'] ?? 'Unknown error') . "\n";
            echo "Details: " . json_encode($result, JSON_PRETTY_PRINT) . "\n";
        } else {
            echo " Sign up successful!\n\n";
            echo "=== Login Credentials ===\n";
            echo "Email:    " . $uniqueEmail . "\n";
            echo "Password: " . $password . "\n";
            echo "\nFull Response:\n";
            echo json_encode($result, JSON_PRETTY_PRINT) . "\n";
            
            // If the response contains user session info
            if (isset($result['session'])) {
                echo "\n=== Session Details ===\n";
                echo "Access Token: " . (isset($result['session']['access_token']) ? substr($result['session']['access_token'], 0, 20) . "..." : "N/A") . "\n";
                echo "User ID: " . ($result['session']['user']['id'] ?? "N/A") . "\n";
            }
        }
    } else {
        echo " Sign up returned null\n";
    }
} catch (\Throwable $e) {
    echo " Exception occurred: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
}
?>
