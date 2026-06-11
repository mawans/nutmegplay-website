<?php
namespace App\Services;

use App\Core\SupabaseClient;

/**
 * Service for the `challenge_templates` table.
 *
 * Admins/Instructors create challenge templates (daily, weekly, monthly).
 * Each template has:
 *   - title, description, type (daily/weekly/monthly)
 *   - xp_reward, target_value, metric (goals/assists/wins/matches/distance)
 *   - start_date, end_date, is_active
 *   - created_by (UUID)
 */
class ChallengeTemplateService
{
    private SupabaseClient $db;

    public function __construct()
    {
        $this->db = SupabaseClient::getInstance();
    }

    public function getById(int $id): ?array
    {
        $row = $this->db->from('challenge_templates')->select('*')
            ->eq('id', (string)$id)->single()->execute();
        return ($row && empty($row['error'])) ? $row : null;
    }

    /** List active challenges */
    public function listActive(int $limit = 50): array
    {
        $rows = $this->db->from('challenge_templates')->select('*')
            ->eq('is_active', 'true')
            ->order('created_at', false)->limit($limit)->execute();
        return ($rows && empty($rows['error'])) ? $rows : [];
    }

    /** List by type (daily/weekly/monthly) */
    public function listByType(string $type, int $limit = 20): array
    {
        $rows = $this->db->from('challenge_templates')->select('*')
            ->eq('type', strtolower($type))
            ->eq('is_active', 'true')
            ->order('created_at', false)->limit($limit)->execute();
        return ($rows && empty($rows['error'])) ? $rows : [];
    }

    /** List all (for admin) */
    public function list(int $limit = 100): array
    {
        $rows = $this->db->from('challenge_templates')->select('*')
            ->order('created_at', false)->limit($limit)->execute();
        return ($rows && empty($rows['error'])) ? $rows : [];
    }

    /** Create a new challenge template */
    public function create(array $data): ?array
    {
        $result = $this->db->from('challenge_templates')->insert($data);
        return ($result && empty($result['error'])) ? ($result[0] ?? $result) : null;
    }

    /** Update a challenge template */
    public function update(int $id, array $data): ?array
    {
        $result = $this->db->from('challenge_templates')
            ->eq('id', (string)$id)->update($data);
        return ($result && empty($result['error'])) ? ($result[0] ?? $result) : null;
    }

    /** Delete a challenge template */
    public function delete(int $id): bool
    {
        $result = $this->db->from('challenge_templates')
            ->eq('id', (string)$id)->delete();
        return $result !== null && empty($result['error']);
    }

    /** Toggle active status */
    public function toggleActive(int $id, bool $active): ?array
    {
        return $this->update($id, ['is_active' => $active]);
    }

    /** Count active challenges */
    public function countActive(): int
    {
        $rows = $this->db->from('challenge_templates')->select('id')
            ->eq('is_active', 'true')->execute();
        return is_array($rows) && empty($rows['error']) ? count($rows) : 0;
    }
}
