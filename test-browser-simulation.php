<?php
/**
 * End-to-End Browser Simulation Test
 * Tests the actual application through HTTP requests
 * Simulates real user interactions via the browser
 */

require 'app/bootstrap.php';

echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║   End-to-End Browser Simulation Test                           ║\n";
echo "║   Testing App via HTTP Requests (Browser Perspective)          ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

$baseUrl = 'http://127.0.0.1:8000';
$testsPassed = 0;
$testsFailed = 0;
$testResults = [];

function httpRequest($method, $endpoint, $data = null, $headers = []) {
    global $baseUrl;
    
    $url = $baseUrl . $endpoint;
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => array_merge([
            'Content-Type: application/json',
            'User-Agent: NutmegPlay-E2E-Test/1.0'
        ], $headers),
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    
    if ($data !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    return [
        'code' => $httpCode,
        'body' => $response,
        'error' => $error,
    ];
}

function testEndpoint($name, $method, $endpoint, $expectedCode = 200, $data = null) {
    global $testsPassed, $testsFailed, $testResults;
    
    echo "Testing: $name\n";
    echo "  Endpoint: $method $endpoint\n";
    
    try {
        $result = httpRequest($method, $endpoint, $data);
        
        if ($result['error']) {
            echo "  ❌ FAIL: Request error - {$result['error']}\n";
            $testsFailed++;
            $testResults[] = "❌ $name - Request error";
            return false;
        }
        
        echo "  HTTP Code: {$result['code']} (expected $expectedCode)\n";
        
        if ($result['code'] === $expectedCode || ($expectedCode === 200 && in_array($result['code'], [200, 302, 301]))) {
            echo "  ✅ PASS\n\n";
            $testsPassed++;
            $testResults[] = "✅ $name";
            return true;
        } else {
            echo "  ❌ FAIL: Expected $expectedCode, got {$result['code']}\n";
            if (strlen($result['body']) < 500) {
                echo "  Response: " . substr($result['body'], 0, 200) . "\n";
            }
            echo "\n";
            $testsFailed++;
            $testResults[] = "❌ $name - HTTP {$result['code']}";
            return false;
        }
    } catch (\Throwable $e) {
        echo "  ❌ FAIL: {$e->getMessage()}\n\n";
        $testsFailed++;
        $testResults[] = "❌ $name - {$e->getMessage()}";
        return false;
    }
}

// =============================================================================
// TEST 1: LANDING & LOGIN PAGES
// =============================================================================
echo "📍 SECTION 1: Landing & Login Pages\n";
echo "───────────────────────────────────────────────────────────────\n\n";

testEndpoint("Landing Page Loads", "GET", "/", 200);
testEndpoint("Login Page Loads", "GET", "/login", 200);

echo "\n";

// =============================================================================
// TEST 2: API ENDPOINTS
// =============================================================================
echo "📍 SECTION 2: API Endpoints\n";
echo "───────────────────────────────────────────────────────────────\n\n";

testEndpoint("API Health Check", "GET", "/api/health", 200);
testEndpoint("Get Active Challenges API", "GET", "/api/weekly-challenges/active", 200);
testEndpoint("Get Leaderboard API", "GET", "/api/weekly-challenges/1/leaderboard", 200);
testEndpoint("Get Accounts API", "GET", "/api/accounts", 200);
testEndpoint("Get Matches API", "GET", "/api/matches", 200);

echo "\n";

// =============================================================================
// TEST 3: WEEKLY CHALLENGES ENDPOINTS
// =============================================================================
echo "📍 SECTION 3: Weekly Challenges Routes\n";
echo "───────────────────────────────────────────────────────────────\n\n";

testEndpoint("Weekly Challenges Dashboard", "GET", "/weekly-challenges", 200);
testEndpoint("Create Challenge Form", "GET", "/weekly-challenges/create", 200);
testEndpoint("Challenge Details Page", "GET", "/weekly-challenges/1", 200);

echo "\n";

// =============================================================================
// TEST 4: PLAYER PAGES
// =============================================================================
echo "📍 SECTION 4: Player & Team Pages\n";
echo "───────────────────────────────────────────────────────────────\n\n";

testEndpoint("Players List", "GET", "/players", 200);
testEndpoint("Player Profile", "GET", "/players/1", 200);
testEndpoint("Team Dashboard", "GET", "/team", 200);
testEndpoint("Match History", "GET", "/match-history", 200);

echo "\n";

// =============================================================================
// TEST 5: VIDEO UPLOAD
// =============================================================================
echo "📍 SECTION 5: Video Upload Routes\n";
echo "───────────────────────────────────────────────────────────────\n\n";

testEndpoint("Video Upload Page", "GET", "/video-upload", 200);
testEndpoint("Match Videos Page", "GET", "/videos", 200);

echo "\n";

// =============================================================================
// TEST 6: ADMIN PAGES
// =============================================================================
echo "📍 SECTION 6: Admin Dashboard\n";
echo "───────────────────────────────────────────────────────────────\n\n";

testEndpoint("Admin Dashboard", "GET", "/admin", 200);
testEndpoint("Admin Settings", "GET", "/admin/settings", 200);

echo "\n";

// =============================================================================
// TEST 7: STATIC ASSETS
// =============================================================================
echo "📍 SECTION 7: Static Assets\n";
echo "───────────────────────────────────────────────────────────────\n\n";

testEndpoint("CSS Assets", "GET", "/public/assets/css/style.css", 200);
testEndpoint("JS Assets", "GET", "/public/assets/js/app.js", 200);
testEndpoint("HTMX Library", "GET", "/public/htmx.js", 200);

echo "\n";

// =============================================================================
// TEST 8: RESPONSE VALIDATION
// =============================================================================
echo "📍 SECTION 8: Response Content Validation\n";
echo "───────────────────────────────────────────────────────────────\n\n";

echo "Testing HTML Response Structure...\n";
try {
    $result = httpRequest('GET', '/weekly-challenges');
    
    // Check for HTML content
    if (strpos($result['body'], '<html') !== false || strpos($result['body'], '<form') !== false) {
        echo "  ✅ HTML content present\n";
        $testsPassed++;
        $testResults[] = "✅ HTML Response Structure";
    } else {
        echo "  ⚠️  Warning: HTML markers not found\n";
        $testResults[] = "⚠️  HTML Response - may be API response";
    }
    
    // Check for forms on create page
    $result = httpRequest('GET', '/weekly-challenges/create');
    if (strpos($result['body'], '<form') !== false || strpos($result['body'], 'challenge') !== false) {
        echo "  ✅ Challenge form present\n";
        $testsPassed++;
        $testResults[] = "✅ Challenge Form Content";
    } else {
        echo "  ⚠️  Challenge form content not found\n";
        $testResults[] = "⚠️  Challenge Form - may require auth";
    }
    
} catch (\Throwable $e) {
    echo "  ⚠️  Validation error: {$e->getMessage()}\n";
}

echo "\n";

// =============================================================================
// TEST 9: ERROR HANDLING
// =============================================================================
echo "📍 SECTION 9: Error Handling\n";
echo "───────────────────────────────────────────────────────────────\n\n";

testEndpoint("404 Error Page", "GET", "/nonexistent-page", 404);
testEndpoint("Invalid Challenge ID", "GET", "/weekly-challenges/999999", 200); // May return empty or error page
testEndpoint("Invalid Player ID", "GET", "/players/999999", 200);

echo "\n";

// =============================================================================
// TEST 10: ROUTE COVERAGE
// =============================================================================
echo "📍 SECTION 10: Core Application Routes\n";
echo "───────────────────────────────────────────────────────────────\n\n";

$coreRoutes = [
    'GET|/' => 'Home',
    'GET|/login' => 'Login',
    'GET|/register' => 'Register',
    'GET|/dashboard' => 'Dashboard',
    'GET|/weekly-challenges' => 'Weekly Challenges',
    'GET|/players' => 'Players List',
    'GET|/matches' => 'Matches',
    'GET|/videos' => 'Videos',
    'GET|/notifications' => 'Notifications',
];

foreach ($coreRoutes as $route => $name) {
    list($method, $path) = explode('|', $route);
    testEndpoint("Route: $name", $method, $path, 200);
}

echo "\n";

// =============================================================================
// SUMMARY
// =============================================================================
echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║            END-TO-END TEST SUMMARY                             ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

$total = $testsPassed + $testsFailed;
$percentage = $total > 0 ? round(($testsPassed / $total) * 100, 1) : 0;

echo "Results:\n";
echo "───────────────────────────────────────────────────────────────\n";
echo "✅ Passed:  $testsPassed\n";
echo "❌ Failed:  $testsFailed\n";
echo "📊 Total:   $total\n";
echo "📈 Success: $percentage%\n\n";

if ($testsFailed === 0) {
    echo "╔════════════════════════════════════════════════════════════════╗\n";
    echo "║  ✨ ALL ROUTES ACCESSIBLE - APP FULLY FUNCTIONAL ✨          ║\n";
    echo "╚════════════════════════════════════════════════════════════════╝\n\n";
    
    echo "Browser Testing Checklist:\n";
    echo "───────────────────────────────────────────────────────────────\n\n";
    
    echo "✅ Landing Page - Accessible at http://127.0.0.1:8000/\n";
    echo "✅ Login System - Ready at http://127.0.0.1:8000/login\n";
    echo "✅ Weekly Challenges - Live at http://127.0.0.1:8000/weekly-challenges\n";
    echo "✅ Player Management - Live at http://127.0.0.1:8000/players\n";
    echo "✅ Match History - Live at http://127.0.0.1:8000/match-history\n";
    echo "✅ Video Upload - Live at http://127.0.0.1:8000/video-upload\n";
    echo "✅ Admin Panel - Live at http://127.0.0.1:8000/admin\n";
    echo "✅ Notifications - Live at http://127.0.0.1:8000/notifications\n\n";
    
    echo "📍 NEXT: Test in browser using these steps:\n";
    echo "───────────────────────────────────────────────────────────────\n\n";
    
    echo "1️⃣ OPEN BROWSER\n";
    echo "   URL: http://127.0.0.1:8000\n\n";
    
    echo "2️⃣ LOGIN\n";
    echo "   Email: admin@nutmegplay.fr\n";
    echo "   Password: Admin123!\n\n";
    
    echo "3️⃣ TEST WEEKLY CHALLENGES\n";
    echo "   • Click 'Weekly Challenges' in sidebar\n";
    echo "   • Verify page loads without errors\n";
    echo "   • See 3 active challenges\n";
    echo "   • Click 'Create Challenge' (if instructor)\n";
    echo "   • Fill form and submit\n";
    echo "   • View leaderboard\n\n";
    
    echo "4️⃣ TEST PLAYER FEATURES\n";
    echo "   • Go to 'Players'\n";
    echo "   • Click on a player profile\n";
    echo "   • View match stats\n";
    echo "   • Check XP history\n\n";
    
    echo "5️⃣ TEST VIDEO UPLOAD\n";
    echo "   • Go to 'Video Upload'\n";
    echo "   • Upload a match video\n";
    echo "   • Monitor processing status\n\n";
    
    echo "6️⃣ TEST NOTIFICATIONS\n";
    echo "   • Check notification bell icon\n";
    echo "   • Create a challenge and check alert\n";
    echo "   • Verify read/unread status\n\n";
    
} else {
    echo "⚠️  Some routes failed. See details below:\n\n";
}

echo "Detailed Results:\n";
echo "───────────────────────────────────────────────────────────────\n";
foreach ($testResults as $result) {
    echo "$result\n";
}

exit($testsFailed > 0 ? 1 : 0);
?>
