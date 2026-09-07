<?php
namespace App\Services;

use App\Core\SupabaseClient;

class AiCreditRefundService
{
    public function refundFailedAnalysis(int $matchId, string $reason): bool
    {
        if ($matchId <= 0) {
            return false;
        }

        $db = SupabaseClient::getInstance();
        $unlock = $db->from('stat_unlocks')
            ->select('user_id, match_id')
            ->eq('match_id', (string)$matchId)
            ->single()
            ->execute();

        if (!is_array($unlock) || !empty($unlock['error'])) {
            return false;
        }

        $userId = trim((string)($unlock['user_id'] ?? ''));
        if ($userId === '') {
            return false;
        }

        $note = 'AI refund for failed stats unlock match ' . $matchId;
        $existingRefund = SupabaseClient::getInstance()->from('credit_transactions')
            ->select('id')
            ->eq('user_id', $userId)
            ->eq('kind', 'adjustment')
            ->eq('note', $note)
            ->single()
            ->execute();

        if (is_array($existingRefund) && empty($existingRefund['error']) && !empty($existingRefund['id'])) {
            $this->deleteUnlock($userId, $matchId);
            return false;
        }

        $creditRow = SupabaseClient::getInstance()->from('user_credits')
            ->select('balance')
            ->eq('user_id', $userId)
            ->single()
            ->execute();

        $currentBalance = is_array($creditRow) && empty($creditRow['error'])
            ? (int)($creditRow['balance'] ?? 0)
            : 0;

        $updated = SupabaseClient::getInstance()->from('user_credits')
            ->eq('user_id', $userId)
            ->update([
                'balance' => $currentBalance + 1,
                'updated_at' => gmdate('c'),
            ]);

        if (!is_array($updated) || !empty($updated['error'])) {
            SupabaseClient::getInstance()->from('user_credits')->insert([
                'user_id' => $userId,
                'balance' => 1,
                'updated_at' => gmdate('c'),
            ]);
        }

        $cleanReason = preg_replace('/\s+/', ' ', trim($reason));
        if (strlen((string)$cleanReason) > 220) {
            $cleanReason = substr((string)$cleanReason, 0, 220) . '...';
        }

        SupabaseClient::getInstance()->from('credit_transactions')->insert([
            'user_id' => $userId,
            'amount' => 1,
            'kind' => 'adjustment',
            'note' => $note,
            'pack_key' => $cleanReason !== '' ? ('failed_ai: ' . $cleanReason) : 'failed_ai',
        ]);

        $this->deleteUnlock($userId, $matchId);

        error_log(sprintf('[ai-credit-refund] refunded match %d to user %s', $matchId, $userId));
        return true;
    }

    private function deleteUnlock(string $userId, int $matchId): void
    {
        try {
            SupabaseClient::getInstance()->from('stat_unlocks')
                ->eq('user_id', $userId)
                ->eq('match_id', (string)$matchId)
                ->delete();
        } catch (\Throwable $e) {
            error_log('[ai-credit-refund] could not delete stat unlock: ' . $e->getMessage());
        }
    }
}
