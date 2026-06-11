<?php
namespace App\Services;

use App\Core\SupabaseClient;

/**
 * Service for the `invites` table.
 */
class InviteService
{
    private SupabaseClient $db;

    public function __construct()
    {
        $this->db = SupabaseClient::getInstance();
    }

    public function getById(int $id): ?array
    {
        $row = $this->db->from('invites')->select('*')->eq('id', (string)$id)->single()->execute();
        return ($row && empty($row['error'])) ? $row : null;
    }

    /** Invites sent to a user */
    public function listForUser(string $userId, int $limit = 20): array
    {
        $rows = $this->db->from('invites')->select('*')
            ->eq('sent_to', $userId)
            ->order('created_at', false)->limit($limit)->execute();
        return ($rows && empty($rows['error'])) ? $rows : [];
    }

    /** Invites sent by a user */
    public function listByUser(string $userId, int $limit = 20): array
    {
        $rows = $this->db->from('invites')->select('*')
            ->eq('sent_by', $userId)
            ->order('created_at', false)->limit($limit)->execute();
        return ($rows && empty($rows['error'])) ? $rows : [];
    }

    /** Pending invites for a user */
    public function listPending(string $userId, int $limit = 20): array
    {
        $rows = $this->db->from('invites')->select('*')
            ->eq('sent_to', $userId)
            ->eq('status', 'pending')
            ->order('created_at', false)->limit($limit)->execute();
        return ($rows && empty($rows['error'])) ? $rows : [];
    }

    /** Send an invite */
    public function create(array $data): ?array
    {
        $result = $this->db->from('invites')->insert($data);
        return ($result && empty($result['error'])) ? ($result[0] ?? $result) : null;
    }

    /** Accept invite */
    public function accept(int $id): ?array
    {
        $result = $this->db->from('invites')->eq('id', (string)$id)->update(['status' => 'accepted']);
        return ($result && empty($result['error'])) ? ($result[0] ?? $result) : null;
    }

    /** Accept a pending invite only for the intended recipient */
    public function acceptPendingForUser(int $id, string $userId): ?array
    {
        $result = $this->db->from('invites')
            ->eq('id', (string)$id)
            ->eq('sent_to', $userId)
            ->eq('status', 'pending')
            ->update(['status' => 'accepted']);
        return ($result && empty($result['error'])) ? ($result[0] ?? $result) : null;
    }

    /** Decline invite */
    public function decline(int $id): ?array
    {
        $result = $this->db->from('invites')->eq('id', (string)$id)->update(['status' => 'declined']);
        return ($result && empty($result['error'])) ? ($result[0] ?? $result) : null;
    }

    /** Decline a pending invite only for the intended recipient */
    public function declinePendingForUser(int $id, string $userId): ?array
    {
        $result = $this->db->from('invites')
            ->eq('id', (string)$id)
            ->eq('sent_to', $userId)
            ->eq('status', 'pending')
            ->update(['status' => 'declined']);
        return ($result && empty($result['error'])) ? ($result[0] ?? $result) : null;
    }

    /** Delete an invite */
    public function delete(int $id): bool
    {
        $result = $this->db->from('invites')->eq('id', (string)$id)->delete();
        return $result !== null && empty($result['error']);
    }
}
