<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Auth;
use App\Services\NotificationService;

class NotificationController extends Controller
{
    /** GET /notifications – full notifications page */
    public function index(): void
    {
        Auth::requireAuth();

        $uid     = Auth::uid();
        $notifs  = new NotificationService();
        $all     = $notifs->listForUser($uid, 100);
        $unread  = $notifs->countUnread($uid);
        $account = Auth::account();
        $error   = Auth::getFlash('error');
        $success = Auth::getFlash('success');

        $this->renderRaw(BASE_PATH . '/app/views/dashboards/notifications.php', [
            'account'       => $account,
            'notifications' => $all,
            'unreadCount'   => $unread,
            'error'         => $error,
            'success'       => $success,
        ]);
    }

    /** GET /notifications/api – JSON endpoint for dropdown */
    public function apiLatest(): void
    {
        Auth::requireAuth();
        $uid    = Auth::uid();
        $notifs = new NotificationService();
        $latest = $notifs->listUnread($uid, 10);
        $count  = $notifs->countUnread($uid);

        header('Content-Type: application/json');
        echo json_encode([
            'count'         => $count,
            'notifications' => $latest,
        ]);
        exit;
    }

    /** POST /notifications/read – mark one notification as read */
    public function markRead(): void
    {
        Auth::requireAuth();
        if (!Auth::verifyCsrf($_POST['_csrf'] ?? null)) {
            if (!empty($_SERVER['HTTP_HX_REQUEST'])) {
                http_response_code(403);
                header('Content-Type: application/json');
                echo json_encode(['ok' => false, 'message' => 'Invalid request.']);
                exit;
            }

            Auth::flash('error', 'Invalid request.');
            header('Location: /notifications');
            exit;
        }

        $uid = Auth::uid();
        $id = (int)($_POST['notification_id'] ?? 0);
        if ($id) {
            $notifs = new NotificationService();
            $notifs->markRead($uid, $id);
        }

        // Return JSON for AJAX or redirect
        if (!empty($_SERVER['HTTP_HX_REQUEST'])) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => true]);
            exit;
        }
        header('Location: /notifications');
        exit;
    }

    /** POST /notifications/read-all – mark all as read */
    public function markAllRead(): void
    {
        Auth::requireAuth();
        if (!Auth::verifyCsrf($_POST['_csrf'] ?? null)) {
            if (!empty($_SERVER['HTTP_HX_REQUEST'])) {
                http_response_code(403);
                header('Content-Type: application/json');
                echo json_encode(['ok' => false, 'message' => 'Invalid request.']);
                exit;
            }

            Auth::flash('error', 'Invalid request.');
            header('Location: /notifications');
            exit;
        }

        $uid    = Auth::uid();
        $notifs = new NotificationService();
        $notifs->markAllRead($uid);

        if (!empty($_SERVER['HTTP_HX_REQUEST'])) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => true]);
            exit;
        }
        Auth::flash('success', 'All notifications marked as read.');
        header('Location: /notifications');
        exit;
    }

    /** POST /notifications/delete – delete a notification */
    public function deleteNotification(): void
    {
        Auth::requireAuth();

        if (!Auth::verifyCsrf($_POST['_csrf'] ?? null)) {
            Auth::flash('error', 'Invalid request.');
            header('Location: /notifications');
            exit;
        }

        $id = (int)($_POST['notification_id'] ?? 0);
        if ($id) {
            $notifs = new NotificationService();
            $notifs->delete(Auth::uid(), $id);
            Auth::flash('success', 'Notification deleted.');
        }
        header('Location: /notifications');
        exit;
    }
}
