# NutmegPlay Split Deployment

This is the supported production shape now:

- Website: PHP/shared hosting
- AI: Runpod GPU pod
- Database/Auth: Supabase

Important:

- Supabase is used for database and auth.
- Match video files are uploaded to the website and stored in `public/videos/`.
- The AI pod downloads those files by URL when processing starts.
- The website keeps those files for 30 days and deletes them with a daily cleanup cron.

## 1. What runs where

On the PHP host:

- the website
- video storage in `public/videos/`
- Supabase API calls
- the queue/cron worker that asks the AI pod to analyze website-hosted videos
- the daily cleanup job that expires old website-hosted videos

On Runpod:

- FastAPI
- Python AI
- GPU inference
- temporary downloaded files while analysis is running

The website stores uploaded videos locally and saves a `/videos/...` URL. The worker later asks the AI pod to analyze that website-hosted file through `POST /analyze-url`.

## 2. PHP hosting checklist

Your hosting account needs:

- PHP 8+
- `curl` enabled
- file uploads enabled
- `public/videos` writable
- `storage/ai-output` writable
- `storage/ai-progress` writable
- `storage/logs/ai` writable
- enough disk space for the 30-day retention window

If your host uses Apache, upload the contents of this folder and keep [index.php](index.php) and [.htaccess](.htaccess) in the web root.

If your host lets you choose a document root, point it at this folder:

- `/path/to/website`

You do not need Python on the PHP host.

## 3. Website `.env`

Create or update `.env` on the PHP host with your real values:

```dotenv
SUPABASE_URL=https://YOUR_PROJECT.supabase.co
SUPABASE_KEY=YOUR_LEGACY_ANON_KEY
SUPABASE_SERVICE_ROLE_KEY=YOUR_LEGACY_SERVICE_ROLE_KEY

NUTMEG_AI_FASTAPI_URL=https://YOUR-RUNPOD-PROXY-ENDPOINT
NUTMEG_AI_FASTAPI_TIMEOUT=1800
NUTMEG_AI_FASTAPI_VERIFY_SSL=1
NUTMEG_AI_AUTO_PROCESS=0
NUTMEG_VIDEO_RETENTION_DAYS=30

NUTMEG_WEBSITE_URL=https://your-domain.com
NUTMEG_CHALLENGER_TEAM_COLOR=blue

NUTMEG_RUNPOD_API_KEY=YOUR_RUNPOD_API_KEY
NUTMEG_RUNPOD_TEMPLATE_ID=YOUR_RUNPOD_TEMPLATE_ID
# or:
# NUTMEG_RUNPOD_IMAGE_NAME=your-registry/nutmegplay-ai:latest
NUTMEG_RUNPOD_API_BASE=https://rest.runpod.io/v1
NUTMEG_RUNPOD_FASTAPI_PORT=8888
NUTMEG_RUNPOD_DYNAMIC_ONLY=1
NUTMEG_RUNPOD_DYNAMIC_PORTS=8888/http,22/tcp
NUTMEG_RUNPOD_STATUS_CACHE_SECONDS=5

# Optional
NUTMEG_PHP_BIN=php
```

Notes:

- `NUTMEG_AI_FASTAPI_URL` must be the Runpod proxy URL, not a direct public IP or host URL. The app appends `/analyze-url` and `/health` as needed.
- `NUTMEG_WEBSITE_URL` must be correct, because the AI pod downloads videos from that public website URL.
- On most shared hosting, `NUTMEG_AI_AUTO_PROCESS=0` plus cron is safer.
- The Runpod API key plus template/image config are used for dynamic pod creation from the website.
- This codebase expects the legacy JWT-style Supabase `anon` and `service_role` keys, not the newer `sb_publishable_...` and `sb_secret_...` keys.

## 4. Database setup

If production already has older live tables/data, use:

- [database/migrations/50-prod-schema-compat.sql](database/migrations/50-prod-schema-compat.sql)

Read the compatibility notes first:

- [PROD_SCHEMA_COMPATIBILITY.md](PROD_SCHEMA_COMPATIBILITY.md)

If this is a fresh install, verify the tables after import with:

```bash
php scripts/verify-tables.php
```

## 5. Runpod setup

Deploy the AI worker using the Docker-image flow in [../ai/DeploymentGuide.txt](../ai/DeploymentGuide.txt). The important files are:

- `api.py`
- `app.py`
- `requirements.txt`
- `start-fastapi.sh`
- `yolov8s.pt`
- `train/weights/best.pt`

Recommended Runpod shape:

- package and upload the Nutmeg AI worker using the Linux build flow
- create a Runpod template from the packaged worker, or set `NUTMEG_RUNPOD_IMAGE_NAME` directly
- expose `8888/http` (SSH/TCP is optional and not required for the AI proxy to work)
- keep `HOST=0.0.0.0`, `PORT=8888`, and `WORKERS=1`
- avoid attaching a network volume unless you explicitly need one

Then put the stable Runpod proxy URL into `NUTMEG_AI_FASTAPI_URL` on the PHP host. Do not use a direct pod IP or non-proxy endpoint.

Recommended Runpod behavior:

- choose an on-demand pod
- let the app create the cheapest matching pod dynamically
- keep the pod warm only while videos are actively being processed

## 6. Cron worker on shared hosting

If background spawning is unreliable, use cron instead of auto-processing.

Recommended setting:

```dotenv
NUTMEG_AI_AUTO_PROCESS=0
```

Then add a cron job that runs every minute:

```bash
php /absolute/path/to/website/scripts/process-queued-videos.php --limit=1
```

This script picks up queued uploads and asks the AI pod to analyze the already stored website videos one at a time.

Add a second daily cron for retention cleanup:

```bash
php /absolute/path/to/website/scripts/cleanup-hosted-videos.php --days=30
```

## 7. Upload limits

The app currently rejects files above 500 MB in [app/Controllers/VideoController.php](app/Controllers/VideoController.php).

Your PHP host must therefore allow at least:

- `upload_max_filesize >= 500M`
- `post_max_size > 500M`
- enough execution time for upload handling

## 8. Smoke test

After deployment:

1. Open the website.
2. Log in as an instructor or admin.
3. Start the AI pod from `/video-upload` if it is currently stopped.
4. Upload a small match video from `/video-upload`.
5. Confirm the saved `video_url` points at a website `/videos/...` path.
6. Confirm the row enters `queued` or `processing` in `match_video_analysis`.
7. Run the cron script manually once if needed.
8. Confirm the row becomes `processed`.

## 9. Most likely failure points

- wrong `NUTMEG_AI_FASTAPI_URL`
- wrong `NUTMEG_WEBSITE_URL`
- Runpod pod not started
- `curl` disabled on the PHP host
- upload size limits too small
- shared hosting blocking background process spawning
- missing write permissions on `storage/*` or `public/videos` on the PHP host

For shared hosting, the most reliable setup is:

- website stores videos locally
- Runpod AI deployment
- `NUTMEG_AI_AUTO_PROCESS=0`
- cron every minute
- daily cleanup of `public/videos`
