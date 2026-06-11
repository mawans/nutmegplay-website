<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Auth;
use App\Core\SupabaseClient;
use App\Services\AccountService;
use App\Services\PlayerProgressService;

class AuthController extends Controller
{
    public function login(): void
    {
        if (Auth::check()) {
            header('Location: /dashboard');
            exit;
        }
        $error = Auth::getFlash('error');
        $success = Auth::getFlash('success');
        $this->renderRaw(BASE_PATH . '/app/views/auth/login.php', compact('error', 'success'));
    }

    public function loginAlt(): void
    {
        if (Auth::check()) {
            header('Location: /dashboard');
            exit;
        }
        $error = Auth::getFlash('error');
        $success = Auth::getFlash('success');
        $this->renderRaw(BASE_PATH . '/app/views/auth/login-alt.php', compact('error', 'success'));
    }

    public function register(): void
    {
        if (Auth::check()) {
            header('Location: /dashboard');
            exit;
        }
        $error = Auth::getFlash('error');
        $this->renderRaw(BASE_PATH . '/app/views/auth/login.php', [
            'error' => $error,
            'isRegister' => true,
        ]);
    }

    public function forgotPassword(): void
    {
        if (Auth::check()) {
            header('Location: /dashboard');
            exit;
        }

        $error = Auth::getFlash('error');
        $success = Auth::getFlash('success');
        $this->renderRaw(BASE_PATH . '/app/views/auth/forgot-password.php', compact('error', 'success'));
    }

    public function resetPassword(): void
    {
        $error = Auth::getFlash('error');
        $success = Auth::getFlash('success');
        $this->renderRaw(BASE_PATH . '/app/views/auth/reset-password.php', compact('error', 'success'));
    }

    /** POST /login */
    public function handleLogin(): void
    {
        if (!Auth::verifyCsrf($_POST['_csrf'] ?? null)) {
            Auth::flash('error', 'Invalid request. Please refresh and try again.');
            header('Location: /login');
            exit;
        }

        $email    = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if (!$email || !$password) {
            Auth::flash('error', 'Email and password are required.');
            header('Location: /login');
            exit;
        }

        try {
            $sb = SupabaseClient::getInstance();
            $authResult = $sb->authSignIn($email, $password);
        } catch (\Throwable $e) {
            $this->reportException('auth-login', $e);
            Auth::flash('error', 'Authentication service is unavailable right now. Please try again later.');
            header('Location: /login');
            exit;
        }

        if (!$authResult || !empty($authResult['error'])) {
            $msg = $authResult['message'] ?? 'Invalid email or password.';
            $msgLower = strtolower((string)$msg);
            if (str_contains($msgLower, 'email not confirmed')) {
                $msg = 'Your email is not confirmed yet. Check your inbox (or ask admin to create your user via admin panel).';
            } elseif (str_contains($msgLower, 'rate limit')) {
                $msg = 'Too many login attempts. Please wait a minute and try again.';
            }
            Auth::flash('error', $msg);
            header('Location: /login');
            exit;
        }

        // Fetch account row from accounts table
        $accounts = new AccountService();
        $account = $accounts->getByEmail($email);

        if (!$account) {
            // Maybe first login – create placeholder account row
            $uid = $authResult['user']['id'] ?? '';
            $account = $accounts->create([
                'email'    => $email,
                'uid'      => $uid,
                'fname'    => explode('@', $email)[0],
                'level'    => 1,
            ]);
        }

        Auth::login($authResult, $account ?? []);

        header('Location: /dashboard');
        exit;
    }

    /** POST /register */
    public function handleRegister(): void
    {
        if (!Auth::verifyCsrf($_POST['_csrf'] ?? null)) {
            Auth::flash('error', 'Invalid request. Please refresh and try again.');
            header('Location: /register');
            exit;
        }

        $fname    = trim($_POST['fname'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $country  = trim($_POST['country'] ?? '');
        $position = trim($_POST['position'] ?? '');
        // Public self-registration is always limited to player accounts.
        $role = 'player';

        if (!$email || !$password || !$fname) {
            Auth::flash('error', 'Name, email, and password are required.');
            header('Location: /register');
            exit;
        }

        try {
            $sb = SupabaseClient::getInstance();
            $authResult = $sb->authSignUp($email, $password, ['fname' => $fname]);
        } catch (\Throwable $e) {
            $this->reportException('auth-register', $e);
            Auth::flash('error', 'Authentication service is unavailable right now. Please try again later.');
            header('Location: /register');
            exit;
        }

        if (!$authResult || !empty($authResult['error'])) {
            $msg = $authResult['message'] ?? 'Registration failed. Try a different email.';
            if (str_contains(strtolower($msg), 'rate limit')) {
                $msg = 'Too many signup attempts were made. Please wait a minute before trying again.';
            }
            Auth::flash('error', $msg);
            header('Location: /register');
            exit;
        }

        $uid = $authResult['user']['id'] ?? ($authResult['id'] ?? '');

        // Create accounts row
        $accounts = new AccountService();
        $account = $accounts->create([
            'fname'    => $fname,
            'email'    => $email,
            'uid'      => $uid,
            'country'  => $country,
            'position' => $position,
            'role'     => $role,
            'level'    => 1,
        ]);

        // Create initial player progress
        if ($uid) {
            $progress = new PlayerProgressService();
            $progress->create($uid, 50, 0);
        }

        // If Supabase email confirmation is off, log in immediately
        if (!empty($authResult['access_token'])) {
            Auth::login($authResult, $account ?? []);
            header('Location: /dashboard');
        } else {
            Auth::flash('success', 'Account created! Please check your email to confirm, then log in.');
            header('Location: /login');
        }
        exit;
    }

    /** POST /forgot-password */
    public function handleForgotPassword(): void
    {
        if (!Auth::verifyCsrf($_POST['_csrf'] ?? null)) {
            Auth::flash('error', 'Invalid request. Please refresh and try again.');
            header('Location: /forgot-password');
            exit;
        }

        $email = trim($_POST['email'] ?? '');
        if ($email === '') {
            Auth::flash('error', 'Email is required.');
            header('Location: /forgot-password');
            exit;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Auth::flash('error', 'Enter a valid email address.');
            header('Location: /forgot-password');
            exit;
        }

        try {
            $sb = SupabaseClient::getInstance();
            $redirectTo = $this->resolvePasswordResetUrl();
            $result = $sb->authSendPasswordRecoveryEmail($email, $redirectTo);
        } catch (\Throwable $e) {
            Auth::flash('error', 'Password reset is not configured correctly right now.');
            header('Location: /forgot-password');
            exit;
        }

        if ($result && !empty($result['error'])) {
            $message = strtolower((string)($result['message'] ?? ''));
            if (str_contains($message, 'rate limit')) {
                Auth::flash('error', 'Too many password reset attempts. Please wait a minute and try again.');
                header('Location: /forgot-password');
                exit;
            }
        }

        Auth::flash('success', 'If an account exists for that email, a password reset link has been sent.');
        header('Location: /forgot-password');
        exit;
    }

    /** POST /reset-password */
    public function handleResetPassword(): void
    {
        if (!Auth::verifyCsrf($_POST['_csrf'] ?? null)) {
            Auth::flash('error', 'Invalid request. Please refresh and try again.');
            header('Location: /reset-password');
            exit;
        }

        $accessToken = trim((string)($_POST['access_token'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $passwordConfirmation = (string)($_POST['password_confirmation'] ?? '');

        if ($accessToken === '') {
            Auth::flash('error', 'This reset link is missing or expired. Please request a new one.');
            header('Location: /reset-password');
            exit;
        }

        if (strlen($password) < 8) {
            Auth::flash('error', 'Password must be at least 8 characters long.');
            header('Location: /reset-password');
            exit;
        }

        if ($password !== $passwordConfirmation) {
            Auth::flash('error', 'Passwords do not match.');
            header('Location: /reset-password');
            exit;
        }

        try {
            $sb = SupabaseClient::getInstance();
            $result = $sb->authUpdateUserPassword($accessToken, $password);
        } catch (\Throwable $e) {
            Auth::flash('error', 'Password reset is not configured correctly right now.');
            header('Location: /reset-password');
            exit;
        }

        if (!$result || !empty($result['error'])) {
            $message = strtolower((string)($result['message'] ?? 'Password reset failed.'));
            if (
                str_contains($message, 'expired') ||
                str_contains($message, 'invalid') ||
                str_contains($message, 'jwt') ||
                str_contains($message, 'token')
            ) {
                $friendly = 'This reset link is invalid or expired. Please request a new one.';
            } else {
                $friendly = 'Password could not be updated right now. Please try again.';
            }
            Auth::flash('error', $friendly);
            header('Location: /reset-password');
            exit;
        }

        Auth::logout();
        Auth::flash('success', 'Password updated successfully. You can sign in now.');
        header('Location: /login');
        exit;
    }

    /** GET /logout */
    public function logoutRedirect(): void
    {
        header('Location: ' . (Auth::check() ? '/dashboard' : '/login'));
        exit;
    }

    /** POST /logout */
    public function logout(): void
    {
        if (!Auth::verifyCsrf($_POST['_csrf'] ?? null)) {
            Auth::flash('error', 'Invalid request. Please refresh and try again.');
            header('Location: /dashboard');
            exit;
        }

        Auth::logout();
        header('Location: /login');
        exit;
    }

    /** POST /profile */
    public function updateProfile(): void
    {
        Auth::requireAuth();

        if (!Auth::verifyCsrf($_POST['_csrf'] ?? null)) {
            Auth::flash('error', 'Invalid request. Please refresh and try again.');
            header('Location: /dashboard');
            exit;
        }

        $account = Auth::account();
        $id = (int)($account['id'] ?? 0);

        $data = array_filter([
            'fname'    => trim($_POST['fname'] ?? ''),
            'country'  => trim($_POST['country'] ?? ''),
            'position' => trim($_POST['position'] ?? ''),
        ]);

        if ($id && $data) {
            $accounts = new AccountService();
            $updated = $accounts->update($id, $data);
            if ($updated) {
                Auth::refreshAccount($updated);
            }
        }

        header('Location: /dashboard');
        exit;
    }

    private function resolvePasswordResetUrl(): ?string
    {
        $base = trim((string)(getenv('NUTMEG_WEBSITE_URL') ?: ''));
        if ($base !== '' && !str_contains($base, 'CHANGE_ME')) {
            return rtrim($base, '/') . '/reset-password';
        }

        $forwardedProto = trim((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
        $scheme = $forwardedProto !== ''
            ? trim(explode(',', $forwardedProto)[0])
            : ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http');

        $host = trim((string)($_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? ''));
        if ($host === '') {
            return null;
        }

        return $scheme . '://' . $host . '/reset-password';
    }
}
