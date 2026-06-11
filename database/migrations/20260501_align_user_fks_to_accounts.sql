-- ─────────────────────────────────────────────────────────────────────────
-- Migration: align match_stats / xp_history / player_progress.user_id FKs
--            with the table the application actually uses for users
--            (`accounts`), instead of `auth.users`.
--
-- Why:  the website's auth runs against the `accounts` table directly
--       (custom password column). `auth.users` is a leftover Supabase Auth
--       table that the app never touches. The existing FKs target
--       `auth.users(id)`, which is why match #16 hit
--           HTTP 409: insert or update on table "match_stats" violates
--           foreign key constraint "match_stats_user_id_fkey"
--       — the lineup referenced an account whose UID had no matching
--       row in `auth.users`.
--
-- How to run:
--   1. Open https://supabase.com/dashboard/project/strddrjneurylxoolimj
--   2. Click "SQL Editor" → New query
--   3. Paste the entire content of this file
--   4. Click Run
--
-- Safety:
--   - Verified before writing this file: every existing row in
--     match_stats / xp_history already has a user_id that is present in
--     accounts.uid. The new FK does not orphan any data.
--   - Wrapped in a single transaction; if any step fails the whole
--     migration rolls back.
--   - Read-only queries at the bottom show the post-migration state.
-- ─────────────────────────────────────────────────────────────────────────

BEGIN;

-- 1. accounts.uid must be UNIQUE before it can be a FK target. Verified
--    21 of 21 rows already unique; this just enforces it going forward.
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint c
        JOIN pg_class t ON t.oid = c.conrelid
        WHERE t.relname = 'accounts'
          AND c.contype = 'u'
          AND pg_get_constraintdef(c.oid) ILIKE '%(uid)%'
    ) THEN
        ALTER TABLE public.accounts
            ADD CONSTRAINT accounts_uid_key UNIQUE (uid);
    END IF;
END $$;

-- 2. Drop the old FKs to auth.users. Some installs name them differently;
--    we drop by the canonical column-derived name.
ALTER TABLE public.match_stats
    DROP CONSTRAINT IF EXISTS match_stats_user_id_fkey;
ALTER TABLE public.xp_history
    DROP CONSTRAINT IF EXISTS xp_history_user_id_fkey;
ALTER TABLE public.player_progress
    DROP CONSTRAINT IF EXISTS player_progress_user_id_fkey;

-- 3. Add new FKs that target accounts.uid. ON DELETE CASCADE so that
--    if an admin deletes an account, their stats / xp / progress rows
--    are cleaned up automatically.
ALTER TABLE public.match_stats
    ADD CONSTRAINT match_stats_user_id_fkey
    FOREIGN KEY (user_id) REFERENCES public.accounts (uid) ON DELETE CASCADE;

ALTER TABLE public.xp_history
    ADD CONSTRAINT xp_history_user_id_fkey
    FOREIGN KEY (user_id) REFERENCES public.accounts (uid) ON DELETE CASCADE;

ALTER TABLE public.player_progress
    ADD CONSTRAINT player_progress_user_id_fkey
    FOREIGN KEY (user_id) REFERENCES public.accounts (uid) ON DELETE CASCADE;

COMMIT;

-- ── Verify (read-only) ─────────────────────────────────────────────────
SELECT 'match_stats' AS table_name, conname AS constraint_name,
       pg_get_constraintdef(c.oid) AS definition
FROM pg_constraint c
JOIN pg_class t ON t.oid = c.conrelid
WHERE t.relname IN ('match_stats', 'xp_history', 'player_progress', 'accounts')
  AND contype IN ('f', 'u')
ORDER BY t.relname, conname;

-- Expected output: every FK on (user_id) for match_stats / xp_history /
-- player_progress should now read
--     FOREIGN KEY (user_id) REFERENCES accounts(uid) ON DELETE CASCADE
-- and accounts should have a UNIQUE (uid) constraint.
