<?php
$pageTitle = 'Profile & Settings';
$pageHeading = 'Profile & Settings';
$pageDescription = 'Manage your personal information and preferences';
include BASE_PATH . '/includes/header.php';
include BASE_PATH . '/includes/sidenav.php';
include BASE_PATH . '/includes/topnav.php';

$account = $account ?? [];
$error   = $error ?? '';
$success = $success ?? '';
$csrf    = \App\Core\Auth::csrfToken();

$fname     = htmlspecialchars($account['fname'] ?? '');
$lname     = htmlspecialchars($account['lname'] ?? '');
$username  = htmlspecialchars($account['username'] ?? '');
$email     = htmlspecialchars($account['email'] ?? '');
$country   = htmlspecialchars($account['country'] ?? '');
$position  = htmlspecialchars($account['position'] ?? '');
$imageUrl  = $account['image_url'] ?? '';
$role      = $account['role'] ?? 'player';
$level     = $account['level'] ?? 1;
$createdAt = $account['created_at'] ?? '';
?>

<main class="ml-0 md:ml-64 pt-0 min-h-screen bg-background-dark text-white">
    <div class="max-w-3xl mx-auto px-4 md:px-6 py-8 space-y-6">

        <?php if ($success): ?>
        <div class="rounded-xl border border-primary/30 bg-primary/10 px-4 py-3 text-sm text-primary flex items-center gap-2">
            <span class="material-symbols-outlined text-lg">check_circle</span>
            <?= htmlspecialchars($success) ?>
        </div>
        <?php endif; ?>

        <?php if ($error): ?>
        <div class="rounded-xl border border-accent-danger/30 bg-accent-danger/10 px-4 py-3 text-sm text-accent-danger flex items-center gap-2">
            <span class="material-symbols-outlined text-lg">error</span>
            <?= htmlspecialchars($error) ?>
        </div>
        <?php endif; ?>

        <!-- Avatar & Identity Card -->
        <div class="bg-card-dark border border-[#264531] rounded-2xl p-6">
            <div class="flex flex-col md:flex-row items-center md:items-start gap-6">
                <!-- Avatar -->
                <div class="relative group">
                    <div class="size-28 rounded-2xl overflow-hidden border-2 border-[#264531] bg-[#122017] flex items-center justify-center">
                        <?php if ($imageUrl): ?>
                        <img src="<?= htmlspecialchars($imageUrl) ?>" alt="Avatar" class="size-full object-cover" id="avatar-preview">
                        <?php else: ?>
                        <span class="material-symbols-outlined text-5xl text-muted" id="avatar-placeholder">person</span>
                        <img src="" alt="Avatar" class="size-full object-cover hidden" id="avatar-preview">
                        <?php endif; ?>
                    </div>
                    <label class="absolute inset-0 flex items-center justify-center rounded-2xl bg-black/50 opacity-0 group-hover:opacity-100 transition-opacity cursor-pointer">
                        <span class="material-symbols-outlined text-white text-lg">photo_camera</span>
                        <input type="file" accept="image/*" class="hidden" id="avatar-input" form="profile-form" name="avatar" onchange="previewAvatar(this)">
                    </label>
                </div>
                <!-- Identity -->
                <div class="flex-1 text-center md:text-left">
                    <h3 class="text-xl font-bold"><?= $fname ?> <?= $lname ?></h3>
                    <?php if ($username): ?>
                    <p class="text-sm text-muted">@<?= $username ?></p>
                    <?php endif; ?>
                    <div class="mt-3 flex flex-wrap items-center gap-2 justify-center md:justify-start">
                        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold bg-primary/10 text-primary border border-primary/20 capitalize">
                            <span class="material-symbols-outlined text-xs">shield</span>
                            <?= htmlspecialchars($role) ?>
                        </span>
                        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold bg-yellow-500/10 text-yellow-400 border border-yellow-500/20">
                            <span class="material-symbols-outlined text-xs">star</span>
                            Level <?= intval($level) ?>
                        </span>
                        <?php if ($createdAt): ?>
                        <span class="text-xs text-muted">
                            Joined <?= date('M Y', strtotime($createdAt)) ?>
                        </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Edit Form -->
        <form id="profile-form" method="POST" action="/profile" enctype="multipart/form-data" class="space-y-6">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">

            <!-- Personal Info -->
            <div class="bg-card-dark border border-[#264531] rounded-2xl p-6 space-y-5">
                <div class="flex items-center gap-2 mb-1">
                    <span class="material-symbols-outlined text-primary">person</span>
                    <h4 class="text-base font-bold">Personal Information</h4>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <!-- First Name -->
                    <div>
                        <label class="block text-xs font-semibold text-muted mb-1.5" for="fname">First Name <span class="text-accent-danger">*</span></label>
                        <input type="text" id="fname" name="fname" value="<?= $fname ?>" required
                               class="w-full rounded-lg bg-[#122017] border border-[#264531] px-3 py-2.5 text-sm text-white placeholder-text-muted focus:outline-none focus:ring-2 focus:ring-primary/50 focus:border-primary transition-all">
                    </div>
                    <!-- Last Name -->
                    <div>
                        <label class="block text-xs font-semibold text-muted mb-1.5" for="lname">Last Name</label>
                        <input type="text" id="lname" name="lname" value="<?= $lname ?>"
                               class="w-full rounded-lg bg-[#122017] border border-[#264531] px-3 py-2.5 text-sm text-white placeholder-text-muted focus:outline-none focus:ring-2 focus:ring-primary/50 focus:border-primary transition-all">
                    </div>
                </div>

                <!-- Username -->
                <div>
                    <label class="block text-xs font-semibold text-muted mb-1.5" for="username">Username</label>
                    <div class="relative">
                        <span class="absolute left-3 top-1/2 -translate-y-1/2 text-muted text-sm">@</span>
                        <input type="text" id="username" name="username" value="<?= $username ?>"
                               class="w-full rounded-lg bg-[#122017] border border-[#264531] pl-7 pr-3 py-2.5 text-sm text-white placeholder-text-muted focus:outline-none focus:ring-2 focus:ring-primary/50 focus:border-primary transition-all">
                    </div>
                </div>

                <!-- Email (read-only) -->
                <div>
                    <label class="block text-xs font-semibold text-muted mb-1.5" for="email">Email</label>
                    <input type="email" id="email" value="<?= $email ?>" disabled
                           class="w-full rounded-lg bg-[#0e1a12] border border-[#264531]/50 px-3 py-2.5 text-sm text-muted cursor-not-allowed">
                    <p class="text-xs text-muted mt-1">Email cannot be changed here.</p>
                </div>
            </div>

            <!-- Football Profile -->
            <div class="bg-card-dark border border-[#264531] rounded-2xl p-6 space-y-5">
                <div class="flex items-center gap-2 mb-1">
                    <img src="/public/assets/fivestats-logo.png" alt="" class="size-5 rounded-md">
                    <h4 class="text-base font-bold">Football Profile</h4>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <!-- Country -->
                    <div>
                        <label class="block text-xs font-semibold text-muted mb-1.5" for="country">Country</label>
                        <input type="text" id="country" name="country" value="<?= $country ?>" placeholder="e.g. United Kingdom"
                               class="w-full rounded-lg bg-[#122017] border border-[#264531] px-3 py-2.5 text-sm text-white placeholder-text-muted focus:outline-none focus:ring-2 focus:ring-primary/50 focus:border-primary transition-all">
                    </div>
                    <!-- Position -->
                    <div>
                        <label class="block text-xs font-semibold text-muted mb-1.5" for="position">Position</label>
                        <select id="position" name="position"
                                class="w-full rounded-lg bg-[#122017] border border-[#264531] px-3 py-2.5 text-sm text-white focus:outline-none focus:ring-2 focus:ring-primary/50 focus:border-primary transition-all">
                            <option value="">— Select Position —</option>
                            <?php
                            $positions = ['Goalkeeper','Centre Back','Left Back','Right Back','Defensive Midfielder','Central Midfielder','Attacking Midfielder','Left Winger','Right Winger','Striker'];
                            foreach ($positions as $pos):
                            ?>
                            <option value="<?= $pos ?>" <?= $position === $pos ? 'selected' : '' ?>><?= $pos ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Submit -->
            <div class="flex items-center justify-end gap-3">
                <a href="/dashboard" class="px-5 py-2.5 text-sm font-semibold rounded-lg border border-[#264531] text-muted hover:text-white hover:border-white/20 transition-colors">
                    Cancel
                </a>
                <button type="submit" class="px-6 py-2.5 text-sm font-bold rounded-lg bg-primary text-black hover:brightness-110 active:scale-[0.98] transition-all flex items-center gap-2">
                    <span class="material-symbols-outlined text-lg">save</span>
                    Save Changes
                </button>
            </div>
        </form>
    </div>
</main>

<script>
function previewAvatar(input) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            const preview = document.getElementById('avatar-preview');
            const placeholder = document.getElementById('avatar-placeholder');
            preview.src = e.target.result;
            preview.classList.remove('hidden');
            if (placeholder) placeholder.classList.add('hidden');
        };
        reader.readAsDataURL(input.files[0]);
    }
}
</script>

<?php include BASE_PATH . '/includes/footer.php'; ?>
