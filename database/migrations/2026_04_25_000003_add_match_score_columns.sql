-- Add score columns to matchs for AI/post-match score persistence

alter table public.matchs
    add column if not exists score_home integer,
    add column if not exists score_away integer,
    add column if not exists score_challanger integer,
    add column if not exists score_opponent integer;
