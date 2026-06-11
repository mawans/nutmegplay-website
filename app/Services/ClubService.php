<?php
namespace App\Services;

use App\Core\Cache;
use App\Core\SupabaseClient;

/**
 * Service for the `clubs` table.
 */
class ClubService
{
    private SupabaseClient $db;

    public function __construct()
    {
        $this->db = SupabaseClient::getInstance();
    }

    public function getById(int $id): ?array
    {
        return Cache::remember("clubs:get:$id", 45, function () use ($id): ?array {
            $row = $this->db->from('clubs')->select('*')->eq('id', (string)$id)->single()->execute();
            return ($row && empty($row['error'])) ? $row : null;
        });
    }

    public function getByOwner(string $ownerUid): ?array
    {
        $row = $this->db->from('clubs')->select('*')->eq('owner', $ownerUid)->single()->execute();
        return ($row && empty($row['error'])) ? $row : null;
    }

    public function list(int $limit = 50): array
    {
        $rows = $this->db->from('clubs')->select('*')->order('ranking')->limit($limit)->execute();
        return ($rows && empty($rows['error'])) ? $rows : [];
    }

    public function search(string $term, int $limit = 20): array
    {
        $rows = $this->db->from('clubs')->select('*')
            ->ilike('name', "%{$term}%")
            ->limit($limit)->execute();
        return ($rows && empty($rows['error'])) ? $rows : [];
    }

    public function create(array $data): ?array
    {
        $result = $this->db->from('clubs')->insert($data);
        return ($result && empty($result['error'])) ? ($result[0] ?? $result) : null;
    }

    public function update(int $id, array $data): ?array
    {
        $result = $this->db->from('clubs')->eq('id', (string)$id)->update($data);
        return ($result && empty($result['error'])) ? ($result[0] ?? $result) : null;
    }

    public function delete(int $id): bool
    {
        $result = $this->db->from('clubs')->eq('id', (string)$id)->delete();
        return $result !== null && empty($result['error']);
    }

    /** Count all clubs */
    public function count(): int
    {
        return Cache::remember('clubs:count', 60, fn(): int => $this->db->from('clubs')->countRows());
    }

    /** Get active teams */
    public function listActive(int $limit = 50): array
    {
        $rows = $this->db->from('clubs')->select('*')
            ->eq('team_active', 'true')
            ->order('ranking')->limit($limit)->execute();
        return ($rows && empty($rows['error'])) ? $rows : [];
    }
}
