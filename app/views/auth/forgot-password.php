<?php
$pageTitle = 'Forgot Password';
$csrfToken = \App\Core\Auth::csrfToken();
require_once BASE_PATH . '/includes/header.php';
?>

<div class="min-h-screen flex items-center justify-center bg-background-light dark:bg-background-dark p-4">
    <div class="w-full max-w-md">
        <div class="bg-white dark:bg-card-dark border border-slate-200 dark:border-[#264531] rounded-lg p-8">
            <div class="text-center mb-8">
                <div class="size-16 rounded-full bg-primary/10 flex items-center justify-center mx-auto mb-4">
                    <span class="material-symbols-outlined text-primary text-4xl">lock_reset</span>
                </div>
                <h1 class="text-2xl font-bold mb-2">Reset Your Password</h1>
                <p class="text-slate-500 dark:text-text-muted">Enter your email and we will send you a reset link.</p>
            </div>

            <?php if (!empty($error)): ?>
            <div class="mb-4 p-3 rounded bg-red-500/10 border border-red-500/30 text-red-400 text-sm"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <?php if (!empty($success)): ?>
            <div class="mb-4 p-3 rounded bg-primary/10 border border-primary/30 text-primary text-sm"><?= htmlspecialchars($success) ?></div>
            <?php endif; ?>

            <form class="space-y-4" method="POST" action="/forgot-password" hx-boost="false">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken) ?>">

                <div>
                    <label class="block text-sm font-medium mb-2">Email</label>
                    <input type="email" name="email" required class="w-full px-4 py-2 rounded border border-slate-200 dark:border-[#264531] bg-white dark:bg-background-dark focus:border-primary focus:ring-1 focus:ring-primary outline-none" placeholder="you@example.com">
                </div>

                <button type="submit" class="w-full px-4 py-3 bg-primary hover:bg-green-600 text-white font-medium rounded transition-colors">
                    Send Reset Link
                </button>

                <p class="text-center text-sm text-slate-500 dark:text-text-muted">
                    Remembered it? <a href="/login" class="text-primary hover:underline">Back to sign in</a>
                </p>
            </form>
        </div>
    </div>
</div>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
