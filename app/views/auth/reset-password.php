<?php
$pageTitle = 'Reset Password';
$csrfToken = \App\Core\Auth::csrfToken();
require_once BASE_PATH . '/includes/header.php';
?>

<div class="min-h-screen flex items-center justify-center bg-background-light dark:bg-background-dark p-4">
    <div class="w-full max-w-md">
        <div class="bg-white dark:bg-card-dark border border-slate-200 dark:border-[#264531] rounded-lg p-8">
            <div class="text-center mb-8">
                <div class="size-16 rounded-full bg-primary/10 flex items-center justify-center mx-auto mb-4">
                    <span class="material-symbols-outlined text-primary text-4xl">vpn_key</span>
                </div>
                <h1 class="text-2xl font-bold mb-2">Choose a New Password</h1>
                <p class="text-slate-500 dark:text-text-muted">Open the reset link from your email, then set a new password here.</p>
            </div>

            <div id="reset-status">
                <?php if (!empty($error)): ?>
                <div class="mb-4 p-3 rounded bg-red-500/10 border border-red-500/30 text-red-400 text-sm"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>
                <?php if (!empty($success)): ?>
                <div class="mb-4 p-3 rounded bg-primary/10 border border-primary/30 text-primary text-sm"><?= htmlspecialchars($success) ?></div>
                <?php endif; ?>
            </div>

            <div id="reset-missing-token" class="mb-4 p-3 rounded border border-slate-200 dark:border-[#264531] bg-slate-50 dark:bg-background-dark text-sm text-slate-600 dark:text-text-muted">
                This page needs the recovery token from the email link. Open the reset link from your inbox, then submit your new password.
            </div>

            <form id="reset-password-form" class="space-y-4 hidden" method="POST" action="/reset-password" hx-boost="false">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="access_token" id="reset-access-token" value="">

                <div>
                    <label class="block text-sm font-medium mb-2">New Password</label>
                    <input id="reset-password" type="password" name="password" required minlength="8" class="w-full px-4 py-2 rounded border border-slate-200 dark:border-[#264531] bg-white dark:bg-background-dark focus:border-primary focus:ring-1 focus:ring-primary outline-none" placeholder="At least 8 characters">
                </div>

                <div>
                    <label class="block text-sm font-medium mb-2">Confirm New Password</label>
                    <input id="reset-password-confirmation" type="password" name="password_confirmation" required minlength="8" class="w-full px-4 py-2 rounded border border-slate-200 dark:border-[#264531] bg-white dark:bg-background-dark focus:border-primary focus:ring-1 focus:ring-primary outline-none" placeholder="Repeat your new password">
                </div>

                <button type="submit" class="w-full px-4 py-3 bg-primary hover:bg-green-600 text-white font-medium rounded transition-colors">
                    Update Password
                </button>

                <p class="text-center text-sm text-slate-500 dark:text-text-muted">
                    Need a new email? <a href="/forgot-password" class="text-primary hover:underline">Request another reset link</a>
                </p>
            </form>
        </div>
    </div>
</div>

<script>
(() => {
    const hashParams = new URLSearchParams(window.location.hash.replace(/^#/, ''));
    const queryParams = new URLSearchParams(window.location.search);
    const token = hashParams.get('access_token') || queryParams.get('access_token') || '';
    const errorDescription = hashParams.get('error_description') || queryParams.get('error_description') || '';
    const errorCode = hashParams.get('error') || queryParams.get('error') || '';

    const form = document.getElementById('reset-password-form');
    const tokenInput = document.getElementById('reset-access-token');
    const missingToken = document.getElementById('reset-missing-token');
    const status = document.getElementById('reset-status');

    if (errorDescription !== '' || errorCode !== '') {
        const box = document.createElement('div');
        box.className = 'mb-4 p-3 rounded bg-red-500/10 border border-red-500/30 text-red-400 text-sm';
        box.textContent = decodeURIComponent((errorDescription || errorCode).replace(/\+/g, ' '));
        status.prepend(box);
    }

    if (token !== '') {
        tokenInput.value = token;
        form.classList.remove('hidden');
        missingToken.classList.add('hidden');
        window.history.replaceState({}, document.title, window.location.pathname);
    }
})();
</script>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
