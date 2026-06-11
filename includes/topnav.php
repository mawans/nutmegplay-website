<?php
// Top navigation bar for dashboard pages
$pageHeading = $pageHeading ?? $pageTitle ?? 'Dashboard';
$pageDescription = $pageDescription ?? '';

// Notifications are loaded after the shell renders so shared layout queries
// do not block every authenticated page.
$_topnavUnreadCount = 0;
$_topnavLatest = [];
$_topnavCsrf = \App\Core\Auth::csrfToken();
?>
<!-- Top Navigation Bar -->
<header id="topnav" class="sticky top-0 z-40 h-16 ml-0 md:ml-64 border-b nm-border flex items-center justify-between px-4 md:px-6 shrink-0 nm-bg-card backdrop-blur-sm transition-all duration-300 relative">
    <div class="flex items-center gap-4">
        <button class="text-slate-700 dark:text-white hover:text-primary transition-colors" id="mobile-menu-toggle" onclick="toggleMobileMenu()">
            <span class="material-symbols-outlined">menu</span>
        </button>
        <div>
            <h2 class="text-lg md:text-xl font-bold tracking-tight"><?= htmlspecialchars($pageHeading) ?></h2>
            <?php if ($pageDescription): ?>
            <p class="text-xs md:text-sm text-slate-500 dark:text-text-muted hidden md:block"><?= htmlspecialchars($pageDescription) ?></p>
            <?php endif; ?>
        </div>
    </div>
    <div class="flex items-center gap-3">
        <div id="page-topnav-status-slot" class="hidden md:flex items-center gap-4 pr-4 border-r nm-border"></div>
        <!-- Notifications Dropdown -->
        <div class="relative" id="notif-dropdown-container">
            <button onclick="toggleNotifDropdown()" class="relative p-2 text-slate-600 dark:text-text-muted hover:text-slate-900 dark:hover:text-white transition-colors" id="notif-bell-btn">
                <span class="material-symbols-outlined">notifications</span>
                <?php if ($_topnavUnreadCount > 0): ?>
                <span id="notif-badge" class="absolute top-1 right-1 min-w-[18px] h-[18px] flex items-center justify-center bg-accent-danger text-white text-[10px] font-bold rounded-full px-1 border-2 nm-border"><?= $_topnavUnreadCount > 99 ? '99+' : $_topnavUnreadCount ?></span>
                <?php else: ?>
                <span id="notif-badge" class="absolute top-1 right-1 min-w-[18px] h-[18px] flex items-center justify-center bg-accent-danger text-white text-[10px] font-bold rounded-full px-1 border-2 nm-border hidden">0</span>
                <?php endif; ?>
            </button>
            <!-- Dropdown panel -->
            <div id="notif-dropdown" class="hidden absolute right-0 top-full mt-2 w-80 md:w-96 nm-bg-card border nm-border rounded-xl shadow-2xl overflow-hidden z-50">
                <div class="px-4 py-3 border-b nm-border flex items-center justify-between">
                    <h4 class="text-sm font-bold">Notifications</h4>
                    <a href="/notifications" class="text-xs font-medium text-primary hover:text-green-600 transition-colors">View all →</a>
                </div>
                <div id="notif-dropdown-list" class="max-h-80 overflow-y-auto">
                    <?php if (!empty($_topnavLatest)): ?>
                    <?php foreach ($_topnavLatest as $n):
                        $nIcon = $n['icon'] ?? 'notifications';
                        $nTime = '';
                        if (!empty($n['created_at'])) {
                            $d = time() - strtotime($n['created_at']);
                            if ($d < 60) $nTime = 'now';
                            elseif ($d < 3600) $nTime = floor($d/60) . 'm';
                            elseif ($d < 86400) $nTime = floor($d/3600) . 'h';
                            else $nTime = floor($d/86400) . 'd';
                        }
                    ?>
                    <a href="<?= htmlspecialchars($n['link'] ?? '/notifications') ?>" class="flex items-start gap-3 px-4 py-3 nm-row-hover transition-colors border-b nm-border last:border-0">
                        <div class="shrink-0 size-8 rounded-full bg-primary/10 flex items-center justify-center">
                            <span class="material-symbols-outlined text-primary text-base"><?= htmlspecialchars($nIcon) ?></span>
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-semibold truncate"><?= htmlspecialchars($n['title'] ?? '') ?></p>
                            <p class="text-xs text-muted truncate"><?= htmlspecialchars($n['message'] ?? '') ?></p>
                        </div>
                        <span class="text-[10px] text-muted shrink-0 mt-0.5"><?= $nTime ?></span>
                    </a>
                    <?php endforeach; ?>
                    <?php else: ?>
                    <div class="px-4 py-8 text-center text-muted">
                        <span class="material-symbols-outlined text-2xl mb-1 block">notifications_off</span>
                        <p class="text-xs">No new notifications</p>
                    </div>
                    <?php endif; ?>
                </div>
                <?php if ($_topnavUnreadCount > 0): ?>
                <div class="px-4 py-2 border-t nm-border">
                    <form method="POST" action="/notifications/read-all" hx-boost="false">
                        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($_topnavCsrf) ?>">
                        <button type="submit" class="text-xs font-medium text-primary hover:text-green-600 transition-colors w-full text-center">Mark all as read</button>
                    </form>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</header>

<script>
// Notification dropdown toggle
function toggleNotifDropdown() {
    const dd = document.getElementById('notif-dropdown');
    dd.classList.toggle('hidden');
}
// Close dropdown on outside click
document.addEventListener('click', function(e) {
    const container = document.getElementById('notif-dropdown-container');
    if (container && !container.contains(e.target)) {
        document.getElementById('notif-dropdown')?.classList.add('hidden');
    }
});

// Notification polling
(function() {
    let lastCount = <?= $_topnavUnreadCount ?>;

    async function pollNotifications() {
        try {
            const resp = await fetch('/notifications/api', { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            if (!resp.ok) return;
            const data = await resp.json();
            const badge = document.getElementById('notif-badge');
            if (data.count > 0) {
                badge.textContent = data.count > 99 ? '99+' : data.count;
                badge.classList.remove('hidden');
            } else {
                badge.classList.add('hidden');
            }
            lastCount = data.count;

            // Update dropdown list
            const list = document.getElementById('notif-dropdown-list');
            if (list && data.notifications && data.notifications.length > 0) {
                list.replaceChildren(...data.notifications.map(createNotificationRow));
            } else if (list && (!data.notifications || data.notifications.length === 0)) {
                renderEmptyNotifications(list);
            }
        } catch(e) {}
    }

    function notificationAge(createdAt) {
        if (!createdAt) return '';
        const created = new Date(createdAt).getTime();
        if (!Number.isFinite(created)) return '';
        const age = Math.floor((Date.now() - created) / 1000);
        if (age < 60) return 'now';
        if (age < 3600) return Math.floor(age / 60) + 'm';
        if (age < 86400) return Math.floor(age / 3600) + 'h';
        return Math.floor(age / 86400) + 'd';
    }

    function safeNotificationLink(link) {
        if (typeof link !== 'string' || link.trim() === '') {
            return '/notifications';
        }

        try {
            const parsed = new URL(link, window.location.origin);
            if (parsed.origin !== window.location.origin) {
                return '/notifications';
            }
            return parsed.pathname + parsed.search + parsed.hash;
        } catch (e) {
            return '/notifications';
        }
    }

    function createNotificationRow(notification) {
        const anchor = document.createElement('a');
        anchor.href = safeNotificationLink(notification.link);
        anchor.className = 'flex items-start gap-3 px-4 py-3 hover:bg-slate-50 dark:hover:bg-[#1f3629] transition-colors border-b border-slate-100 dark:border-[#264531]/50 last:border-0';

        const iconWrap = document.createElement('div');
        iconWrap.className = 'shrink-0 size-8 rounded-full bg-primary/10 flex items-center justify-center';

        const icon = document.createElement('span');
        icon.className = 'material-symbols-outlined text-primary text-base';
        icon.textContent = typeof notification.icon === 'string' && notification.icon !== '' ? notification.icon : 'notifications';
        iconWrap.appendChild(icon);

        const body = document.createElement('div');
        body.className = 'flex-1 min-w-0';

        const title = document.createElement('p');
        title.className = 'text-sm font-semibold truncate';
        title.textContent = typeof notification.title === 'string' ? notification.title : '';

        const message = document.createElement('p');
        message.className = 'text-xs text-muted truncate';
        message.textContent = typeof notification.message === 'string' ? notification.message : '';

        body.append(title, message);

        const time = document.createElement('span');
        time.className = 'text-[10px] text-muted shrink-0 mt-0.5';
        time.textContent = notificationAge(notification.created_at);

        anchor.append(iconWrap, body, time);
        return anchor;
    }

    function renderEmptyNotifications(list) {
        const wrapper = document.createElement('div');
        wrapper.className = 'px-4 py-8 text-center text-muted';

        const icon = document.createElement('span');
        icon.className = 'material-symbols-outlined text-2xl mb-1 block';
        icon.textContent = 'notifications_off';

        const text = document.createElement('p');
        text.className = 'text-xs';
        text.textContent = 'No new notifications';

        wrapper.append(icon, text);
        list.replaceChildren(wrapper);
    }

    pollNotifications();

    // Poll every 30 seconds
    setInterval(pollNotifications, 30000);
})();
</script>
