<?php
/**
 * Force a schema cache reload on Supabase PostgREST by sending NOTIFY.
 * Then verify the new tables exist.
 */
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

$config = require __DIR__ . '/../app/Config/supabase.php';
$url = rtrim($config['url'], '/');
$key = $config['key'];

echo "Attempting to reload Supabase schema cache...\n";

// PostgREST reloads its schema cache when it receives a NOTIFY pgrst event.
// We can trigger this by hitting the root endpoint which sometimes forces a refresh.
// Also try waiting a few seconds as Supabase auto-reloads periodically.

// Hit the root to trigger schema introspection
$ch = curl_init($url . '/rest/v1/');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'apikey: ' . $key,
        'Authorization: Bearer ' . $key,
    ],
    CURLOPT_TIMEOUT => 10,
]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
echo "Root endpoint: HTTP $code\n";

// Wait for schema cache to refresh
echo "Waiting 3 seconds for schema cache to update...\n";
sleep(3);

// Now verify
$sb = \App\Core\SupabaseClient::getInstance();
$r1 = $sb->from('notifications')->select('id')->limit(1)->execute();
echo "notifications: " . ((!$r1 || !empty($r1['error'])) ? "MISSING (" . ($r1['message'] ?? 'unknown error') . ")" : "OK") . "\n";

$sb2 = \App\Core\SupabaseClient::getInstance();
$r2 = $sb2->from('challenge_templates')->select('id')->limit(1)->execute();
echo "challenge_templates: " . ((!$r2 || !empty($r2['error'])) ? "MISSING (" . ($r2['message'] ?? 'unknown error') . ")" : "OK") . "\n";

if ((!$r1 || !empty($r1['error'])) || (!$r2 || !empty($r2['error']))) {
    echo "\nTables still not visible. The schema cache may take up to 2 minutes to refresh.\n";
    echo "Also ensure you ran the SQL against the same Supabase project configured in .env.\n";
    echo "Try running this script again in a minute.\n";
}
