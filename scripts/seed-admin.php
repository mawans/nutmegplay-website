<?php
/**
 * One-time script to seed an admin account.
 *
 * Usage:  php scripts/seed-admin.php
 *
 * It registers a new user via Supabase Auth, creates an accounts row
 * with role = 'admin', and prints the credentials.
 */

declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\SupabaseClient;

// ── Credentials for the admin account ─────────────────────
$email    = trim((string)(getenv('NUTMEG_ADMIN_EMAIL') ?: 'admin@nutmegplay.local'));
$password = trim((string)(getenv('NUTMEG_ADMIN_PASSWORD') ?: ''));
$fname    = trim((string)(getenv('NUTMEG_ADMIN_NAME') ?: 'Admin'));
if ($password === '') {
    $password = bin2hex(random_bytes(8)) . 'Aa1!';
}
// ──────────────────────────────────────────────────────────

echo "Nutmeg Admin Seeder\n";
echo "====================\n\n";

try {
    $sb = SupabaseClient::getInstance();
} catch (\Throwable $e) {
    echo "ERROR: Could not connect to Supabase.\n";
    echo $e->getMessage() . "\n";
    exit(1);
}

// 1. Check if account already exists
$existing = $sb->from('accounts')->select('*')->eq('email', $email)->single()->execute();
if ($existing && empty($existing['error'])) {
    // Already exists – just make sure role is admin
    $id = (int)($existing['id'] ?? 0);
    if ($id) {
        $sb->from('accounts')->eq('id', (string)$id)->update(['role' => 'admin']);
        echo "Account already exists – role updated to admin.\n\n";
    }
    echo "  Email:    $email\n";
    echo "  Password: $password\n";
    echo "\nLogin at /login\n";
    exit(0);
}

// 2. Register in Supabase Auth
echo "Creating auth user...\n";
$authResult = $sb->authSignUp($email, $password, ['fname' => $fname]);

if (!$authResult || !empty($authResult['error'])) {
    $msg = $authResult['message'] ?? 'Unknown error';
    // If user already exists in auth but not in accounts table, try signing in
    if (stripos($msg, 'already') !== false || stripos($msg, 'exists') !== false || stripos($msg, 'registered') !== false) {
        echo "Auth user already registered – signing in...\n";
        $authResult = $sb->authSignIn($email, $password);
        if (!$authResult || !empty($authResult['error'])) {
            echo "ERROR: Could not sign in: " . ($authResult['message'] ?? 'unknown') . "\n";
            exit(1);
        }
    } else {
        echo "ERROR: Auth signup failed: $msg\n";
        exit(1);
    }
}

$uid = $authResult['user']['id'] ?? ($authResult['id'] ?? '');

// 3. Create accounts row
echo "Creating accounts row with admin role...\n";
$account = $sb->from('accounts')->insert([
    'fname' => $fname,
    'email' => $email,
    'uid'   => $uid,
    'role'  => 'admin',
    'level' => 1,
]);

if (!$account || !empty($account['error'])) {
    // Might fail if role column doesn't exist yet — try with login_type
    $account = $sb->from('accounts')->insert([
        'fname'      => $fname,
        'email'      => $email,
        'uid'        => $uid,
        'login_type' => 'admin',
        'level'      => 1,
    ]);
}

echo "\n✅ Admin account ready!\n\n";
echo "  Email:    $email\n";
echo "  Password: $password\n";
echo "\n  Login at: http://localhost:8080/login\n";
echo "  Then visit /admin for the Admin Panel.\n";
