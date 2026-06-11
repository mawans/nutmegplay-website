<?php
$useDirectPublicAssets = PHP_SAPI === 'cli-server';
$assetPrefix = $useDirectPublicAssets ? '' : '/public';

$compiledCssHref = $assetPrefix . '/assets/app.css';
$compiledCssPath = BASE_PATH . '/public/assets/app.css';
if (is_file($compiledCssPath)) {
    $compiledCssHref .= '?v=' . (string)filemtime($compiledCssPath);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars(($statusCode ?? 500) . ' | ' . ($pageTitle ?? 'Error')) ?></title>
    <link href="https://fonts.googleapis.com" rel="preconnect">
    <link crossorigin="" href="https://fonts.gstatic.com" rel="preconnect">
    <link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="<?= htmlspecialchars($compiledCssHref, ENT_QUOTES, 'UTF-8') ?>" rel="stylesheet">
</head>
<body class="min-h-screen bg-[#0f1c14] text-white">
    <main class="min-h-screen flex items-center justify-center px-6">
        <section class="w-full max-w-xl rounded-3xl border border-white/10 bg-white/5 p-10 shadow-2xl shadow-black/30">
            <p class="text-sm font-semibold uppercase tracking-[0.25em] text-[#7ddf95]">
                NutmegPlay
            </p>
            <h1 class="mt-4 text-4xl font-black">
                <?= htmlspecialchars((string)($statusCode ?? 500)) ?>
            </h1>
            <h2 class="mt-2 text-2xl font-bold">
                <?= htmlspecialchars((string)($pageTitle ?? 'Something went wrong')) ?>
            </h2>
            <p class="mt-4 text-base leading-7 text-white/75">
                <?= htmlspecialchars((string)($message ?? 'Something went wrong. Please try again later.')) ?>
            </p>
            <div class="mt-8 flex flex-wrap gap-3">
                <a href="/" class="rounded-full bg-[#1db954] px-5 py-3 font-semibold text-[#0f1c14]">
                    Back Home
                </a>
                <a href="/login" class="rounded-full border border-white/15 px-5 py-3 font-semibold text-white/85">
                    Sign In
                </a>
            </div>
        </section>
    </main>
</body>
</html>
