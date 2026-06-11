create table if not exists public.match_video_analysis (
    id uuid primary key default gen_random_uuid(),
    match_id bigint not null unique references public.matchs(id) on delete cascade,
    video_url text,
    video_source_type text not null default 'local_upload',
    processing_status text not null default 'pending',
    ai_output jsonb,
    team_stats jsonb,
    player_stats jsonb,
    normalized_stats jsonb,
    error_message text,
    progress_stage text,
    progress_percent integer not null default 0,
    progress_message text,
    uploaded_by uuid,
    queued_at timestamptz not null default now(),
    processed_at timestamptz,
    created_at timestamptz not null default now(),
    updated_at timestamptz not null default now()
);

create index if not exists idx_match_video_analysis_status on public.match_video_analysis(processing_status);
revoke all privileges on public.match_video_analysis from anon, authenticated;
grant all privileges on public.match_video_analysis to service_role;
