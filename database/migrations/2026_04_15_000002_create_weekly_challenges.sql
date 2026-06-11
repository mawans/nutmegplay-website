-- Migration: Create weekly challenges system with auto-tracking
-- Features: Weekly challenges, leaderboards, progress tracking, rewards

-- Weekly challenges created by admins/instructors
CREATE TABLE IF NOT EXISTS public.weekly_challenges (
    id BIGSERIAL PRIMARY KEY,
    title TEXT NOT NULL,
    description TEXT,
    metric TEXT NOT NULL,  -- 'goals', 'assists', 'distance_meters', 'sprints', 'passes', 'matches_won', 'duels_won'
    target_value INTEGER NOT NULL DEFAULT 5,
    xp_reward INTEGER NOT NULL DEFAULT 100,
    difficulty VARCHAR(20) DEFAULT 'medium',  -- easy, medium, hard
    is_team_challenge BOOLEAN DEFAULT FALSE,  -- if true, applies to teams; if false, applies to individuals
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    is_repeating BOOLEAN DEFAULT TRUE,  -- repeats weekly
    created_by UUID,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW()
);

-- Player/Team participation and progress tracking
CREATE TABLE IF NOT EXISTS public.challenge_participation (
    id BIGSERIAL PRIMARY KEY,
    challenge_id BIGINT NOT NULL REFERENCES public.weekly_challenges(id) ON DELETE CASCADE,
    user_id UUID NOT NULL,
    week_start_date DATE NOT NULL,  -- Monday of the week
    week_number INTEGER NOT NULL,   -- Week 1-52 of the year
    year_number INTEGER NOT NULL,   -- 2026, 2027, etc
    current_progress NUMERIC NOT NULL DEFAULT 0,
    target_value INTEGER NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'in_progress',  -- 'not_started', 'in_progress', 'completed', 'abandoned'
    completed_at TIMESTAMPTZ,
    position_rank INTEGER,  -- Final rank this week (1st, 2nd, 3rd, etc)
    xp_awarded INTEGER DEFAULT 0,
    points_bonus INTEGER DEFAULT 0,  -- Extra points for streaks
    streak_count INTEGER DEFAULT 0,  -- Consecutive weeks won
    is_winner BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW()
);

-- Historical results (archive of completed weeks)
CREATE TABLE IF NOT EXISTS public.weekly_challenge_results (
    id BIGSERIAL PRIMARY KEY,
    challenge_id BIGINT NOT NULL,
    week_start_date DATE NOT NULL,
    week_number INTEGER NOT NULL,
    year_number INTEGER NOT NULL,
    winner_user_id UUID,
    winner_name TEXT,
    top_3_users UUID[],  -- Array of top 3 user IDs
    top_3_scores NUMERIC[],  -- Corresponding scores
    total_participants INTEGER DEFAULT 0,
    total_xp_distributed INTEGER DEFAULT 0,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- Streaks and achievements
CREATE TABLE IF NOT EXISTS public.challenge_achievements (
    id BIGSERIAL PRIMARY KEY,
    user_id UUID NOT NULL,
    achievement_type VARCHAR(50) NOT NULL,  -- '5_week_streak', 'consistent_challenger', '1000_xp', etc
    challenge_id BIGINT,
    earned_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    badge_icon TEXT,  -- emoji or icon reference
    badge_label TEXT NOT NULL
);

-- Create indexes for fast queries
CREATE INDEX IF NOT EXISTS idx_weekly_challenges_active ON public.weekly_challenges(is_active, start_date, end_date);
CREATE INDEX IF NOT EXISTS idx_challenge_participation_user_week ON public.challenge_participation(user_id, week_start_date);
CREATE INDEX IF NOT EXISTS idx_challenge_participation_week ON public.challenge_participation(challenge_id, week_start_date);
CREATE INDEX IF NOT EXISTS idx_challenge_participation_status ON public.challenge_participation(status);
CREATE INDEX IF NOT EXISTS idx_challenge_results_week ON public.weekly_challenge_results(week_start_date);
CREATE INDEX IF NOT EXISTS idx_challenge_achievements_user ON public.challenge_achievements(user_id);

-- Grant permissions
REVOKE ALL PRIVILEGES ON public.weekly_challenges FROM anon, authenticated;
REVOKE ALL PRIVILEGES ON public.challenge_participation FROM anon, authenticated;
REVOKE ALL PRIVILEGES ON public.weekly_challenge_results FROM anon, authenticated;
REVOKE ALL PRIVILEGES ON public.challenge_achievements FROM anon, authenticated;

GRANT ALL PRIVILEGES ON public.weekly_challenges TO service_role;
GRANT ALL PRIVILEGES ON public.challenge_participation TO service_role;
GRANT ALL PRIVILEGES ON public.weekly_challenge_results TO service_role;
GRANT ALL PRIVILEGES ON public.challenge_achievements TO service_role;
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO service_role;
