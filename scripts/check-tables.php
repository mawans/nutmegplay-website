<?php
require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\SupabaseClient;

$sb = SupabaseClient::getInstance();

// Test notifications table
$result = $sb->from('notifications')->select('id')->limit(1)->execute();
if (is_array($result) && !empty($result['error'])) {
    echo "notifications table: MISSING\n";
    echo "Error: " . json_encode($result) . "\n";
} else {
    echo "notifications table: EXISTS\n";
}

// Test challenge_templates table
$result2 = $sb->from('challenge_templates')->select('id')->limit(1)->execute();
if (is_array($result2) && !empty($result2['error'])) {
    echo "challenge_templates table: MISSING\n";
    echo "Error: " . json_encode($result2) . "\n";
} else {
    echo "challenge_templates table: EXISTS\n";
}

// Test match_video_analysis table
$result3 = $sb->from('match_video_analysis')->select('id')->limit(1)->execute();
if (is_array($result3) && !empty($result3['error'])) {
    echo "match_video_analysis table: MISSING\n";
    echo "Error: " . json_encode($result3) . "\n";
} else {
    echo "match_video_analysis table: EXISTS\n";
}
