<?php 
$pageTitle = 'Login - FiveStats';
$csrfToken = \App\Core\Auth::csrfToken();
require_once BASE_PATH . '/includes/header.php';
?>

<div class="min-h-screen flex flex-col lg:flex-row">
    <!-- Left Panel: Hero / Branding -->
    <div class="hidden lg:flex lg:w-1/2 relative bg-gradient-to-br from-[#0a1a10] via-[#122017] to-[#1b3525] items-center justify-center overflow-hidden">
        <!-- Decorative background elements -->
        <div class="absolute inset-0 opacity-10">
            <div class="absolute top-20 left-20 w-64 h-64 rounded-full bg-primary blur-[100px]"></div>
            <div class="absolute bottom-20 right-20 w-48 h-48 rounded-full bg-primary blur-[80px]"></div>
        </div>
        <!-- Pitch line pattern -->
        <div class="absolute inset-0 opacity-5">
            <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-80 h-80 border-2 border-white rounded-full"></div>
            <div class="absolute top-1/2 left-0 right-0 h-px bg-white"></div>
        </div>
        
        <div class="relative z-10 max-w-md text-center px-8">
            <img src="/public/assets/fivestats-logo.png" alt="FiveStats" class="size-20 rounded-2xl mx-auto mb-8 border border-primary/30">
            <h1 class="text-4xl font-bold text-white mb-4 tracking-tight">FiveStats</h1>
            <p class="text-lg text-white/70 mb-8 leading-relaxed">The complete football analytics platform. Track matches, manage teams, and elevate your game.</p>
            
            <!-- Stats row -->
            <div class="grid grid-cols-3 gap-4 mt-12">
                <div class="text-center">
                    <p class="text-2xl font-bold text-primary">2.4K+</p>
                    <p class="text-xs text-white/50 mt-1">Active Players</p>
                </div>
                <div class="text-center">
                    <p class="text-2xl font-bold text-primary">850+</p>
                    <p class="text-xs text-white/50 mt-1">Matches Played</p>
                </div>
                <div class="text-center">
                    <p class="text-2xl font-bold text-primary">120+</p>
                    <p class="text-xs text-white/50 mt-1">Teams</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Right Panel: Login Form -->
    <div class="flex-1 flex items-center justify-center bg-background-light dark:bg-background-dark p-6 lg:p-12">
        <div class="w-full max-w-sm">
            <!-- Mobile logo (visible on small screens) -->
            <div class="lg:hidden text-center mb-8">
                <img src="/public/assets/fivestats-logo.png" alt="FiveStats" class="size-14 rounded-xl mx-auto mb-3">
                <h1 class="text-xl font-bold">FiveStats</h1>
            </div>

            <div class="mb-8">
                <h2 class="text-2xl font-bold tracking-tight">Welcome back</h2>
                <p class="text-slate-500 dark:text-text-muted mt-2 text-sm">Enter your credentials to access your account</p>
            </div>

            <?php if (!empty($error)): ?>
            <div class="mb-4 p-3 rounded bg-red-500/10 border border-red-500/30 text-red-400 text-sm"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <?php if (!empty($success)): ?>
            <div class="mb-4 p-3 rounded bg-primary/10 border border-primary/30 text-primary text-sm"><?= htmlspecialchars($success) ?></div>
            <?php endif; ?>
            
            <form class="space-y-5" method="POST" action="/login" hx-boost="false">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken) ?>">
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-text-muted mb-2">Email Address</label>
                    <div class="relative">
                        <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-xl">mail</span>
                        <input type="email" name="email" required class="w-full pl-10 pr-4 py-3 rounded-lg border border-slate-200 dark:border-[#264531] bg-white dark:bg-[#1a2c22] focus:border-primary focus:ring-2 focus:ring-primary/20 outline-none transition-all text-sm" placeholder="you@example.com">
                    </div>
                </div>
                
                <div>
                    <div class="flex items-center justify-between mb-2">
                        <label class="block text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-text-muted">Password</label>
                        <a href="/forgot-password" class="text-xs text-primary hover:underline">Forgot password?</a>
                    </div>
                    <div class="relative">
                        <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-xl">lock</span>
                        <input id="login-alt-password" type="password" name="password" required class="w-full pl-10 pr-11 py-3 rounded-lg border border-slate-200 dark:border-[#264531] bg-white dark:bg-[#1a2c22] focus:border-primary focus:ring-2 focus:ring-primary/20 outline-none transition-all text-sm" placeholder="Enter your password">
                        <button type="button" class="password-toggle absolute right-3 top-1/2 -translate-y-1/2 text-slate-500 hover:text-primary transition-colors" data-target="login-alt-password" aria-label="Show password" aria-pressed="false">
                            <span class="material-symbols-outlined text-[20px] leading-none">visibility</span>
                        </button>
                    </div>
                </div>

                <div class="flex items-center gap-2">
                    <input type="checkbox" id="remember" class="rounded border-slate-300 dark:border-[#264531] text-primary focus:ring-primary/20">
                    <label for="remember" class="text-sm text-slate-600 dark:text-text-muted">Remember me for 30 days</label>
                </div>
                
                <button type="submit" class="w-full px-4 py-3 bg-primary hover:bg-green-600 text-white font-semibold rounded-lg transition-all duration-200 hover:shadow-lg hover:shadow-primary/25 active:scale-[0.98]">
                    Sign In
                </button>
                
                <div class="relative my-6">
                    <div class="absolute inset-0 flex items-center"><div class="w-full border-t border-slate-200 dark:border-[#264531]"></div></div>
                    <div class="relative flex justify-center text-xs"><span class="bg-background-light dark:bg-background-dark px-3 text-slate-400">or continue with</span></div>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <button type="button" class="flex items-center justify-center gap-2 px-4 py-2.5 border border-slate-200 dark:border-[#264531] rounded-lg hover:bg-slate-50 dark:hover:bg-[#1a2c22] transition-colors text-sm font-medium">
                        <svg class="w-5 h-5" viewBox="0 0 24 24"><path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92a5.06 5.06 0 01-2.2 3.32v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.1z" fill="#4285F4"/><path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/><path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" fill="#FBBC05"/><path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/></svg>
                        Google
                    </button>
                    <button type="button" class="flex items-center justify-center gap-2 px-4 py-2.5 border border-slate-200 dark:border-[#264531] rounded-lg hover:bg-slate-50 dark:hover:bg-[#1a2c22] transition-colors text-sm font-medium">
                        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2C6.477 2 2 6.484 2 12.017c0 4.425 2.865 8.18 6.839 9.504.5.092.682-.217.682-.483 0-.237-.008-.868-.013-1.703-2.782.605-3.369-1.343-3.369-1.343-.454-1.158-1.11-1.466-1.11-1.466-.908-.62.069-.608.069-.608 1.003.07 1.531 1.032 1.531 1.032.892 1.53 2.341 1.088 2.91.832.092-.647.35-1.088.636-1.338-2.22-.253-4.555-1.113-4.555-4.951 0-1.093.39-1.988 1.029-2.688-.103-.253-.446-1.272.098-2.65 0 0 .84-.27 2.75 1.026A9.564 9.564 0 0112 6.844c.85.004 1.705.115 2.504.337 1.909-1.296 2.747-1.027 2.747-1.027.546 1.379.202 2.398.1 2.651.64.7 1.028 1.595 1.028 2.688 0 3.848-2.339 4.695-4.566 4.943.359.309.678.92.678 1.855 0 1.338-.012 2.419-.012 2.747 0 .268.18.58.688.482A10.019 10.019 0 0022 12.017C22 6.484 17.522 2 12 2z"/></svg>
                        GitHub
                    </button>
                </div>
                
                <p class="text-center text-sm text-slate-500 dark:text-text-muted pt-4">
                    Don't have an account? <a href="/register" class="text-primary font-medium hover:underline">Create one free</a>
                </p>
            </form>
        </div>
    </div>
</div>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
