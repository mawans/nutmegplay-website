<?php 
$isRegister = $isRegister ?? false;
$pageTitle = $isRegister ? 'Register' : 'Login';
$csrfToken = \App\Core\Auth::csrfToken();
require_once BASE_PATH . '/includes/header.php';
?>

<div class="min-h-screen flex items-center justify-center bg-background-light dark:bg-background-dark p-4">
    <div class="w-full max-w-md">
        <div class="bg-white dark:bg-card-dark border border-slate-200 dark:border-[#264531] rounded-lg p-8">
            <div class="text-center mb-8">
                <img src="/public/assets/fivestats-logo.png" alt="FiveStats" class="size-16 rounded-2xl mx-auto mb-4">
                <h1 class="text-2xl font-bold mb-2"><?= $isRegister ? 'Create Your Account' : 'Welcome to FiveStats' ?></h1>
                <p class="text-slate-500 dark:text-text-muted"><?= $isRegister ? 'Join the pitch' : 'Sign in to your account' ?></p>
            </div>

            <?php if (!empty($error)): ?>
            <div class="mb-4 p-3 rounded bg-red-500/10 border border-red-500/30 text-red-400 text-sm"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <?php if (!empty($success)): ?>
            <div class="mb-4 p-3 rounded bg-primary/10 border border-primary/30 text-primary text-sm"><?= htmlspecialchars($success) ?></div>
            <?php endif; ?>
            
            <form class="space-y-4" method="POST" action="<?= $isRegister ? '/register' : '/login' ?>" hx-boost="false">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken) ?>">
                <?php if ($isRegister): ?>
                <div>
                    <label class="block text-sm font-medium mb-2">Full Name</label>
                    <input type="text" name="fname" required class="w-full px-4 py-2 rounded border border-slate-200 dark:border-[#264531] bg-white dark:bg-background-dark focus:border-primary focus:ring-1 focus:ring-primary outline-none" placeholder="Your name">
                </div>
                <?php endif; ?>

                <div>
                    <label class="block text-sm font-medium mb-2">Email</label>
                    <input type="email" name="email" required class="w-full px-4 py-2 rounded border border-slate-200 dark:border-[#264531] bg-white dark:bg-background-dark focus:border-primary focus:ring-1 focus:ring-primary outline-none" placeholder="you@example.com">
                </div>
                
                <div>
                    <label class="block text-sm font-medium mb-2">Password</label>
                    <div class="relative">
                        <input id="login-password" type="password" name="password" required class="w-full px-4 pr-11 py-2 rounded border border-slate-200 dark:border-[#264531] bg-white dark:bg-background-dark focus:border-primary focus:ring-1 focus:ring-primary outline-none" placeholder="••••••••">
                        <button type="button" class="password-toggle absolute right-3 top-1/2 -translate-y-1/2 text-slate-500 hover:text-primary transition-colors" data-target="login-password" aria-label="Show password" aria-pressed="false">
                            <span class="material-symbols-outlined text-[20px] leading-none">visibility</span>
                        </button>
                    </div>
                    <?php if (!$isRegister): ?>
                    <p class="mt-2 text-right text-sm">
                        <a href="/forgot-password" class="text-primary hover:underline">Forgot password?</a>
                    </p>
                    <?php endif; ?>
                </div>

                <?php if ($isRegister): ?>
                <div>
                    <label class="block text-sm font-medium mb-2">Position</label>
                    <select name="position" class="w-full px-4 py-2 rounded border border-slate-200 dark:border-[#264531] bg-white dark:bg-background-dark focus:border-primary focus:ring-1 focus:ring-primary outline-none">
                        <option value="">Select position</option>
                        <option value="GK">Goalkeeper</option>
                        <option value="DEF">Defender</option>
                        <option value="MID">Midfielder</option>
                        <option value="FWD">Forward</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium mb-2">Country</label>
                    <input type="text" name="country" class="w-full px-4 py-2 rounded border border-slate-200 dark:border-[#264531] bg-white dark:bg-background-dark focus:border-primary focus:ring-1 focus:ring-primary outline-none" placeholder="Your country">
                </div>
                <p class="rounded border border-slate-200 dark:border-[#264531] bg-slate-50 dark:bg-background-dark px-4 py-3 text-sm text-slate-600 dark:text-text-muted">
                    New accounts are created as <span class="font-semibold text-primary">players</span>. Instructor and admin access must be assigned from the admin panel.
                </p>
                <?php endif; ?>
                
                <button type="submit" class="w-full px-4 py-3 bg-primary hover:bg-green-600 text-white font-medium rounded transition-colors">
                    <?= $isRegister ? 'Create Account' : 'Sign In' ?>
                </button>
                
                <p class="text-center text-sm text-slate-500 dark:text-text-muted">
                    <?php if ($isRegister): ?>
                        Already have an account? <a href="/login" class="text-primary hover:underline">Sign in</a>
                    <?php else: ?>
                        Don't have an account? <a href="/register" class="text-primary hover:underline">Sign up</a>
                    <?php endif; ?>
                </p>
            </form>
        </div>
    </div>
</div>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
