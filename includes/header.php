<?php
// Header include for all pages
?>
<?php
$pageTitle = $pageTitle ?? 'Nutmeg Dashboard';
$extraHead = $extraHead ?? '';
$useDirectPublicAssets = PHP_SAPI === 'cli-server';
$assetPrefix = $useDirectPublicAssets ? '' : '/public';

$compiledCssHref = $assetPrefix . '/assets/app.css';
$compiledCssPath = BASE_PATH . '/public/assets/app.css';
if (is_file($compiledCssPath)) {
    $compiledCssHref .= '?v=' . (string)filemtime($compiledCssPath);
}
$htmxLibraryHref = $assetPrefix . '/vendor/htmx.min.js';
$htmxLibraryPath = BASE_PATH . '/public/vendor/htmx.min.js';
if (is_file($htmxLibraryPath)) {
    $htmxLibraryHref .= '?v=' . (string)filemtime($htmxLibraryPath);
}
$htmxScriptHref = $assetPrefix . '/htmx.js';
$htmxScriptPath = BASE_PATH . '/public/htmx.js';
if (is_file($htmxScriptPath)) {
    $htmxScriptHref .= '?v=' . (string)filemtime($htmxScriptPath);
}
?>
<!DOCTYPE html>
<html class="dark" lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <link href="https://fonts.googleapis.com" rel="preconnect">
    <link crossorigin="" href="https://fonts.gstatic.com" rel="preconnect">
    <link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;600;700&family=Noto+Sans:wght@400;500;700&family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
    <link href="<?= htmlspecialchars($compiledCssHref, ENT_QUOTES, 'UTF-8') ?>" rel="stylesheet">
    <script src="<?= htmlspecialchars($htmxLibraryHref, ENT_QUOTES, 'UTF-8') ?>"></script>
    <script src="<?= htmlspecialchars($htmxScriptHref, ENT_QUOTES, 'UTF-8') ?>"></script>

    <style>
        /* Nutmeg dark palette utility classes.
           These are NOT in the prebuilt Tailwind app.css, so we inject them
           directly here so every dashboard page can use them without rebuilding
           Tailwind (no Node.js on the shared host). Keep names prefixed with
           nm- so they never collide with Tailwind utilities. */
        .nm-bg-page    { background-color: #0a1610 !important; }   /* deepest, body bg */
        .nm-bg-card    { background-color: #1a3325 !important; }   /* outer card */
        .nm-bg-section { background-color: #264531 !important; }   /* lifted panel */
        .nm-bg-recess  { background-color: #0d1f15 !important; }   /* deep / inputs */
        .nm-bg-hover   { background-color: #315743 !important; }   /* hover lift */
        .nm-text-primary  { color: #ffffff !important; }
        .nm-text-secondary{ color: #c8dccf !important; }
        .nm-text-muted    { color: #8fb9a0 !important; }
        .nm-border        { border-color: #2f5d3f !important; }
        .nm-divide > * + * { border-color: #2f5d3f !important; }
        .nm-row-hover:hover { background-color: #315743 !important; }

        /* Force dark dropdowns: Tailwind cannot reach into the OS-rendered
           option list, so this lives outside utility classes. */
        select.nm-select,
        select.nm-select option,
        .nm-select-shell select,
        .nm-select-shell option {
            background-color: #0d1f15;
            color: #ffffff;
            color-scheme: dark;
        }

        /* Searchable lineup dropdown. Replaces the native <select> on each
           jersey slot with an inline button + popover that has a search box
           and a filterable list. Designed to look identical to the native
           select shell so the rest of the lineup card layout never moves. */
        .nm-search-select-wrap {
            position: relative;
            flex: 1 1 auto;
            min-width: 0;
        }
        .nm-search-trigger {
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.5rem;
            text-align: left;
            background-color: transparent;
            color: #ffffff;
            border: 0;
            outline: none;
            padding: 0.55rem 0.75rem;
            font-size: 0.875rem;
            cursor: pointer;
        }
        .nm-search-trigger:focus { box-shadow: inset 0 0 0 1px rgba(34, 197, 94, 0.65); }
        .nm-search-trigger.is-empty .nm-search-trigger-label { color: #8fb9a0; font-style: italic; }
        .nm-search-trigger-caret {
            color: #8fb9a0;
            font-size: 0.7rem;
            flex: 0 0 auto;
            transition: transform 180ms ease;
        }
        [data-search-select][data-open="1"] .nm-search-trigger-caret { transform: rotate(180deg); }

        .nm-search-popover {
            position: absolute;
            top: calc(100% + 4px);
            left: 0;
            right: 0;
            z-index: 60;
            background-color: #163122;
            border: 1px solid #2f5d3f;
            border-radius: 0.5rem;
            box-shadow: 0 12px 32px rgba(0, 0, 0, 0.4);
            overflow: hidden;
            opacity: 0;
            transform: translateY(-4px);
            transition: opacity 160ms ease, transform 160ms ease;
            pointer-events: none;
        }
        [data-search-select][data-open="1"] .nm-search-popover {
            opacity: 1;
            transform: translateY(0);
            pointer-events: auto;
        }
        .nm-search-input {
            width: 100%;
            padding: 0.55rem 0.75rem;
            background-color: #0d1f15;
            color: #ffffff;
            border: 0;
            border-bottom: 1px solid #2f5d3f;
            outline: none;
            font-size: 0.875rem;
        }
        .nm-search-input::placeholder { color: #8fb9a0; }
        .nm-search-list {
            max-height: 240px;
            overflow-y: auto;
            margin: 0;
            padding: 0.25rem 0;
            list-style: none;
        }
        .nm-search-list li {
            padding: 0.5rem 0.75rem;
            cursor: pointer;
            color: #dbeae0;
            font-size: 0.875rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.5rem;
        }
        .nm-search-list li.is-hidden { display: none; }
        .nm-search-list li:hover,
        .nm-search-list li[data-active="1"] { background-color: #264531; color: #ffffff; }
        .nm-search-list li[data-current="1"] {
            background-color: rgba(34, 197, 94, 0.12);
            color: #bbf7d0;
        }
        .nm-search-list li .nm-search-list-meta {
            color: #8fb9a0;
            font-size: 0.75rem;
        }
        .nm-search-empty {
            padding: 0.75rem;
            text-align: center;
            color: #8fb9a0;
            font-size: 0.8rem;
        }

        /* Reusable team tints — used on lineup row badges AND stats badges
           so the two cards feel like the same component. */
        .nm-pill-blue { background-color: rgba(56, 189, 248, 0.18); color: #bae6fd; }
        .nm-pill-red  { background-color: rgba(244, 63, 94, 0.18);  color: #fecdd3; }
        .nm-team-label-blue { color: #bae6fd; }
        .nm-team-label-red  { color: #fecdd3; }

        /* ─── Topbar AI worker transitions ─────────────────────────────
           The renderer used to wipe innerHTML on every status poll, which
           killed any CSS transition. We now keep stable DOM nodes and only
           swap text/classes; these rules give every visual swap a smooth
           300ms ease and animate the dot/icon when state changes. */
        [data-ai-topnav-root] {
            transition: opacity 220ms ease-out;
        }
        [data-ai-topnav-pill],
        [data-ai-topnav-action] {
            transition: background-color 280ms ease, color 280ms ease,
                        border-color 280ms ease, box-shadow 280ms ease,
                        transform 200ms ease;
        }
        [data-ai-topnav-action]:not(:disabled):active {
            transform: scale(0.97);
        }
        [data-ai-topnav-dot] {
            transition: background-color 280ms ease, box-shadow 280ms ease;
            box-shadow: 0 0 0 0 currentColor;
        }
        [data-ai-topnav-root][data-state="ready"] [data-ai-topnav-dot] {
            animation: nm-pulse-ready 1.6s ease-in-out infinite;
        }
        [data-ai-topnav-root][data-state="starting"] [data-ai-topnav-dot],
        [data-ai-topnav-root][data-state="stopping"] [data-ai-topnav-dot] {
            animation: nm-pulse-busy 1s ease-in-out infinite;
        }
        @keyframes nm-pulse-ready {
            0%, 100% { box-shadow: 0 0 0 0 rgba(34, 197, 94, 0.55); }
            70%      { box-shadow: 0 0 0 8px rgba(34, 197, 94, 0); }
        }
        @keyframes nm-pulse-busy {
            0%, 100% { opacity: 1; }
            50%      { opacity: 0.45; }
        }
        [data-ai-topnav-icon] {
            display: inline-block;
            transition: transform 320ms ease, color 280ms ease;
        }
        [data-ai-topnav-root][data-rotating="1"] [data-ai-topnav-icon] {
            animation: nm-spin 0.9s linear infinite;
        }
        @keyframes nm-spin {
            to { transform: rotate(360deg); }
        }
        /* Soft fade when state CHANGES so the eye notices the swap. */
        @keyframes nm-state-pop {
            from { opacity: 0.55; transform: translateY(-1px); }
            to   { opacity: 1;    transform: translateY(0); }
        }
        [data-ai-topnav-root][data-just-changed="1"] [data-ai-topnav-pill],
        [data-ai-topnav-root][data-just-changed="1"] [data-ai-topnav-action] {
            animation: nm-state-pop 320ms ease-out;
        }

        /* Custom scrollbar for sidebar */
        .sidebar-scroll::-webkit-scrollbar {
            width: 4px;
        }
        .sidebar-scroll::-webkit-scrollbar-track {
            background: transparent;
        }
        .sidebar-scroll::-webkit-scrollbar-thumb {
            background: #264531;
            border-radius: 2px;
        }
        
        /* Pulse animation for status indicator */
        .pulse-dot {
            animation: pulse 2s ease-in-out infinite;
        }
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
        
        /* Mobile responsive improvements */
        @media (max-width: 640px) {
            .stat-value { font-size: 1.75rem !important; }
        }
        
        /* Safe area for mobile devices with notches */
        @supports (padding: max(0px)) {
            body {
                padding-left: max(0px, env(safe-area-inset-left));
                padding-right: max(0px, env(safe-area-inset-right));
            }
        }

        #navigation-progress {
            position: fixed;
            inset: 0 0 auto 0;
            height: 3px;
            z-index: 9999;
            pointer-events: none;
            opacity: 0;
            background: rgba(29, 185, 84, 0.08);
            transition: opacity 180ms ease;
        }

        #navigation-progress[data-active="true"] {
            opacity: 1;
        }

        #navigation-progress-bar {
            height: 100%;
            width: 100%;
            transform: scaleX(0);
            transform-origin: left center;
            transition: transform 180ms ease-out;
            will-change: transform;
            background: linear-gradient(90deg, #1db954 0%, #28d467 45%, #6beb98 100%);
            box-shadow: 0 0 18px rgba(29, 185, 84, 0.4);
        }

        .nav-link[data-nav-pending="true"] {
            background: rgba(29, 185, 84, 0.12) !important;
            color: #1db954 !important;
            border-color: rgba(29, 185, 84, 0.3);
            box-shadow: inset 0 0 0 1px rgba(29, 185, 84, 0.18);
        }

        .nav-link[data-nav-pending="true"] .material-symbols-outlined {
            color: #1db954;
        }

        body[data-nav-loading="true"] {
            cursor: progress;
        }

        body[data-nav-loading="true"] a,
        body[data-nav-loading="true"] button {
            cursor: progress;
        }
    </style>
    <?= $extraHead ?>
</head>
<body class="bg-background-light dark:bg-background-dark text-slate-900 dark:text-white font-display min-h-screen overflow-x-hidden transition-colors duration-200" hx-boost="true">
<div id="navigation-progress" aria-hidden="true" data-active="false">
    <div id="navigation-progress-bar"></div>
</div>
<div class="flex w-full min-h-screen">
<div class="flex flex-col w-full min-h-screen">
