# Prod Schema Compatibility

This project currently expects a newer database shape than the older production schema snapshot.

Use [database/migrations/50-prod-schema-compat.sql](database/migrations/50-prod-schema-compat.sql) for an existing production database.

Do not run [database/init/00-hosted-bootstrap.sql](database/init/00-hosted-bootstrap.sql) against a live production database that already has data.

This migration assumes the older core production tables already exist, such as `accounts`, `announcement`, `clubs`, `invites`, `matchs`, `match_stats`, `player_progress`, and `xp_history`.

## What the migration fixes

- Creates missing tables used by the current app:
  - `notifications`
  - `challenge_templates`
  - `match_video_analysis`
  - `match_jersey_slots`
  - `match_jersey_stats`
- Adds the missing `announcement.title` column
- Adds `accounts.role` and backfills it from `login_type` when possible
- Makes `match_stats.match_id` bigint-compatible for the current app
- Makes `xp_history.match_id` bigint-compatible for the current app
- Adds indexes and service-role grants expected by the app

## Safe behavior

The migration is intentionally non-destructive.

- If `match_stats.match_id` is currently `uuid`, it is renamed to `legacy_match_id_uuid`
- A new bigint `match_id` column is added for the app going forward
- The same preservation is done for `xp_history.match_id`
- Existing legacy values are not discarded
- If legacy values look numeric, they are copied into the new bigint column
- If they are true UUID values, they remain preserved in the legacy column and the new bigint column stays `null`

This keeps the app working for new data without forcing a risky cast that could destroy old references.

## Known limitations

- Old `uuid`-based `match_stats` rows cannot be automatically mapped to `public.matchs.id bigint` unless you have your own external mapping between the old UUID values and current match ids.
- Old `uuid`-based `xp_history` rows have the same limitation.
- The migration will only add the `(match_id, user_id)` uniqueness constraint on `match_stats` if there are no existing duplicates.
- This document only covers database compatibility. The current production app flow now expects website-hosted videos and a separate AI endpoint.

## Post-run checks

After running the migration, check these:

```sql
select count(*) as legacy_match_stats_rows
from public.match_stats
where legacy_match_id_uuid is not null
  and match_id is null;

select count(*) as legacy_xp_history_rows
from public.xp_history
where legacy_match_id_uuid is not null
  and match_id is null;

select match_id, user_id, count(*)
from public.match_stats
where match_id is not null
group by match_id, user_id
having count(*) > 1;
```

If the first two counts are non-zero, the migration preserved legacy UUID-based rows safely, but those old rows still need manual mapping if you want them tied to current bigint `matchs.id` rows.

## Why this migration is needed

The current PHP app writes bigint match ids into:

- [ApiController.php](app/Controllers/ApiController.php)
- [VideoAnalysisService.php](app/Services/VideoAnalysisService.php)
- [XpHistoryService.php](app/Services/XpHistoryService.php)

The older production schema snapshot used `uuid` for `match_stats.match_id` and `xp_history.match_id`, which would cause manual stats, AI stats, and XP writes to fail.

The app also inserts announcement titles in:

- [AdminController.php](app/Controllers/AdminController.php)
- [ApiController.php](app/Controllers/ApiController.php)

So `announcement.title` must exist.
