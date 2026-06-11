<?php
/**
 * Recreate / repair the admin account.
 *
 * Handles all four states:
 *   - accounts row exists, auth.users row exists  -> update password, ensure uid linked + role=admin
 *   - accounts row exists, auth.users row missing -> create auth user, relink accounts.uid
 *   - accounts row missing, auth.users row exists -> reset password, insert accounts row
 *   - both missing                                -> create both
 *
 * Env overrides:
 *   NUTMEG_ADMIN_EMAIL    (default: admin@nutmegplay.fr)
 *   NUTMEG_ADMIN_PASSWORD (default: random strong password, printed at end)
 *   NUTMEG_ADMIN_NAME     (default: Admin)
 *
 * Usage:  php website/scripts/recreate-admin.php
 */

declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\SupabaseClient;

$email    = trim((string)(getenv('NUTMEG_ADMIN_EMAIL') ?: 'admin@nutmegplay.fr'));
$password = trim((string)(getenv('NUTMEG_ADMIN_PASSWORD') ?: ''));
$fname    = trim((string)(getenv('NUTMEG_ADMIN_NAME') ?: 'Admin'));
if ($password === '') {
    $password = bin2hex(random_bytes(8)) . 'Aa1!';
}

$sb = SupabaseClient::getInstance();
if (!$sb->hasServiceRoleKey()) {
    fwrite(STDERR, "ERROR: SUPABASE_SERVICE_ROLE_KEY not set in env.\n");
    exit(1);
}

$baseUrl = rtrim((string)getenv('SUPABASE_URL'), '/');
$srk     = (string)getenv('SUPABASE_SERVICE_ROLE_KEY');

if ($baseUrl === '' || $srk === '') {
    fwrite(STDERR, "ERROR: SUPABASE_URL or SUPABASE_SERVICE_ROLE_KEY missing.\n");
    exit(1);
}

function adminApi(string $method, string $url, string $srk, ?array $body = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => [
            'apikey: ' . $srk,
            'Authorization: Bearer ' . $srk,
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT        => 20,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return [
        'status' => $code,
        'body'   => is_string($resp) && $resp !== '' ? json_decode($resp, true) : null,
        'error'  => $err,
    ];
}

echo "Recreate admin: $email\n";
echo "============================================\n\n";

// ── 1. Look up existing auth user by email ──────────────────────────
echo "[1/3] Checking Supabase auth.users...\n";
$listResp = adminApi('GET', $baseUrl . '/auth/v1/admin/users?per_page=1000', $srk);
if ($listResp['status'] !== 200) {
    fwrite(STDERR, "ERROR: list users failed (status {$listResp['status']}): " . json_encode($listResp['body']) . "\n");
    if ($listResp['error']) fwrite(STDERR, "       curl error: {$listResp['error']}\n");
    exit(1);
}
$existingAuthUser = null;
foreach (($listResp['body']['users'] ?? []) as $u) {
    if (strcasecmp((string)($u['email'] ?? ''), $email) === 0) {
        $existingAuthUser = $u;
        break;
    }
}

if ($existingAuthUser) {
    $authUid = (string)$existingAuthUser['id'];
    echo "      Found auth user id=$authUid; resetting password.\n";
    $upd = adminApi('PUT', $baseUrl . '/auth/v1/admin/users/' . $authUid, $srk, [
        'password'      => $password,
        'email_confirm' => true,
    ]);
    if ($upd['status'] < 200 || $upd['status'] >= 300) {
        fwrite(STDERR, "ERROR: password reset failed (status {$upd['status']}): " . json_encode($upd['body']) . "\n");
        exit(1);
    }
    echo "      Password reset.\n";
} else {
    echo "      No auth user for $email; creating one.\n";
    $create = $sb->authAdminCreateUser($email, $password, ['fname' => $fname], true);
    if (!$create || !empty($create['error'])) {
        fwrite(STDERR, "ERROR: authAdminCreateUser failed: " . json_encode($create) . "\n");
        exit(1);
    }
    $authUid = (string)($create['id'] ?? ($create['user']['id'] ?? ''));
    if ($authUid === '') {
        fwrite(STDERR, "ERROR: created user but could not parse id. Response: " . json_encode($create) . "\n");
        exit(1);
    }
    echo "      Created auth user id=$authUid.\n";
}

// ── 2. Reconcile accounts row ───────────────────────────────────────
echo "\n[2/3] Reconciling accounts row...\n";
$existing = $sb->from('accounts')->select('*')->eq('email', $email)->single()->execute();
$existingId = (is_array($existing) && empty($existing['error'])) ? (int)($existing['id'] ?? 0) : 0;

if ($existingId > 0) {
    $oldUid = (string)($existing['uid'] ?? '');
    $oldRole = (string)($existing['role'] ?? '');
    echo "      Found accounts row id=$existingId (uid=$oldUid, role=$oldRole); updating.\n";
    $sb->from('accounts')->eq('id', (string)$existingId)->update([
        'uid'  => $authUid,
        'role' => 'admin',
    ]);
} else {
    echo "      No accounts row; inserting fresh admin row.\n";
    $sb->from('accounts')->insert([
        'fname' => $fname,
        'email' => $email,
        'uid'   => $authUid,
        'role'  => 'admin',
        'level' => 1,
    ]);
}

// ── 3. Verify by signing in ─────────────────────────────────────────
echo "\n[3/3] Verifying login via Supabase auth...\n";
$signin = $sb->authSignIn($email, $password);
if (!$signin || !empty($signin['error'])) {
    fwrite(STDERR, "WARNING: sign-in verification failed: " . json_encode($signin) . "\n");
    fwrite(STDERR, "         User was created but the password did not authenticate. Check Supabase project settings.\n");
} else {
    echo "      Sign-in OK.\n";
}

echo "\n============================================\n";
echo "Admin account ready.\n\n";
echo "  Email:    $email\n";
echo "  Password: $password\n";
echo "  Auth UID: $authUid\n\n";
echo "Login at /login then visit /admin\n";
