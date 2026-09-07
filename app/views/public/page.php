<?php
$isLoggedIn = $isLoggedIn ?? false;
$pageKey = $pageKey ?? 'support';

$pages = [
    'matchmaking' => [
        'title' => 'Matchmaking',
        'eyebrow' => 'Schedule better games',
        'intro' => 'Create fixtures, challenge opponents, and keep every player aligned before match day.',
        'cta' => ['label' => 'Start Matchmaking', 'href' => '/register'],
        'sections' => [
            [
                'heading' => 'Create Matches Without The Back-And-Forth',
                'body' => 'FiveStats gives organizers and instructors a clean way to set teams, locations, kickoff times, and opponent challenges from one dashboard.',
                'items' => [
                    'Create upcoming matches with date, time, venue, and opponent details.',
                    'Send match challenges and track pending, accepted, or rejected responses.',
                    'Connect scheduled matches to uploaded videos and analysis history.',
                ],
            ],
            [
                'heading' => 'Built For Local Football Communities',
                'body' => 'The matchmaking flow is designed for five-a-side fields, club owners, instructors, and recurring groups that need fast organization.',
                'items' => [
                    'Keep players connected to their teams and fixtures.',
                    'Reduce manual messages by keeping match status visible in the app.',
                    'Give admins control over match approval and venue assignment.',
                ],
            ],
            [
                'heading' => 'From Fixture To Stats',
                'body' => 'A scheduled match becomes the record that powers video uploads, bib selection, AI analysis, notifications, and player history.',
                'items' => [
                    'Match details stay consistent across mobile and web.',
                    'Players can return later to view unlocked statistics.',
                    'Organizers can manage match recordings after the game.',
                ],
            ],
        ],
    ],
    'players' => [
        'title' => 'Player Analytics',
        'eyebrow' => 'Turn match footage into player insight',
        'intro' => 'FiveStats helps players understand their performance with AI-generated statistics, history, rankings, and progress tracking.',
        'cta' => ['label' => 'View Player Tools', 'href' => '/register'],
        'sections' => [
            [
                'heading' => 'Stats Players Can Actually Use',
                'body' => 'After a match is analyzed, players can unlock their bib-specific report and keep it connected to their account.',
                'items' => [
                    'See speed, passing, shooting, dribbling, and physical performance indicators.',
                    'Unlock multiple player reports without losing previously paid stats.',
                    'Return to recent matches and review saved analysis history.',
                ],
            ],
            [
                'heading' => 'One Match, Many Player Reports',
                'body' => 'The system is designed so a match is analyzed once, then saved stats can be reused when another player requests their bib report.',
                'items' => [
                    'Avoid unnecessary GPU reprocessing for the same match.',
                    'Retrieve completed reports from the database when available.',
                    'Notify players when their analysis is ready.',
                ],
            ],
            [
                'heading' => 'Progress Over Time',
                'body' => 'Analytics become more valuable as players build a history of matches, team activity, challenges, and unlocks.',
                'items' => [
                    'Track recent match performance from the mobile app.',
                    'Compare progress across fixtures and weekly challenges.',
                    'Keep player identity connected across web and mobile.',
                ],
            ],
        ],
    ],
    'teams' => [
        'title' => 'Team Manager',
        'eyebrow' => 'Manage squads with less friction',
        'intro' => 'Create clubs, organize players, send invitations, and keep team activity tied to matches and analysis.',
        'cta' => ['label' => 'Create Your Team', 'href' => '/register'],
        'sections' => [
            [
                'heading' => 'Build And Maintain Your Club',
                'body' => 'FiveStats gives instructors and organizers the structure needed to manage teams, members, invites, and ownership.',
                'items' => [
                    'Create a club profile and assign players to the squad.',
                    'Invite players and manage team membership from the dashboard.',
                    'Keep team identity visible across fixtures, challenges, and player lists.',
                ],
            ],
            [
                'heading' => 'A Shared Home For Match Activity',
                'body' => 'Teams are connected to matchmaking, weekly challenges, match history, and performance reports so football activity stays organized.',
                'items' => [
                    'See the players attached to each team.',
                    'Use team data when scheduling or approving matches.',
                    'Keep player progress associated with the correct club.',
                ],
            ],
            [
                'heading' => 'Designed For Real Administrators',
                'body' => 'Role-based access keeps player, instructor, and admin workflows separate while still sharing the same platform.',
                'items' => [
                    'Players can access their own team and stats.',
                    'Instructors can manage teams, players, and video workflows.',
                    'Admins can oversee users, matches, announcements, and challenges.',
                ],
            ],
        ],
    ],
    'video-upload' => [
        'title' => 'Video Highlights',
        'eyebrow' => 'Upload, process, and review match recordings',
        'intro' => 'FiveStats connects match videos to AI analysis, player bibs, cloud storage, and long-term match history.',
        'cta' => ['label' => 'Upload A Match Video', 'href' => '/register'],
        'sections' => [
            [
                'heading' => 'Match Video Uploads',
                'body' => 'Organizers can upload match footage, attach it to a scheduled fixture, and prepare player bib assignments before analysis.',
                'items' => [
                    'Support for hosted match recordings and cloud video storage.',
                    'Lineup and bib tools so each player can unlock the correct report.',
                    'Video records stay available from match history where storage rules allow.',
                ],
            ],
            [
                'heading' => 'AI Analysis Pipeline',
                'body' => 'Uploaded videos can be sent to the AI worker, processed on GPU infrastructure, and saved back to the database for reuse.',
                'items' => [
                    'Server-side processing continues independently from a user phone.',
                    'Completed analysis should be retrieved instead of reprocessed.',
                    'Progress and completion states are tracked for the app and dashboard.',
                ],
            ],
            [
                'heading' => 'Better Playback Experience',
                'body' => 'The video experience supports match review, player context, and smoother mobile viewing as recordings move into the current storage system.',
                'items' => [
                    'Keep current videos in the new storage system instead of split legacy sources.',
                    'Use iOS-compatible video encoding for reliable playback.',
                    'Make match recordings easier to review alongside stats.',
                ],
            ],
        ],
    ],
    'about' => [
        'title' => 'About FiveStats',
        'eyebrow' => 'Built for five-a-side football',
        'intro' => 'FiveStats is a football performance platform for players, teams, and organizers who want match data without enterprise complexity.',
        'cta' => ['label' => 'Join FiveStats', 'href' => '/register'],
        'sections' => [
            [
                'heading' => 'Why FiveStats Exists',
                'body' => 'Local football creates great moments every day, but most players never get structured feedback from those matches. FiveStats turns recorded games into accessible player insight.',
                'items' => [
                    'Make performance analysis available beyond professional clubs.',
                    'Give every player a clear way to access their own stats.',
                    'Help organizers connect matches, videos, teams, and results.',
                ],
            ],
            [
                'heading' => 'What The Platform Connects',
                'body' => 'FiveStats brings together the workflows that usually live in separate apps: scheduling, teams, uploads, AI analysis, credits, notifications, and match history.',
                'items' => [
                    'Mobile app for players who want stats and notifications.',
                    'Web dashboard for organizers managing teams and video workflows.',
                    'Server-side AI pipeline for processing match recordings.',
                ],
            ],
            [
                'heading' => 'Our Product Standard',
                'body' => 'The goal is simple: once a match is processed, every eligible player should be able to retrieve their report without wasted processing or confusing repeat payments.',
                'items' => [
                    'Analyze a match once and store reusable results.',
                    'Keep paid player unlocks available in history.',
                    'Notify users as soon as server work is complete.',
                ],
            ],
        ],
    ],
    'privacy' => [
        'title' => 'Privacy Policy',
        'eyebrow' => 'Your data and videos',
        'intro' => 'This policy explains what FiveStats collects, why it is used, and how account, video, payment, and analysis data support the service.',
        'cta' => ['label' => 'Contact Privacy Support', 'href' => '/contact'],
        'sections' => [
            [
                'heading' => 'Information We Collect',
                'body' => 'FiveStats may collect and store the information needed to operate football accounts, teams, matches, payments, videos, and AI reports.',
                'items' => [
                    'Account details such as name, email, role, profile image, and team assignment.',
                    'Match details such as teams, venue, date, time, status, participants, and bib selections.',
                    'Uploaded or hosted match videos and generated AI analysis outputs.',
                    'Credit, checkout, and payment confirmation data from Stripe.',
                ],
            ],
            [
                'heading' => 'How We Use Information',
                'body' => 'Data is used to authenticate users, show relevant dashboard and mobile app content, process payments, run AI analysis, deliver notifications, and provide support.',
                'items' => [
                    'Match videos are used to generate player statistics and playback experiences.',
                    'Push tokens are used to send analysis and account notifications.',
                    'Support details are used to diagnose account, payment, or video issues.',
                ],
            ],
            [
                'heading' => 'Storage And Access',
                'body' => 'Private data is protected by authenticated routes, API checks, role permissions, signed video access, and server-side service credentials where required.',
                'items' => [
                    'Players should only see reports and account data connected to their access.',
                    'Admins and instructors may have broader dashboard access for operational tasks.',
                    'Video retention may be limited by configured storage cleanup rules.',
                ],
            ],
            [
                'heading' => 'Your Choices',
                'body' => 'You can contact support to ask about your account data, report incorrect information, or request help with privacy-related access questions.',
                'items' => [
                    'Do not share your login credentials with others.',
                    'Report unauthorized access as soon as possible.',
                    'Include your account email when requesting privacy support.',
                ],
            ],
        ],
    ],
    'terms' => [
        'title' => 'Terms of Service',
        'eyebrow' => 'Using FiveStats',
        'intro' => 'These terms describe how FiveStats should be used across the website, mobile app, video storage, payments, and AI analysis.',
        'cta' => ['label' => 'Create Account', 'href' => '/register'],
        'sections' => [
            [
                'heading' => 'Account Responsibility',
                'body' => 'You are responsible for using your account honestly, keeping your login secure, and only uploading match content you are allowed to share.',
                'items' => [
                    'Do not upload illegal, abusive, or unrelated content.',
                    'Do not attempt to bypass credits, permissions, or security checks.',
                    'Do not interfere with the platform, API, AI worker, or hosting systems.',
                ],
            ],
            [
                'heading' => 'Credits And Analysis',
                'body' => 'Credits unlock analysis and player statistics according to the product flow shown in the app. AI outputs are performance estimates generated from available match footage.',
                'items' => [
                    'Poor video quality can affect analysis accuracy.',
                    'Processing time can vary depending on video size and GPU availability.',
                    'If a paid analysis fails, contact support with the account and match details.',
                ],
            ],
            [
                'heading' => 'Videos And Rights',
                'body' => 'You should only upload match videos that you are allowed to use and share through FiveStats. You are responsible for making sure participants and venues permit recording.',
                'items' => [
                    'Do not upload unrelated, illegal, abusive, or private content without permission.',
                    'Uploaded videos may be processed, stored, transcoded, or cleaned up as part of the service.',
                    'Poor quality or incompatible files may limit playback or analysis accuracy.',
                ],
            ],
            [
                'heading' => 'Availability',
                'body' => 'FiveStats aims to keep the service available, but maintenance, hosting limits, third-party outages, or external payment and AI provider issues may occasionally affect access.',
                'items' => [
                    'Processing speed can vary depending on GPU and storage availability.',
                    'Notifications depend on device settings, app permissions, and push delivery providers.',
                    'Support can investigate paid analysis failures when sufficient account and match details are provided.',
                ],
            ],
        ],
    ],
    'contact' => [
        'title' => 'Contact',
        'eyebrow' => 'Talk to the FiveStats team',
        'intro' => 'Use this page for account, billing, partnership, field operations, video, or product questions.',
        'cta' => ['label' => 'Email Support', 'href' => 'mailto:support@fivestats.app'],
        'sections' => [
            [
                'heading' => 'Best Way To Reach Us',
                'body' => 'For the fastest answer, include the exact details connected to your question so the team can find the right account, match, or payment.',
                'items' => [
                    'Support: support@fivestats.app',
                    'Website: nutmegplay.fr',
                    'For paid-analysis issues, include account email, match date, bib number, and video filename.',
                ],
            ],
            [
                'heading' => 'What We Can Help With',
                'body' => 'The team can help with credits, Stripe checkout, missing stats, video uploads, RunPod processing, account access, dashboard questions, and team setup.',
                'items' => [
                    'Billing and credit confirmation.',
                    'AI processing stuck or missing statistics.',
                    'iOS or Android video playback reports.',
                    'Team, player, or match setup questions.',
                ],
            ],
            [
                'heading' => 'Partnerships And Venues',
                'body' => 'If you run a field, league, academy, or recurring football group, contact FiveStats to discuss onboarding teams and match workflows.',
                'items' => [],
            ],
        ],
    ],
    'support' => [
        'title' => 'Support',
        'eyebrow' => 'Help when something gets stuck',
        'intro' => 'Find quick fixes for common FiveStats issues and contact the team with the details needed to investigate.',
        'cta' => ['label' => 'Email Support', 'href' => 'mailto:support@fivestats.app'],
        'sections' => [
            [
                'heading' => 'AI Analysis Support',
                'body' => 'If analysis is stuck, missing, or did not notify the player, send the match date, selected bib number, account email, and whether the app was closed during processing.',
                'items' => [
                    'If progress stays at 5%, the server may be waiting for a GPU worker.',
                    'If another player already analyzed the match, the app should retrieve saved stats instead of reprocessing.',
                    'If a paid unlock is missing, include the Stripe checkout time or credit purchase details.',
                ],
            ],
            [
                'heading' => 'Video Support',
                'body' => 'For video playback issues, include your device model, iOS or Android version, and the match video filename.',
                'items' => [
                    'On iOS, video must be encoded in a compatible H.264/AAC MP4 format.',
                    'If audio plays but the picture is black, the file likely needs transcoding.',
                    'If a legacy Google Drive video is missing, mention that it needs migration to the current storage system.',
                ],
            ],
            [
                'heading' => 'Payment Support',
                'body' => 'If Stripe payment succeeds but credits do not appear, send the account email and payment time so the checkout session can be checked.',
                'items' => [
                    'Do not pay again until support has checked the first payment.',
                    'Keep the Stripe receipt or checkout confirmation available.',
                ],
            ],
            [
                'heading' => 'Account And Team Support',
                'body' => 'If you cannot see a team, invite, fixture, or unlocked player report, send the account email and describe what should be visible.',
                'items' => [
                    'Include the team name if the issue is related to club membership.',
                    'Include the opponent and match date if the issue is related to fixtures.',
                    'Include screenshots when the app shows a ready notification but no stats appear.',
                ],
            ],
        ],
    ],
];

$page = $pages[$pageKey] ?? $pages['support'];
$pageTitle = $page['title'] . ' | FiveStats';
$assetPrefix = '/public';
$logoHref = $assetPrefix . '/assets/fivestats-logo.png';
?>
<!DOCTYPE html>
<html class="dark" lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
  <link rel="icon" type="image/png" href="<?= htmlspecialchars($logoHref, ENT_QUOTES, 'UTF-8') ?>">
  <link href="https://fonts.googleapis.com" rel="preconnect">
  <link crossorigin="" href="https://fonts.gstatic.com" rel="preconnect">
  <link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <style>
    :root {
      --bg: #06120c;
      --panel: #0c1d14;
      --panel-2: #10291b;
      --line: rgba(255, 255, 255, 0.11);
      --text: #f5fff8;
      --muted: #a7beae;
      --soft: #d8e8dd;
      --green: #22d36f;
      --green-2: #53ee91;
      --dark: #041008;
    }
    * { box-sizing: border-box; }
    html { background: var(--bg); }
    body {
      margin: 0;
      min-height: 100vh;
      color: var(--text);
      font-family: "Lexend", system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
      background:
        radial-gradient(circle at 12% 0%, rgba(34, 211, 111, 0.16), transparent 32rem),
        radial-gradient(circle at 90% 16%, rgba(34, 211, 111, 0.10), transparent 28rem),
        linear-gradient(180deg, #06120c 0%, #07150f 52%, #050d09 100%);
    }
    a { color: inherit; text-decoration: none; }
    .fs-wrap { width: min(1120px, calc(100% - 40px)); margin: 0 auto; }
    .fs-header {
      position: sticky;
      top: 0;
      z-index: 20;
      border-bottom: 1px solid var(--line);
      background: rgba(6, 18, 12, 0.86);
      backdrop-filter: blur(18px);
    }
    .fs-nav {
      min-height: 76px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 24px;
    }
    .fs-brand { display: inline-flex; align-items: center; gap: 12px; font-weight: 800; font-size: 20px; }
    .fs-logo { width: 40px; height: 40px; border-radius: 12px; object-fit: cover; display: block; }
    .fs-links { display: flex; align-items: center; gap: 20px; color: var(--muted); font-size: 14px; }
    .fs-links a:hover, .fs-footer-links a:hover { color: var(--green-2); }
    .fs-button {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-height: 44px;
      padding: 0 18px;
      border-radius: 999px;
      border: 1px solid transparent;
      background: var(--green);
      color: var(--dark);
      font-weight: 800;
      box-shadow: 0 14px 35px rgba(34, 211, 111, 0.18);
      transition: transform .18s ease, background .18s ease, border-color .18s ease, color .18s ease;
    }
    .fs-button:hover { transform: translateY(-1px); background: var(--green-2); }
    .fs-button.secondary {
      background: transparent;
      color: var(--text);
      border-color: rgba(255,255,255,.16);
      box-shadow: none;
    }
    .fs-button.secondary:hover { color: var(--green-2); border-color: rgba(34, 211, 111, .55); background: rgba(34, 211, 111, .08); }
    .fs-hero { border-bottom: 1px solid var(--line); overflow: hidden; }
    .fs-hero-grid {
      display: grid;
      grid-template-columns: minmax(0, 1.08fr) minmax(280px, .72fr);
      gap: 48px;
      align-items: center;
      padding: 82px 0 76px;
    }
    .fs-eyebrow {
      margin: 0 0 16px;
      color: var(--green-2);
      font-size: 12px;
      font-weight: 800;
      letter-spacing: .22em;
      text-transform: uppercase;
    }
    .fs-hero h1 {
      margin: 0;
      max-width: 760px;
      font-size: clamp(42px, 7vw, 84px);
      line-height: .95;
      letter-spacing: -0.04em;
    }
    .fs-intro {
      max-width: 690px;
      margin: 24px 0 0;
      color: var(--soft);
      font-size: 19px;
      line-height: 1.75;
    }
    .fs-actions { display: flex; flex-wrap: wrap; gap: 12px; margin-top: 32px; }
    .fs-visual {
      position: relative;
      min-height: 360px;
      border: 1px solid rgba(34, 211, 111, .25);
      border-radius: 28px;
      background:
        linear-gradient(135deg, rgba(34, 211, 111, .16), transparent 40%),
        linear-gradient(180deg, rgba(255,255,255,.07), rgba(255,255,255,.02)),
        var(--panel);
      box-shadow: 0 28px 80px rgba(0,0,0,.34);
      overflow: hidden;
    }
    .fs-visual:before {
      content: "";
      position: absolute;
      inset: 24px;
      border: 2px solid rgba(83, 238, 145, .18);
      border-radius: 22px;
    }
    .fs-visual:after {
      content: "";
      position: absolute;
      top: 24px;
      bottom: 24px;
      left: 50%;
      width: 2px;
      background: rgba(83, 238, 145, .16);
    }
    .fs-visual-logo {
      position: absolute;
      width: 164px;
      height: 164px;
      left: 50%;
      top: 50%;
      transform: translate(-50%, -50%);
      border-radius: 36px;
      object-fit: cover;
      box-shadow: 0 24px 65px rgba(0,0,0,.35);
    }
    .fs-stat {
      position: absolute;
      left: 24px;
      right: 24px;
      bottom: 24px;
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 10px;
      z-index: 2;
    }
    .fs-stat div {
      border: 1px solid rgba(255,255,255,.1);
      border-radius: 16px;
      padding: 14px;
      background: rgba(4, 16, 8, .72);
    }
    .fs-stat strong { display: block; font-size: 20px; color: var(--green-2); }
    .fs-stat span { color: var(--muted); font-size: 12px; }
    .fs-content { padding: 64px 0; }
    .fs-cards { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 18px; }
    .fs-card {
      min-height: 100%;
      padding: 28px;
      border: 1px solid var(--line);
      border-radius: 22px;
      background: linear-gradient(180deg, rgba(255,255,255,.06), rgba(255,255,255,.025));
      box-shadow: 0 18px 50px rgba(0,0,0,.18);
    }
    .fs-card h2 { margin: 0 0 12px; font-size: 23px; letter-spacing: -0.02em; }
    .fs-card p { margin: 0; color: var(--muted); line-height: 1.7; }
    .fs-list { display: grid; gap: 12px; margin: 22px 0 0; padding: 0; list-style: none; color: var(--soft); }
    .fs-list li { display: flex; gap: 11px; line-height: 1.55; }
    .fs-dot {
      width: 9px;
      height: 9px;
      margin-top: 8px;
      border-radius: 50%;
      flex: 0 0 auto;
      background: var(--green);
      box-shadow: 0 0 18px rgba(34,211,111,.55);
    }
    .fs-contact {
      margin-top: 18px;
      padding: 30px;
      border: 1px solid rgba(34,211,111,.34);
      border-radius: 24px;
      background: linear-gradient(135deg, rgba(34,211,111,.18), rgba(255,255,255,.04));
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 24px;
    }
    .fs-contact h2 { margin: 0 0 8px; }
    .fs-contact p { margin: 0; color: var(--soft); line-height: 1.6; }
    .fs-footer { border-top: 1px solid var(--line); color: var(--muted); }
    .fs-footer-inner { display: flex; justify-content: space-between; gap: 24px; padding: 30px 0; font-size: 14px; }
    .fs-footer-links { display: flex; flex-wrap: wrap; gap: 14px; }
    @media (max-width: 920px) {
      .fs-links { display: none; }
      .fs-hero-grid { grid-template-columns: 1fr; padding: 58px 0; }
      .fs-visual { min-height: 300px; }
      .fs-cards { grid-template-columns: 1fr; }
      .fs-footer-inner, .fs-contact { flex-direction: column; align-items: flex-start; }
    }
    @media (max-width: 560px) {
      .fs-wrap { width: min(100% - 28px, 1120px); }
      .fs-nav { min-height: 68px; }
      .fs-brand { font-size: 18px; }
      .fs-logo { width: 34px; height: 34px; }
      .fs-hero h1 { font-size: 40px; }
      .fs-intro { font-size: 16px; }
      .fs-visual-logo { width: 126px; height: 126px; }
      .fs-stat { grid-template-columns: 1fr; }
    }
  </style>
</head>
<body>
  <header class="fs-header">
    <div class="fs-wrap fs-nav">
      <a href="/" class="fs-brand">
        <img src="<?= htmlspecialchars($logoHref, ENT_QUOTES, 'UTF-8') ?>" alt="FiveStats" class="fs-logo">
        <span>FiveStats</span>
      </a>
      <nav class="fs-links">
        <a href="/matchmaking">Matchmaking</a>
        <a href="/players">Analytics</a>
        <a href="/teams">Teams</a>
        <a href="/video-upload">Videos</a>
        <a href="/about">About</a>
        <a href="/support">Support</a>
      </nav>
      <a href="<?= $isLoggedIn ? '/dashboard' : '/login' ?>" class="fs-button">
        <?= $isLoggedIn ? 'Dashboard' : 'Sign In' ?>
      </a>
    </div>
  </header>

  <main>
    <section class="fs-hero">
      <div class="fs-wrap fs-hero-grid">
        <div>
          <p class="fs-eyebrow"><?= htmlspecialchars($page['eyebrow'], ENT_QUOTES, 'UTF-8') ?></p>
          <h1><?= htmlspecialchars($page['title'], ENT_QUOTES, 'UTF-8') ?></h1>
          <p class="fs-intro"><?= htmlspecialchars($page['intro'], ENT_QUOTES, 'UTF-8') ?></p>
          <?php if (!empty($page['cta']['href']) && !empty($page['cta']['label'])): ?>
            <div class="fs-actions">
              <a href="<?= htmlspecialchars($page['cta']['href'], ENT_QUOTES, 'UTF-8') ?>" class="fs-button">
                <?= htmlspecialchars($page['cta']['label'], ENT_QUOTES, 'UTF-8') ?>
              </a>
              <a href="/support" class="fs-button secondary">Get Help</a>
            </div>
          <?php endif; ?>
        </div>
        <div class="fs-visual" aria-hidden="true">
          <img src="<?= htmlspecialchars($logoHref, ENT_QUOTES, 'UTF-8') ?>" alt="" class="fs-visual-logo">
          <div class="fs-stat">
            <div><strong>10</strong><span>Players</span></div>
            <div><strong>1x</strong><span>Analysis</span></div>
            <div><strong>24/7</strong><span>Access</span></div>
          </div>
        </div>
      </div>
    </section>

    <section class="fs-wrap fs-content">
      <div class="fs-cards">
        <?php foreach ($page['sections'] as $section): ?>
        <article class="fs-card">
          <h2><?= htmlspecialchars($section['heading'], ENT_QUOTES, 'UTF-8') ?></h2>
          <p><?= htmlspecialchars($section['body'], ENT_QUOTES, 'UTF-8') ?></p>
          <?php if (!empty($section['items'])): ?>
            <ul class="fs-list">
              <?php foreach ($section['items'] as $item): ?>
                <li>
                  <span class="fs-dot"></span>
                  <span><?= htmlspecialchars($item, ENT_QUOTES, 'UTF-8') ?></span>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </article>
        <?php endforeach; ?>
      </div>

      <?php if ($pageKey === 'support' || $pageKey === 'contact'): ?>
        <article class="fs-contact">
          <div>
            <h2>Need direct help?</h2>
            <p>Email support with your account email, match date, bib number, and video filename.</p>
          </div>
          <a href="mailto:support@fivestats.app" class="fs-button">Email Support</a>
        </article>
      <?php endif; ?>
    </section>
  </main>

  <footer class="fs-footer">
    <div class="fs-wrap fs-footer-inner">
      <p>&copy; <?= date('Y') ?> FiveStats. All rights reserved.</p>
      <div class="fs-footer-links">
        <a href="/matchmaking">Matchmaking</a>
        <a href="/players">Analytics</a>
        <a href="/teams">Teams</a>
        <a href="/video-upload">Videos</a>
        <a href="/about">About</a>
        <a href="/privacy">Privacy</a>
        <a href="/terms">Terms</a>
        <a href="/contact">Contact</a>
        <a href="/support">Support</a>
      </div>
    </div>
  </footer>
</body>
</html>
