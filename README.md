# Nutmeg Football Platform

If you are deploying and want the shortest path, start here:

- [START_HERE_DEPLOY.md](START_HERE_DEPLOY.md)

If you need a plain-language guide for the client or end users, use:

- [CLIENT_USER_GUIDE.md](CLIENT_USER_GUIDE.md)

A modern PHP-based football analytics and management dashboard built with a custom MVC architecture, Tailwind CSS dark theme, and HTMX for SPA-like navigation.

## Quick Start

```bash
php -S 127.0.0.1:8000 index.php
```

Visit **http://localhost:8000**

Edit the existing `.env` file and set your real values:

- `SUPABASE_URL` (for example `https://your-project-ref.supabase.co`)
- `SUPABASE_KEY` (use the legacy `anon` key with this codebase)
- `SUPABASE_SERVICE_ROLE_KEY` (required for hosted/server-side app operations with the provided SQL grants)
- `NUTMEG_PHP_BIN` (optional, override PHP executable used to spawn the background worker)

## Project Structure

```
nutmeg/
├── index.php                  # Root front controller (entry point)
├── app/
│   ├── bootstrap.php           # PSR-4 autoloader
│   ├── routes.php              # All application routes
│   ├── Core/
│   │   ├── Controller.php      # Base controller (renderRaw)
│   │   ├── HtmxHelper.php     # HTMX request detection utilities
│   │   └── Router.php          # URL routing engine
│   ├── Controllers/
│   │   ├── AuthController.php
│   │   ├── ChallengesController.php
│   │   ├── EventsController.php
│   │   ├── FixturesController.php
│   │   ├── HomeController.php
│   │   ├── InstructorController.php
│   │   ├── MatchHistoryController.php
│   │   ├── PlayersController.php
│   │   ├── TeamController.php
│   │   └── VideoController.php
│   └── views/
│       ├── auth/
│       │   ├── login.php       # Centered card login
│       │   └── login-alt.php   # Split-panel login with branding
│       └── dashboards/
│           ├── home.php        # Main dashboard with live pitches
│           ├── matchmaking.php # PitchPerfect matchmaking lobby
│           ├── fixtures.php    # Upcoming fixtures & team rosters
│           ├── challenges.php  # Challenges & leaderboard
│           ├── players.php     # Player stats & FIFA-style card
│           ├── teams.php       # Team management & activity
│           ├── instructor.php  # Instructor analytics & diagnostics
│           ├── match-history.php # Match history & highlights
│           └── video-upload.php  # Video ingest & AI highlights
├── includes/
│   ├── header.php              # HTML head, Tailwind config, HTMX
│   ├── footer.php              # Closing HTML tags
│   ├── sidenav.php             # Unified sidebar navigation
│   └── topnav.php              # Top bar with page title
├── database/
│   └── init/
│       └── 20-notifications-challenges.sql # SQL for notifications/challenges tables
└── public/
    ├── index.php               # Compatibility wrapper to root entry point
    └── htmx.js                 # Mobile menu & sidebar JS
```

## Routes

| Route               | Page                                 |
| ------------------- | ------------------------------------ |
| `/` or `/dashboard` | Dashboard overview with live pitches |
| `/matchmaking`      | PitchPerfect matchmaking lobby       |
| `/fixtures`         | Upcoming fixtures & rosters          |
| `/challenges`       | Football challenges & XP             |
| `/players`          | Player stats dashboard               |
| `/teams`            | Team management                      |
| `/instructor`       | Instructor analytics                 |
| `/match-history`    | Match history & highlights           |
| `/video-upload`     | Video upload & AI highlights         |
| `/login`            | Login (centered card)                |
| `/login-alt`        | Login (split-panel with branding)    |

## Architecture

- **MVC**: Router dispatches to Controllers which render Views
- **Shared Layout**: All dashboard views include `header.php`, `sidenav.php`, `topnav.php`, and `footer.php`
- **Active Nav**: PHP-driven — each view sets `$currentPage` which the sidenav uses to highlight the active link
- **HTMX**: `hx-boost="true"` on `<body>` transparently upgrades all navigation to AJAX with history support
- **Sidebar**: Collapsible with localStorage persistence, responsive mobile menu

## Color Palette

| Token             | Value     | Usage            |
| ----------------- | --------- | ---------------- |
| `primary`         | `#1db954` | Green accent     |
| `background-dark` | `#122017` | Main background  |
| `card-dark`       | `#1b2e21` | Card backgrounds |
| `surface-dark`    | `#1b2e23` | Surface elements |
| Border            | `#264531` | Border color     |
| `text-muted`      | `#B3B3B3` | Secondary text   |
| `danger`          | `#E74C3C` | Error/danger     |

## Tech Stack

- **Backend**: PHP 8+ (custom MVC, PSR-4 autoloading)
- **Frontend**: Tailwind CSS (compiled stylesheet), Material Symbols icons
- **Interactivity**: local HTMX + custom JS for SPA-like navigation
- **Fonts**: Lexend, Space Grotesk, Noto Sans

## Match Video AI

The current production shape is:

- website on PHP/shared hosting
- AI on a Runpod pod
- Supabase for auth and database
- new match videos stored in Backblaze B2 (`foot-videos`)
- AI downloads the B2 object URL when processing starts

Before using this flow, make sure you:

1. Apply [database/migrations/50-prod-schema-compat.sql](database/migrations/50-prod-schema-compat.sql) if production already has existing data.
2. Set the real Supabase and Runpod/AI values in `.env`.
3. Configure the `NUTMEG_B2_*` variables in `.env`; never expose them to the mobile app.
4. On shared hosting, set `NUTMEG_AI_AUTO_PROCESS=0` and run the queue worker from cron.
5. Add the daily cleanup cron for `scripts/cleanup-hosted-videos.php`.
6. See [DEPLOY_SPLIT_HOSTING.md](DEPLOY_SPLIT_HOSTING.md) for the production checklist.
7. See [../ai/DeploymentGuide.txt](../ai/DeploymentGuide.txt) for the AI pod deployment steps.
