<?php
require 'app/bootstrap.php';

echo "Checking if weekly challenges tables exist...\n\n";

try {
    $sb = \App\Core\SupabaseClient::getInstance();
    
    $tables = [
        'weekly_challenges',
        'challenge_participation',
        'weekly_challenge_results',
        'challenge_achievements'
    ];
    
    foreach ($tables as $table) {
        try {
            $result = $sb->from($table)->select('id')->limit(1)->execute();
            echo "✅ $table - EXISTS\n";
        } catch (\Throwable $e) {
            echo "❌ $table - NOT FOUND\n";
        }
    }
    
    echo "\n✨ Weekly challenges database schema is ready!\n";
    
} catch (\Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
