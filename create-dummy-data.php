<?php
/**
 * Create Dummy Challenge Data
 * Populates the database with realistic weekly challenge data
 */

require 'app/bootstrap.php';

echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║          Creating Dummy Challenge Data                         ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

$sb = \App\Core\SupabaseClient::getInstance();

// Get a few accounts for testing
echo "Getting test accounts...\n";
$accounts = $sb->from('accounts')
    ->select('id')
    ->limit(5)
    ->execute();

if (empty($accounts)) {
    echo "❌ No accounts found. Cannot create challenges without users.\n";
    exit(1);
}

echo "Found " . count($accounts) . " accounts\n\n";

// ============================================================================
// CREATE DUMMY CHALLENGES
// ============================================================================
echo "📍 Creating Dummy Weekly Challenges\n";
echo "───────────────────────────────────────────────────────────────\n\n";

$challenges = [
    [
        'title' => 'Hat-Trick Heroes',
        'description' => 'Score 3 goals in a single match. Go for glory!',
        'metric' => 'goals',
        'target_value' => 3,
        'xp_reward' => 250,
        'difficulty' => 'hard',
        'is_team_challenge' => false,
        'start_date' => date('Y-m-d'),
        'end_date' => date('Y-m-d', strtotime('+7 days')),
        'is_active' => true,
        'is_repeating' => true,
        'created_by' => $accounts[0]['id'] ?? null,
    ],
    [
        'title' => 'Assist Master',
        'description' => 'Rack up 5 assists across your matches this week.',
        'metric' => 'assists',
        'target_value' => 5,
        'xp_reward' => 200,
        'difficulty' => 'medium',
        'is_team_challenge' => false,
        'start_date' => date('Y-m-d'),
        'end_date' => date('Y-m-d', strtotime('+7 days')),
        'is_active' => true,
        'is_repeating' => true,
        'created_by' => $accounts[0]['id'] ?? null,
    ],
    [
        'title' => 'Endurance Run',
        'description' => 'Cover 8+ kilometers total distance across all matches.',
        'metric' => 'distance_meters',
        'target_value' => 8000,
        'xp_reward' => 150,
        'difficulty' => 'medium',
        'is_team_challenge' => false,
        'start_date' => date('Y-m-d'),
        'end_date' => date('Y-m-d', strtotime('+7 days')),
        'is_active' => true,
        'is_repeating' => true,
        'created_by' => $accounts[0]['id'] ?? null,
    ],
    [
        'title' => 'Speed Demon',
        'description' => 'Register 5 sprints in a single match. Show your pace!',
        'metric' => 'sprints',
        'target_value' => 5,
        'xp_reward' => 175,
        'difficulty' => 'easy',
        'is_team_challenge' => false,
        'start_date' => date('Y-m-d'),
        'end_date' => date('Y-m-d', strtotime('+7 days')),
        'is_active' => true,
        'is_repeating' => true,
        'created_by' => $accounts[0]['id'] ?? null,
    ],
    [
        'title' => 'Precision Passer',
        'description' => 'Complete 20 successful passes without losing the ball.',
        'metric' => 'passes',
        'target_value' => 20,
        'xp_reward' => 100,
        'difficulty' => 'easy',
        'is_team_challenge' => false,
        'start_date' => date('Y-m-d'),
        'end_date' => date('Y-m-d', strtotime('+7 days')),
        'is_active' => true,
        'is_repeating' => true,
        'created_by' => $accounts[0]['id'] ?? null,
    ],
    [
        'title' => 'Duelist',
        'description' => 'Win 10 duels throughout the week. Physical play wins matches!',
        'metric' => 'duels_won',
        'target_value' => 10,
        'xp_reward' => 180,
        'difficulty' => 'medium',
        'is_team_challenge' => false,
        'start_date' => date('Y-m-d'),
        'end_date' => date('Y-m-d', strtotime('+7 days')),
        'is_active' => true,
        'is_repeating' => true,
        'created_by' => $accounts[0]['id'] ?? null,
    ],
];

$createdCount = 0;
foreach ($challenges as $challenge) {
    try {
        $result = $sb->from('weekly_challenges')
            ->insert($challenge)
            ->execute();
        
        echo "✅ Created: {$challenge['title']}\n";
        echo "   Target: {$challenge['target_value']} {$challenge['metric']}\n";
        echo "   XP Reward: {$challenge['xp_reward']}\n";
        echo "   Difficulty: {$challenge['difficulty']}\n\n";
        
        $createdCount++;
    } catch (\Throwable $e) {
        echo "⚠️  {$challenge['title']}: " . $e->getMessage() . "\n\n";
    }
}

echo "\n";

// ============================================================================
// CREATE DUMMY ENROLLMENTS
// ============================================================================
echo "📍 Creating Dummy Challenge Enrollments\n";
echo "───────────────────────────────────────────────────────────────\n\n";

try {
    // Get the challenges we just created
    $allChallenges = $sb->from('weekly_challenges')
        ->select('id')
        ->eq('is_active', 'true')
        ->limit(10)
        ->execute();
    
    echo "Getting participants...\n";
    $participants = $sb->from('accounts')
        ->select('id')
        ->limit(10)
        ->execute();
    
    echo "Creating enrollments...\n\n";
    
    $enrollmentCount = 0;
    if (!empty($allChallenges)) {
        foreach ($allChallenges as $challenge) {
            // Enroll 3-5 random participants in each challenge
            $numEnrollees = rand(3, 5);
            $selectedParticipants = array_slice($participants, 0, $numEnrollees);
            
            foreach ($selectedParticipants as $participant) {
                try {
                    $result = $sb->from('challenge_participation')
                        ->insert([
                            'challenge_id' => $challenge['id'],
                            'user_id' => $participant['id'],
                            'enrolled_at' => date('c'),
                            'week_start_date' => date('Y-m-d'),
                            'current_progress' => rand(0, 100),
                            'position_rank' => rand(1, 10),
                            'is_winner' => rand(0, 1) === 1,
                            'status' => 'in_progress',
                        ])
                        ->execute();
                    
                    $enrollmentCount++;
                } catch (\Throwable $e) {
                    // Duplicate enrollment is OK
                }
            }
        }
    }
    
    echo "✅ Created $enrollmentCount challenge enrollments\n\n";
    
} catch (\Throwable $e) {
    echo "⚠️  Enrollment error: " . $e->getMessage() . "\n\n";
}

// ============================================================================
// CREATE DUMMY ACHIEVEMENTS
// ============================================================================
echo "📍 Creating Dummy Challenge Achievements\n";
echo "───────────────────────────────────────────────────────────────\n\n";

try {
    $achievementCount = 0;
    
    // Award some achievements to winners
    $completions = $sb->from('challenge_participation')
        ->select('id, user_id, challenge_id')
        ->eq('is_winner', 'true')
        ->limit(5)
        ->execute();
    
    foreach ($completions as $completion) {
        try {
            $result = $sb->from('challenge_achievements')
                ->insert([
                    'user_id' => $completion['user_id'],
                    'challenge_id' => $completion['challenge_id'],
                    'completed_at' => date('c'),
                    'xp_earned' => rand(100, 250),
                    'is_weekly' => true,
                    'is_elite' => rand(0, 1) === 1,
                ])
                ->execute();
            
            $achievementCount++;
        } catch (\Throwable $e) {
            // OK if already exists
        }
    }
    
    echo "✅ Awarded $achievementCount achievements\n\n";
    
} catch (\Throwable $e) {
    echo "⚠️  Achievement error: " . $e->getMessage() . "\n\n";
}

// ============================================================================
// VERIFICATION
// ============================================================================
echo "📍 Data Verification\n";
echo "───────────────────────────────────────────────────────────────\n\n";

try {
    $totalChallenges = $sb->from('weekly_challenges')
        ->select('id')
        ->execute();
    
    $activeChallenges = $sb->from('weekly_challenges')
        ->select('id')
        ->eq('is_active', 'true')
        ->execute();
    
    $totalEnrollments = $sb->from('challenge_participation')
        ->select('id')
        ->execute();
    
    $totalAchievements = $sb->from('challenge_achievements')
        ->select('id')
        ->execute();
    
    echo "Database Summary:\n";
    echo "───────────────────────────────────────────────────────────────\n";
    echo "Total Challenges: " . count($totalChallenges) . "\n";
    echo "Active Challenges: " . count($activeChallenges) . "\n";
    echo "Total Enrollments: " . count($totalEnrollments) . "\n";
    echo "Total Achievements: " . count($totalAchievements) . "\n\n";
    
    echo "✅ Dummy data creation complete!\n\n";
    
} catch (\Throwable $e) {
    echo "⚠️  Verification error: " . $e->getMessage() . "\n";
}

// ============================================================================
// NEXT STEPS
// ============================================================================
echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║                    NEXT STEPS                                  ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

echo "✅ Dummy data is ready for testing!\n\n";

echo "You can now:\n";
echo "  1. Open http://127.0.0.1:8000 in browser\n";
echo "  2. Login with: admin@nutmegplay.fr / Admin123!\n";
echo "  3. Go to Weekly Challenges\n";
echo "  4. See " . count($activeChallenges) . " active challenges\n";
echo "  5. See leaderboards with participants\n";
echo "  6. Test enrollment and progress tracking\n";
echo "  7. View achievements\n\n";

?>
