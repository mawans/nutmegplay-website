<?php
// Sidenav include for all pages
use App\Core\Auth;
$_sidenavUser = Auth::name();
$_sidenavAvatar = Auth::avatar();
$_sidenavCsrf = Auth::csrfToken();
$_sidenavCanManageMatchmaking = Auth::can('matchmaking.manage');
$_sidenavCanManageVideo = Auth::can('video.manage');
$_sidenavCanViewInstructor = Auth::can('instructor.view');
$_sidenavCanViewAdmin = Auth::can('admin.view');
?>
<!-- Mobile Overlay -->
<div id="sidebar-overlay" class="fixed inset-0 bg-black/50 z-40 hidden" onclick="closeMobileMenu()"></div>

<!-- Sidebar -->
<aside id="sidebar"
    class="flex flex-col w-64 h-screen nm-bg-card border-r nm-border fixed left-0 top-0 z-50 transform -translate-x-full md:translate-x-0 transition-all duration-300 ease-in-out"
    data-collapsed="false">
    <div class="p-4 flex items-center justify-between h-16 border-b nm-border">
        <div class="flex items-center gap-3 sidebar-expanded-content">
            <img src="/public/assets/fivestats-logo.png" alt="FiveStats" class="size-8 rounded-lg shrink-0">
            <h2 class="text-slate-900 dark:text-white text-xl font-bold leading-tight tracking-[-0.015em]">FiveStats</h2>
        </div>
        <div class="sidebar-collapsed-content" style="display: none;">
            <img src="/public/assets/fivestats-logo.png" alt="FiveStats" class="size-8 rounded-lg shrink-0">
        </div>
        <button class="md:hidden text-slate-500 hover:text-slate-900 dark:text-text-muted dark:hover:text-white"
            onclick="closeMobileMenu()">
            <span class="material-symbols-outlined">close</span>
        </button>
    </div>
    <nav class="flex-1 px-2 space-y-1 overflow-y-auto sidebar-scroll py-4">
        <?php 
        $current = $currentPage ?? '';
        $navItems = [
            ['url' => '/dashboard', 'icon' => 'dashboard', 'label' => 'Dashboard', 'key' => 'dashboard'],
            ['url' => '/fixtures', 'icon' => 'event', 'label' => 'Fixtures', 'key' => 'fixtures'],
            ['url' => '/challenges', 'icon' => 'emoji_events', 'label' => 'Challenges', 'key' => 'challenges'],
            ['url' => '/weekly-challenges', 'icon' => 'leaderboard', 'label' => 'Weekly Challenges', 'key' => 'weekly-challenges'],
            ['url' => '/players', 'icon' => 'groups', 'label' => 'Players', 'key' => 'players'],
            ['url' => '/teams', 'icon' => 'shield', 'label' => 'Teams', 'key' => 'teams'],
            ['url' => '/match-history', 'icon' => 'history', 'label' => 'Match History', 'key' => 'match-history'],
            ['url' => '/notifications', 'icon' => 'notifications', 'label' => 'Notifications', 'key' => 'notifications'],
            ['url' => '/profile', 'icon' => 'manage_accounts', 'label' => 'Profile & Settings', 'key' => 'profile'],
            ['url' => '/support', 'icon' => 'support_agent', 'label' => 'Support', 'key' => 'support'],
        ];
        if ($_sidenavCanManageMatchmaking) {
            array_splice($navItems, 1, 0, [[
                'url' => '/matchmaking',
                'icon' => 'handshake',
                'label' => 'Matchmaking',
                'key' => 'matchmaking',
            ]]);
        }
        if ($_sidenavCanManageVideo) {
            array_splice($navItems, 7, 0, [[
                'url' => '/video-upload',
                'icon' => 'video_library',
                'label' => 'Video Upload',
                'key' => 'video-upload',
            ]]);
        }
        if ($_sidenavCanViewInstructor) {
            $navItems[] = ['url' => '/instructor', 'icon' => 'school', 'label' => 'Instructor', 'key' => 'instructor'];
        }
        if ($_sidenavCanViewAdmin) {
            $navItems[] = ['url' => '/admin', 'icon' => 'admin_panel_settings', 'label' => 'Admin Panel', 'key' => 'admin'];
        }
        
        foreach ($navItems as $item):
            $isActive = $current === $item['key'];
            $activeClass = $isActive ? 'bg-primary/10 text-primary' : 'text-slate-600 dark:text-text-muted hover:bg-slate-100 dark:hover:bg-[#1f3629] hover:text-primary dark:hover:text-white';
        ?>
        <a class="nav-link flex items-center gap-4 px-3 py-3 rounded-xl <?= $activeClass ?> transition-all group"
            href="<?= $item['url'] ?>" title="<?= $item['label'] ?>" data-page="<?= $item['key'] ?>">
            <span
                class="material-symbols-outlined shrink-0 <?= $isActive ? '' : 'group-hover:text-primary transition-colors' ?>"><?= $item['icon'] ?></span>
            <span
                class="sidebar-expanded-content <?= $isActive ? 'font-bold' : 'font-medium' ?> text-sm whitespace-nowrap"><?= $item['label'] ?></span>
        </a>
        <?php endforeach; ?>
    </nav>
    <div class="p-3 mt-auto border-t nm-border">

        <div
            class="flex items-center gap-3 p-2 rounded-xl hover:bg-slate-100 dark:hover:bg-[#1f3629] cursor-pointer transition-colors">
            <div class="bg-center bg-no-repeat bg-cover rounded-full size-10 border-2 border-primary shrink-0"
                style='background-image: url("<?= htmlspecialchars($_sidenavAvatar ?: 'https://ui-avatars.com/api/?name=' . urlencode($_sidenavUser) . '&background=1db954&color=fff') ?>");'>
            </div>
            <div class="sidebar-expanded-content flex-1 min-w-0">
                <p class="text-sm font-bold text-slate-900 dark:text-white truncate"><?= htmlspecialchars($_sidenavUser) ?></p>
                <p class="text-xs text-slate-500 dark:text-text-muted truncate">Online</p>
            </div>
            <span class="sidebar-expanded-content material-symbols-outlined text-slate-400">settings</span>
        </div>
        <form action="/logout" method="POST" class="sidebar-expanded-content mt-3" hx-boost="false">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($_sidenavCsrf) ?>">
            <button type="submit"
                class="w-full flex items-center justify-center gap-2 text-xs font-medium text-slate-500 dark:text-text-muted hover:text-accent-danger transition-colors">
                <span class="material-symbols-outlined text-sm">logout</span>
                Sign Out
            </button>
        </form>
    </div>
</aside>
