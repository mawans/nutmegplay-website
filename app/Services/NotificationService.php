<?php
namespace App\Services;

use App\Core\Cache;
use App\Core\SupabaseClient;

/**
 * Service for the `notifications` table.
 *
 * Each notification has:
 *   - user_id  (UUID)  recipient
 *   - type     (text)  category key e.g. user_created, match_created …
 *   - title    (text)  short headline
 *   - message  (text)  longer body
 *   - icon     (text)  Material Symbols icon name
 *   - link     (text)  optional URL to navigate to
 *   - is_read  (bool)  false by default
 *   - created_at (timestamptz)
 */
class NotificationService
{
    private SupabaseClient $db;

    // ── Notification type constants ─────────────────────────────
    public const TYPE_USER_CREATED       = 'user_created';
    public const TYPE_MATCH_CREATED      = 'match_created';
    public const TYPE_MATCH_APPROVED     = 'match_approved';
    public const TYPE_MATCH_REJECTED     = 'match_rejected';
    public const TYPE_MATCH_COMPLETED    = 'match_completed';
    public const TYPE_FIXTURE_SET        = 'fixture_set';
    public const TYPE_FIXTURE_REMINDER   = 'fixture_reminder';
    public const TYPE_TEAM_CREATED       = 'team_created';
    public const TYPE_TEAM_DELETED       = 'team_deleted';
    public const TYPE_VIDEO_UPLOADED     = 'video_uploaded';
    public const TYPE_VIDEO_PROCESSED    = 'video_processed';
    public const TYPE_LEADERBOARD_UPDATE = 'leaderboard_update';
    public const TYPE_CHALLENGE_NEW      = 'challenge_new';
    public const TYPE_CHALLENGE_ACCEPTED = 'challenge_accepted';
    public const TYPE_CHALLENGE_DECLINED = 'challenge_declined';
    public const TYPE_INVITE_RECEIVED    = 'invite_received';
    public const TYPE_PROFILE_UPDATED    = 'profile_updated';
    public const TYPE_ROLE_CHANGED       = 'role_changed';
    public const TYPE_ANNOUNCEMENT       = 'announcement';

    public function __construct()
    {
        $this->db = SupabaseClient::getInstance();
    }

    /* ── Read ───────────────────────────────────────────────── */

    /** Get a single notification */
    public function getById(int $id): ?array
    {
        $row = $this->db->from('notifications')->select('*')
            ->eq('id', (string)$id)->single()->execute();
        return ($row && empty($row['error'])) ? $row : null;
    }

    /** All notifications for a user (newest first) */
    public function listForUser(string $userId, int $limit = 50): array
    {
        $rows = $this->db->from('notifications')->select('*')
            ->eq('user_id', $userId)
            ->order('created_at', false)->limit($limit)->execute();
        return ($rows && empty($rows['error'])) ? $rows : [];
    }

    /** Unread notifications for a user */
    public function listUnread(string $userId, int $limit = 20): array
    {
        return Cache::remember("notifications:unread:$userId:$limit", 10, function () use ($userId, $limit): array {
            $rows = $this->db->from('notifications')->select('*')
                ->eq('user_id', $userId)
                ->eq('is_read', 'false')
                ->order('created_at', false)->limit($limit)->execute();
            return ($rows && empty($rows['error'])) ? $rows : [];
        });
    }

    /** Count unread */
    public function countUnread(string $userId): int
    {
        return Cache::remember("notifications:unread_count:$userId", 10, function () use ($userId): int {
            return $this->db->from('notifications')
                ->eq('user_id', $userId)
                ->eq('is_read', 'false')
                ->countRows();
        });
    }

    /* ── Write ──────────────────────────────────────────────── */

    /** Create a notification */
    public function create(array $data): ?array
    {
        $result = $this->db->from('notifications')->insert($data);
        return ($result && empty($result['error'])) ? ($result[0] ?? $result) : null;
    }

    /** Convenience: send a notification to a user */
    public function send(
        string $userId,
        string $type,
        string $title,
        string $message,
        string $icon = 'notifications',
        string $link = ''
    ): ?array {
        return $this->create([
            'user_id' => $userId,
            'type'    => $type,
            'title'   => $title,
            'message' => $message,
            'icon'    => $icon,
            'link'    => $link,
            'is_read' => false,
        ]);
    }

    /** Send the same notification to multiple users */
    public function sendToMany(
        array  $userIds,
        string $type,
        string $title,
        string $message,
        string $icon = 'notifications',
        string $link = ''
    ): int {
        $count = 0;
        foreach ($userIds as $uid) {
            if ($uid && $this->send($uid, $type, $title, $message, $icon, $link)) {
                $count++;
            }
        }
        return $count;
    }

    /** Mark single notification as read */
    public function markRead(string $userId, int $id): bool
    {
        $result = $this->db->from('notifications')
            ->eq('user_id', $userId)
            ->eq('id', (string)$id)
            ->update(['is_read' => true]);
        return $result !== null && empty($result['error']);
    }

    /** Mark all of a user's notifications as read */
    public function markAllRead(string $userId): bool
    {
        $result = $this->db->from('notifications')
            ->eq('user_id', $userId)
            ->eq('is_read', 'false')
            ->update(['is_read' => true]);
        return $result !== null && empty($result['error']);
    }

    /** Delete a notification */
    public function delete(string $userId, int $id): bool
    {
        $result = $this->db->from('notifications')
            ->eq('user_id', $userId)
            ->eq('id', (string)$id)
            ->delete();
        return $result !== null && empty($result['error']);
    }

    /** Delete all read notifications older than N days for a user */
    public function cleanup(string $userId, int $daysOld = 30): bool
    {
        $cutoff = date('c', strtotime("-{$daysOld} days"));
        $result = $this->db->from('notifications')
            ->eq('user_id', $userId)
            ->eq('is_read', 'true')
            ->filter('created_at', 'lt', $cutoff)
            ->delete();
        return $result !== null && empty($result['error']);
    }
}
