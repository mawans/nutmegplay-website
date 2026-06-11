<?php
$pageTitle = 'Notifications';
$pageHeading = 'Notifications';
$pageDescription = 'Stay up to date with all your activity';
$currentPage = 'notifications';
$notifications = $notifications ?? [];
$unreadCount = $unreadCount ?? 0;
$account = $account ?? [];
$error = $error ?? '';
$success = $success ?? '';
$csrfToken = \App\Core\Auth::csrfToken();

require_once BASE_PATH . '/includes/header.php';
require_once BASE_PATH . '/includes/sidenav.php';
?>

<div class="flex flex-col flex-1 w-full">
<?php require_once BASE_PATH . '/includes/topnav.php'; ?>

<div id="main-content" class="flex flex-col flex-1 overflow-y-auto bg-background-light dark:bg-background-dark ml-0 md:ml-64 transition-all duration-300">
<div class="p-4 md:p-6 lg:p-8 w-full flex-1">
<div class="w-full max-w-4xl mx-auto">

<?php if ($error): ?>
<div class="bg-accent-danger/10 border border-accent-danger/30 text-accent-danger rounded-xl p-4 mb-6 flex items-center gap-3">
    <span class="material-symbols-outlined">error</span>
    <p class="text-sm font-medium"><?= htmlspecialchars($error) ?></p>
</div>
<?php endif; ?>
<?php if ($success): ?>
<div class="bg-primary/10 border border-primary/30 text-primary rounded-xl p-4 mb-6 flex items-center gap-3">
    <span class="material-symbols-outlined">check_circle</span>
    <p class="text-sm font-medium"><?= htmlspecialchars($success) ?></p>
</div>
<?php endif; ?>

<!-- Header with actions -->
<div class="flex items-center justify-between mb-6">
    <div class="flex items-center gap-3">
        <h3 class="text-lg font-bold">All Notifications</h3>
        <?php if ($unreadCount > 0): ?>
        <span class="px-2.5 py-0.5 rounded-full text-xs font-bold bg-accent-danger text-white"><?= $unreadCount ?> unread</span>
        <?php endif; ?>
    </div>
    <?php if ($unreadCount > 0): ?>
    <form method="POST" action="/notifications/read-all" hx-boost="false">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken) ?>">
        <button type="submit" class="text-sm font-medium text-primary hover:text-green-600 transition-colors flex items-center gap-1">
            <span class="material-symbols-outlined text-base">done_all</span>
            Mark all as read
        </button>
    </form>
    <?php endif; ?>
</div>

<!-- Notification List -->
<?php if (!empty($notifications)): ?>
<div class="space-y-3">
<?php foreach ($notifications as $notif):
    $isRead = !empty($notif['is_read']);
    $icon = $notif['icon'] ?? 'notifications';
    $link = $notif['link'] ?? '';
    $type = $notif['type'] ?? 'general';
    $createdAt = $notif['created_at'] ?? '';
    $timeAgo = '';
    if ($createdAt) {
        $diff = time() - strtotime($createdAt);
        if ($diff < 60) $timeAgo = 'Just now';
        elseif ($diff < 3600) $timeAgo = floor($diff / 60) . 'm ago';
        elseif ($diff < 86400) $timeAgo = floor($diff / 3600) . 'h ago';
        elseif ($diff < 604800) $timeAgo = floor($diff / 86400) . 'd ago';
        else $timeAgo = date('M j', strtotime($createdAt));
    }

    // Color by type
    $iconColor = match(true) {
        str_contains($type, 'approved') || str_contains($type, 'accepted') || str_contains($type, 'created') => 'text-primary',
        str_contains($type, 'rejected') || str_contains($type, 'declined') || str_contains($type, 'deleted') => 'text-accent-danger',
        str_contains($type, 'video') => 'text-blue-400',
        str_contains($type, 'challenge') || str_contains($type, 'leaderboard') => 'text-warning',
        str_contains($type, 'fixture') || str_contains($type, 'match') => 'text-primary',
        default => 'text-muted',
    };
    $bgColor = match(true) {
        str_contains($type, 'approved') || str_contains($type, 'accepted') || str_contains($type, 'created') => 'bg-primary/10',
        str_contains($type, 'rejected') || str_contains($type, 'declined') || str_contains($type, 'deleted') => 'bg-accent-danger/10',
        str_contains($type, 'video') => 'bg-blue-400/10',
        str_contains($type, 'challenge') || str_contains($type, 'leaderboard') => 'bg-warning/10',
        default => 'bg-slate-100 dark:bg-white/5',
    };
?>
<div class="bg-white dark:bg-card-dark rounded-xl border <?= $isRead ? 'border-slate-200 dark:border-[#264531]' : 'border-primary/40 dark:border-primary/30' ?> p-4 flex items-start gap-4 transition-all hover:border-primary/30 <?= !$isRead ? 'ring-1 ring-primary/20' : '' ?>">
    <div class="shrink-0 size-10 rounded-full <?= $bgColor ?> flex items-center justify-center">
        <span class="material-symbols-outlined <?= $iconColor ?>"><?= htmlspecialchars($icon) ?></span>
    </div>
    <div class="flex-1 min-w-0">
        <div class="flex items-start justify-between gap-2">
            <div class="flex-1">
                <h4 class="text-sm font-bold <?= $isRead ? 'text-muted' : '' ?>"><?= htmlspecialchars($notif['title'] ?? 'Notification') ?></h4>
                <p class="text-sm <?= $isRead ? 'text-muted/70' : 'text-muted' ?> mt-0.5"><?= htmlspecialchars($notif['message'] ?? '') ?></p>
            </div>
            <div class="flex items-center gap-2 shrink-0">
                <span class="text-xs text-muted whitespace-nowrap"><?= $timeAgo ?></span>
                <?php if (!$isRead): ?>
                <span class="size-2 rounded-full bg-primary shrink-0"></span>
                <?php endif; ?>
            </div>
        </div>
        <div class="flex items-center gap-3 mt-2">
            <?php if ($link): ?>
            <a href="<?= htmlspecialchars($link) ?>" class="text-xs font-medium text-primary hover:text-green-600 transition-colors">View →</a>
            <?php endif; ?>
            <?php if (!$isRead): ?>
            <form method="POST" action="/notifications/read" class="inline" hx-boost="false">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="notification_id" value="<?= (int)($notif['id'] ?? 0) ?>">
                <button type="submit" class="text-xs text-muted hover:text-primary transition-colors">Mark read</button>
            </form>
            <?php endif; ?>
            <form method="POST" action="/notifications/delete" class="inline" hx-boost="false">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="notification_id" value="<?= (int)($notif['id'] ?? 0) ?>">
                <button type="submit" class="text-xs text-muted hover:text-accent-danger transition-colors">Delete</button>
            </form>
        </div>
    </div>
</div>
<?php endforeach; ?>
</div>
<?php else: ?>
<div class="bg-white dark:bg-card-dark rounded-xl p-12 text-center border border-slate-200 dark:border-[#264531]">
    <span class="material-symbols-outlined text-5xl text-muted mb-3 block">notifications_off</span>
    <h3 class="text-lg font-bold mb-2">No Notifications</h3>
    <p class="text-muted text-sm">You're all caught up! Notifications will appear here when there's activity.</p>
</div>
<?php endif; ?>

</div>
</div>
</div>
</div>

</div>
</div>
<?php require_once BASE_PATH . "/includes/footer.php"; ?>
