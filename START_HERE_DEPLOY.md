# Start Here

Ignore most of the repo. For deployment, these are the only things that matter.

## If production already has data

Run this in Supabase first:

- [database/migrations/50-prod-schema-compat.sql](database/migrations/50-prod-schema-compat.sql)

Read this once before running it:

- [PROD_SCHEMA_COMPATIBILITY.md](PROD_SCHEMA_COMPATIBILITY.md)

Do not run `database/init/00-hosted-bootstrap.sql` on a live production DB.

## Website deploy

Upload this file to your PHP host:

- [dist/nutmegplay-php-hosting.zip](../../dist/nutmegplay-php-hosting.zip)

That zip already contains:

- the website files
- the root `.htaccess` router required for `/login`, `/dashboard`, and API routes
- empty writable folders

Keep or upload the production `.env` beside `index.php`. It is intentionally
not included in the zip so deployment archives do not expose production secrets.
Make sure these values are correct:

- `SUPABASE_URL`
- `SUPABASE_KEY`
- `SUPABASE_SERVICE_ROLE_KEY`
- `NUTMEG_AI_FASTAPI_URL`
- `NUTMEG_WEBSITE_URL`
- `NUTMEG_RUNPOD_API_KEY`
- `NUTMEG_RUNPOD_TEMPLATE_ID` or `NUTMEG_RUNPOD_IMAGE_NAME`

Important: `NUTMEG_AI_FASTAPI_URL` must point to the Runpod proxy URL only. Do not use a direct pod IP or direct host URL.

Recommended production values:

```dotenv
NUTMEG_AI_AUTO_PROCESS=0
NUTMEG_AI_FASTAPI_TIMEOUT=1800
NUTMEG_VIDEO_RETENTION_DAYS=30
NUTMEG_AI_STALE_JOB_TIMEOUT_SECONDS=7200
```

Then add cron on the PHP host:

```bash
php /absolute/path/to/website/scripts/process-queued-videos.php --limit=1
php /absolute/path/to/website/scripts/cleanup-hosted-videos.php --days=30
```

## AI deploy

Use the Docker-image Runpod flow in [../ai/DeploymentGuide.txt](../ai/DeploymentGuide.txt).

The important AI files are:

- [api.py](../ai/api.py)
- [app.py](../ai/app.py)
- [requirements.txt](../ai/requirements.txt)
- [start-fastapi.sh](../ai/start-fastapi.sh)
- [DeploymentGuide.txt](../ai/DeploymentGuide.txt)

The AI pod must:

- run FastAPI
- expose one stable public URL that the website can reach
- be launched from the custom Nutmeg AI image or template, not from a raw `runpod/pytorch` base pod
- if the pod is terminated or a job stalls, the website treats queued/processing jobs older than the stale timeout as failed so the next upload can start cleanly

## Ignore these for now

You do not need to think about these while deploying:

- old backup zip files
- local `public/videos/*.mp4`
- local logs in `storage/`
- old helper scripts
- legacy AI-hosted upload files unless you are debugging an older deployment

## Production shape

So the clean production path is:

1. fix the DB with the compatibility migration
2. upload the website zip to PHP/shared hosting
3. package and deploy the Nutmeg AI worker to Runpod
4. point `NUTMEG_AI_FASTAPI_URL` at the Runpod proxy endpoint
5. let the website store videos and let the AI download them by URL

If you want the shortest possible checklist, use only these three files:

- [database/migrations/50-prod-schema-compat.sql](database/migrations/50-prod-schema-compat.sql)
- [dist/nutmegplay-php-hosting.zip](../../dist/nutmegplay-php-hosting.zip)
- [DeploymentGuide.txt](../ai/DeploymentGuide.txt)
