# Weekly Challenges System

Complete weekly competition framework for NutmegPlay with automatic progress tracking, leaderboards, and rewards.

## Overview

The weekly challenges system allows instructors to create competitions with specific metrics (goals, assists, etc.) that players complete during the week. Progress is automatically updated from video analysis, and winners receive XP rewards.

**Key Features:**
- 🎯 Instructor-created challenges with customizable metrics
- 📊 Real-time leaderboards with automatic ranking
- 🤖 Auto-tracking from AI video analysis (no manual updates)
- ⭐ XP rewards & streak tracking
- 📈 Player history and achievement tracking
- 🏆 Weekly results archival

## Database Schema

### `weekly_challenges` Table
Central definition of all available challenges:
```sql
id             - Challenge ID (primary key)
title          - Challenge name
description    - Challenge details
metric         - What to measure (goals, assists, distance_meters, sprints, passes, etc.)
target_value   - Goal value (e.g., 5 goals to win)
xp_reward      - XP awarded when completed (default: 100)
difficulty     - Challenge difficulty: easy/medium/hard
is_team_challenge - Team vs individual competition
start_date     - Challenge starts (Monday of week)
end_date       - Challenge ends (Sunday of week)
is_active      - Whether this challenge is currently active
is_repeating   - Auto-repeats weekly if TRUE
created_by     - Instructor who created it (UUID)
```

### `challenge_participation` Table
Tracks each player's progress:
```sql
id              - Record ID
challenge_id    - Reference to weekly_challenges
user_id         - Player (UUID)
week_start_date - Monday of competition week (YYYY-MM-DD)
week_number     - Week number (1-52)
year_number     - Year (2026, 2027, etc)
current_progress - Player's current metric value
target_value    - Goal to reach
status          - in_progress/completed/abandoned/not_started
completed_at    - Timestamp when target reached
position_rank   - Final position (1st, 2nd, 3rd, etc)
xp_awarded      - XP given for completion
streak_count    - Consecutive weeks won
is_winner       - Whether player placed in top 3
```

### `weekly_challenge_results` Table
Historical archive for completed weeks:
```sql
id                  - Record ID
challenge_id        - Challenge ID
week_start_date     - Week of competition
week_number         - Week number
year_number         - Year
winner_user_id      - Top-ranked player (UUID)
top_3_users         - Array of top 3 player IDs
top_3_scores        - Corresponding scores
total_participants  - Players who participated
total_xp_distributed - Total XP awarded this week
```

### `challenge_achievements` Table
Track badges and achievements:
```sql
id              - Achievement ID
user_id         - Player who earned it (UUID)
achievement_type - Type (5_week_streak, 1000_xp, consistent_challenger, etc)
badge_icon      - Emoji or icon reference
badge_label     - Display name
earned_at       - When earned (timestamp)
```

## Controller: `WeeklyChallengesController`

### Routes

#### Web Routes (Browser)
```
GET  /weekly-challenges              → index()    # View all challenges & leaderboards
GET  /weekly-challenges/:id          → details()  # View specific challenge details
POST /weekly-challenges/create       → create()   # Admin: Create new challenge
POST /weekly-challenges/:id/enroll   → enroll()   # Player: Join a challenge
```

#### API Routes (JSON)
```
GET  /api/weekly-challenges/leaderboard/:id      → leaderboardJson()       # Challenge leaderboard
GET  /api/weekly-challenges/overall               → overallLeaderboardJson() # Weekly top performers
POST /api/weekly-challenges/archive-week          → archiveWeek()           # Cron: Archive results
```

### Methods

#### `index()`
- **Access**: Authenticated users
- **Returns**: HTML view with:
  - Active challenges for current week
  - Individual player progress for each
  - Overall leaderboard (all challenges combined)
  - Player's challenge history (last 10)
  - (Admin only) Challenge creation form

#### `details(int $id)`
- **Access**: Authenticated users
- **Returns**: HTML view for specific challenge showing:
  - Challenge details & metrics
  - Full leaderboard with rankings
  - User's personal progress
  - Time remaining until end of week

#### `create()`
- **Access**: Instructors only (verified via CSRF)
- **POST Parameters**:
  ```
  title              - Challenge name
  description        - Details/rules
  metric             - goals|assists|distance_meters|sprints|passes|matches_won|duels_won
  target_value       - Goal (integer)
  xp_reward          - XP for completion (default 100)
  difficulty         - easy|medium|hard
  is_team_challenge  - true|false
  start_date         - YYYY-MM-DD (usually today/Monday)
  end_date           - YYYY-MM-DD (usually +7 days)
  is_repeating       - true|false
  ```
- **Returns**: JSON with challenge_id on success

#### `enroll(int $id)`
- **Access**: Authenticated users (CSRF required)
- **Action**: Enrolls user in challenge for current week
- **Returns**: JSON success/error response
- **Side Effects**: Creates `challenge_participation` record, sends notification

#### `leaderboardJson(int $id)`
- **Access**: Authenticated users
- **Returns**: JSON array of leaderboard entries sorted by progress DESC
- **Includes**: Player name, current progress, rank, XP awarded

#### `overallLeaderboardJson()`
- **Access**: Authenticated users
- **Returns**: JSON array of overall weekly standings (top 50)
- **Shows**: Challenges completed, total XP earned, times ranked

#### `archiveWeek()`
- **Access**: Cron job only (requires X-Cron-Secret header)
- **Action**: Archives previous week's results
- **Triggers**: Calculates final rankings, generates results records
- **Schedule**: Run daily at 00:01 UTC to archive Monday-Sunday week

## Service: `WeeklyChallengesService`

### Core Methods

#### `createChallenge(array $data): int`
Creates a new challenge definition.
```php
$service = new WeeklyChallengesService($db);
$id = $service->createChallenge([
    'title' => 'Weekly Goals Challenge',
    'metric' => 'goals',
    'target_value' => 5,
    'xp_reward' => 100,
    'difficulty' => 'medium',
]);
```

#### `getActiveChallenges(): array`
Returns all active challenges for current week.
```php
$challenges = $service->getActiveChallenges();
// Each has: id, title, description, metric, target_value, difficulty, etc.
```

#### `getChallengeLeaderboard(int $id, ?string $weekDate): array`
Gets ranked leaderboard for a specific challenge.
```php
$leaderboard = $service->getChallengeLeaderboard(1);
// Each entry: user_id, fname, lname, current_progress, position_rank, is_winner
```

#### `enrollUserInChallenge(int $id, string $userId): bool`
Enrolls a user in a challenge for the current week.
```php
$enrolled = $service->enrollUserInChallenge($challengeId, Auth::uid());
```

#### `updatePlayerProgress(string $userId, int $matchId): void`
**AUTO-CALLED after video analysis completes!**

Updates player's progress on all active challenges based on latest match stats.
```php
// Called automatically from VideoAnalysisService after AI analysis
$service->updatePlayerProgress($userId, $matchId);
```

The method:
1. Fetches player's total stats (all matches this week)
2. Finds all active challenges
3. For each challenge:
   - Gets player's participation record
   - Updates `current_progress` from match stats
   - Auto-completes if target reached
   - Awards XP if completed

#### `getOverallLeaderboard(?string $weekDate, int $limit): array`
Combined leaderboard across all challenges.
```php
$overall = $service->getOverallLeaderboard(null, 50);
// Sorted by total_xp_earned DESC, then challenges_completed DESC
```

#### `getUserChallengeHistory(string $userId, int $limit): array`
Gets player's challenge participation history.
```php
$history = $service->getUserChallengeHistory(Auth::uid(), 20);
// Shows all past and current challenges with status
```

#### `archiveWeek(string $weekStartDate): void`
Archives all completed weeks and generates results.
```php
$service->archiveWeek('2026-04-13');  // Monday of week
```

Creates `weekly_challenge_results` records with final rankings.

## Auto-Tracking Flow

### When Video Analysis Completes

1. **AI finishes processing match video**
   ```
   VideoAnalysisService::processMatchVideo() completes
   ```

2. **Match stats persisted to database**
   ```
   replacePersistedStats() saves jersey stats
   ```

3. **Weekly challenges triggered**
   ```
   updateWeeklyChallengesForMatch() called for all players
   ```

4. **For each participating player**
   ```
   WeeklyChallengesService::updatePlayerProgress($userId, $matchId)
   ```

5. **Progress auto-updated**
   - Fetches player's cumulative stats this week
   - Updates `challenge_participation.current_progress`
   - If target reached: `status = 'completed'`, `xp_awarded`
   - Sends notification: "Challenge Completed! +100 XP"

6. **Player sees immediate feedback**
   - Leaderboard auto-updates (refreshes every 30 seconds in UI)
   - XP badge shows earned points
   - Rank updates in real-time

**No manual intervention needed!** Everything flows automatically from video upload → AI analysis → stats → challenges → rewards.

## Frontend: Weekly Challenges Dashboard

### Location
`app/views/dashboards/weekly-challenges.php`

### Features

#### Active Challenges Grid
- Card per active challenge
- Shows: title, difficulty, description, metric
- Progress bar (current / target)
- Mini leaderboard (top 3)
- Join button (if not enrolled) or status badge

#### Challenge Creation (Instructors Only)
- Form to create new challenge
- Fields: title, description, metric, target, difficulty
- Submit creates challenge immediately
- Available in sidebar/admin menu

#### Overall Leaderboard Table
- Real-time rankings across all challenges
- Columns: Rank (🥇🥈🥉), Player, Challenges Won, XP Earned
- Updates every 30 seconds via AJAX
- Highlights top 3 with medal emojis

#### Challenge History
- Shows player's past 10 challenge attempts
- Status: In Progress / Completed / Abandoned
- XP earned per challenge
- Week dates

### Real-Time Updates
```javascript
// Auto-refresh leaderboards every 30 seconds
setInterval(() => {
    fetch('/api/weekly-challenges/overall')
        .then(r => r.json())
        .then(data => {
            // Update leaderboard DOM
        });
}, 30000);
```

## Integration with Match Flow

### Step-by-Step

1. **Player uploads match video**
   → `VideoController::handleUpload()`

2. **AI starts processing**
   → `VideoAnalysisService::queueAnalysis()`

3. **Video uploaded to AI worker**
   → `VideoAnalysisService::storeUploadedVideo()`

4. **AI analyzes video** (background job)
   → `scripts/process-match-video.php`
   → `VideoAnalysisService::processMatchVideo()`

5. **Stats extracted from AI output**
   → Match goals, assists, distance, etc. stored in `jersey_stats`

6. **Weekly challenges updated** ⭐ NEW
   → `VideoAnalysisService::updateWeeklyChallengesForMatch()`
   → For each player who played:
   → `WeeklyChallengesService::updatePlayerProgress()`

7. **Player notified** ⭐ NEW
   → "Challenge Complete! +100 XP"
   → Leaderboard updates in real-time

8. **Week completes** (every Sunday at 23:59)
   → Cron job calls `POST /api/weekly-challenges/archive-week`
   → Results saved, streaks tracked, achievements awarded

## Cron Jobs

### Daily Archive (recommend: 00:01 UTC)
```bash
# Call to archive the previous week
curl -X POST https://nutmegplay.com/api/weekly-challenges/archive-week \
  -H "X-Cron-Secret: $NUTMEG_CRON_SECRET"
```

**Alternative: PHP cron**
```php
$_SERVER['HTTP_X_CRON_SECRET'] = getenv('NUTMEG_CRON_SECRET');
require 'app/Controllers/WeeklyChallengesController.php';
$controller = new WeeklyChallengesController();
$controller->archiveWeek();
```

## Database Queries

### Get current week's active challenges
```sql
SELECT * FROM weekly_challenges
WHERE is_active = TRUE
  AND start_date <= CURRENT_DATE
  AND end_date >= CURRENT_DATE;
```

### Get player's current progress on all challenges
```sql
SELECT cp.challenge_id, cp.current_progress, cp.target_value, 
       cp.status, wc.title, wc.metric
FROM challenge_participation cp
JOIN weekly_challenges wc ON cp.challenge_id = wc.id
WHERE cp.user_id = $userId
  AND cp.week_start_date = $weekStart
ORDER BY cp.status DESC, cp.current_progress DESC;
```

### Get leaderboard for specific challenge
```sql
SELECT cp.*, a.fname, a.lname, a.email,
       ROW_NUMBER() OVER (ORDER BY cp.current_progress DESC) as rank
FROM challenge_participation cp
JOIN auth.users a ON cp.user_id = a.id
WHERE cp.challenge_id = $id
  AND cp.week_start_date = $weekStart
ORDER BY cp.current_progress DESC
LIMIT 100;
```

### Archive week (called by cron)
```sql
INSERT INTO weekly_challenge_results (challenge_id, week_start_date, ...)
SELECT challenge_id, week_start_date,
  (ARRAY_AGG(user_id ORDER BY current_progress DESC))[1:3] as top_3_users,
  (ARRAY_AGG(current_progress ORDER BY current_progress DESC))[1:3] as top_3_scores,
  COUNT(*), SUM(xp_awarded)
FROM challenge_participation
WHERE week_start_date = $weekStart
GROUP BY challenge_id, week_start_date;
```

## Example: Creating Weekly Challenges

### Scenario: Manager Sets Up Monday's Challenges

1. **Create Goals Challenge**
   ```
   POST /weekly-challenges/create
   {
     "title": "Goal Scorer",
     "description": "Score 5 goals this week",
     "metric": "goals",
     "target_value": 5,
     "xp_reward": 150,
     "difficulty": "medium",
     "start_date": "2026-04-13",
     "end_date": "2026-04-19"
   }
   ```

2. **Create Assists Challenge**
   ```
   "title": "Playmaker",
   "metric": "assists",
   "target_value": 4,
   "xp_reward": 100
   ```

3. **Create Distance Challenge**
   ```
   "title": "Endurance",
   "metric": "distance_meters",
   "target_value": 8000,  // 8km
   "xp_reward": 75,
   "difficulty": "easy"
   ```

4. **Players see challenges in dashboard**
   - Can enroll in any/all challenges
   - View leaderboards
   - See current progress

5. **Match uploaded Wednesday**
   - Player scores 2 goals, 1 assist, runs 2500m
   - AI completes analysis
   - Challenge progress auto-updated
   - Goal: 2/5 (40%), Assists: 1/4 (25%), Distance: 2500m/8000m (31%)

6. **Match uploaded Friday**
   - Player scores 3 goals, 2 assists, runs 3000m
   - Goal: 5/5 ✅ COMPLETED! +150 XP
   - Assists: 3/4 (75%), Distance: 5500m/8000m (69%)
   - Notification sent immediately

7. **Sunday night**
   - Cron archives week
   - Results saved to `weekly_challenge_results`
   - New week begins Monday

## Error Handling

- **Challenge update fails**: Error logged, processing continues
- **Player not found**: Record skipped, no crash
- **Database down**: Graceful fallback, retry on next analysis
- **Invalid metric**: Validation at creation, caught early

## Performance Optimization

- **Indexes on**:
  - `challenge_participation(user_id, week_start_date)`
  - `challenge_participation(status, week_start_date)`
  - `weekly_challenges(is_active, start_date, end_date)`

- **Leaderboard limit**: Top 100 cached, refreshed every 30s

- **Stats aggregation**: Uses match totals (not per-frame), lightweight

## Future Enhancements

- [ ] Team challenges (combine team member scores)
- [ ] Challenge streaks (bonus XP for consecutive wins)
- [ ] Custom metric calculations
- [ ] Challenge templates (recurring monthly/seasonal)
- [ ] Mobile app push notifications for completions
- [ ] Achievements/badges system
- [ ] Challenge difficulty multipliers
- [ ] Leaderboard filters (by position, team, etc)
