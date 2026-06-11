<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Auth;
use App\Services\AccountService;
use App\Services\NotificationService;

class ProfileController extends Controller
{
    /** GET /profile – settings / edit profile page */
    public function index(): void
    {
        Auth::requireAuth();

        $account = Auth::account();
        $error   = Auth::getFlash('error');
        $success = Auth::getFlash('success');

        $this->renderRaw(BASE_PATH . '/app/views/dashboards/profile.php', [
            'account' => $account,
            'error'   => $error,
            'success' => $success,
        ]);
    }

    /** POST /profile – update profile */
    public function update(): void
    {
        Auth::requireAuth();

        if (!Auth::verifyCsrf($_POST['_csrf'] ?? null)) {
            Auth::flash('error', 'Invalid request. Please refresh and try again.');
            header('Location: /profile');
            exit;
        }

        $account  = Auth::account();
        $uid      = (string)($account['uid'] ?? '');
        $accounts = new AccountService();

        $data = [];
        $fields = ['fname', 'lname', 'username', 'country', 'position'];
        foreach ($fields as $field) {
            if (isset($_POST[$field])) {
                $data[$field] = trim($_POST[$field]);
            }
        }

        // Handle avatar upload
        if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['avatar'];
            $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($file['tmp_name']);

            if (in_array($mime, $allowed, true) && $file['size'] <= 5 * 1024 * 1024) {
                $ext = match ($mime) {
                    'image/jpeg' => 'jpg',
                    'image/png'  => 'png',
                    'image/gif'  => 'gif',
                    'image/webp' => 'webp',
                    default      => 'jpg',
                };
                $filename = 'avatar_' . md5($uid) . '_' . time() . '.' . $ext;
                $destDir  = BASE_PATH . '/public/avatars';
                if (!is_dir($destDir)) {
                    mkdir($destDir, 0755, true);
                }
                $destPath = $destDir . '/' . $filename;
                if (move_uploaded_file($file['tmp_name'], $destPath)) {
                    $data['image_url'] = '/avatars/' . $filename;
                }
            } else {
                Auth::flash('error', 'Avatar must be an image (JPG, PNG, GIF, WebP) under 5MB.');
                header('Location: /profile');
                exit;
            }
        }

        if (empty($data)) {
            Auth::flash('error', 'No changes to save.');
            header('Location: /profile');
            exit;
        }

        $updated = $accounts->updateByUid($uid, $data);

        if ($updated) {
            // Refresh the session cache
            Auth::refreshAccount($updated);

            // Send notification
            try {
                $notifs = new NotificationService();
                $notifs->send(
                    $uid,
                    NotificationService::TYPE_PROFILE_UPDATED,
                    'Profile Updated',
                    'Your profile has been updated successfully.',
                    'person',
                    '/profile'
                );
            } catch (\Throwable $e) {
                // Silently ignore notification errors
            }

            Auth::flash('success', 'Profile updated successfully.');
        } else {
            Auth::flash('error', 'Failed to update profile.');
        }

        header('Location: /profile');
        exit;
    }
}
