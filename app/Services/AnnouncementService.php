<?php
namespace App\Services;

use App\Core\Cache;
use App\Core\SupabaseClient;

/**
 * Service for the `announcement` table.
 */
class AnnouncementService
{
    private SupabaseClient $db;

    public function __construct()
    {
        $this->db = SupabaseClient::getInstance();
    }

    public function getById(int $id): ?array
    {
        $row = $this->db->from('announcement')->select('*')->eq('id', (string)$id)->single()->execute();
        return ($row && empty($row['error'])) ? $row : null;
    }

    /** List announcements (newest first) */
    public function list(int $limit = 20): array
    {
        return Cache::remember("announcement:list:$limit", 45, function () use ($limit): array {
            $rows = $this->db->from('announcement')->select('*')
                ->order('created_at', false)->limit($limit)->execute();
            return ($rows && empty($rows['error'])) ? $rows : [];
        });
    }

    /** List announcements for a specific club */
    public function listByClub(string $club, int $limit = 20): array
    {
        $rows = $this->db->from('announcement')->select('*')
            ->eq('club', $club)
            ->order('created_at', false)->limit($limit)->execute();
        return ($rows && empty($rows['error'])) ? $rows : [];
    }

    /** List announcements by a specific user */
    public function listByUser(string $userId, int $limit = 20): array
    {
        $rows = $this->db->from('announcement')->select('*')
            ->eq('user_id', $userId)
            ->order('created_at', false)->limit($limit)->execute();
        return ($rows && empty($rows['error'])) ? $rows : [];
    }

    /** Create an announcement */
    public function create(array $data): ?array
    {
        $result = $this->db->from('announcement')->insert($data);
        return ($result && empty($result['error'])) ? ($result[0] ?? $result) : null;
    }

    /** Update an announcement */
    public function update(int $id, array $data): ?array
    {
        $result = $this->db->from('announcement')->eq('id', (string)$id)->update($data);
        return ($result && empty($result['error'])) ? ($result[0] ?? $result) : null;
    }

    /** Delete an announcement */
    public function delete(int $id): bool
    {
        $result = $this->db->from('announcement')->eq('id', (string)$id)->delete();
        return $result !== null && empty($result['error']);
    }
}
