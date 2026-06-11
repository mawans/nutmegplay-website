<?php
namespace App\Services;

class AiProgressService
{
    public function pathForMatch(int $matchId): string
    {
        return BASE_PATH . '/storage/ai-progress/match_' . $matchId . '.json';
    }

    public function read(int $matchId): ?array
    {
        $path = $this->pathForMatch($matchId);
        if (!is_file($path)) {
            return null;
        }

        $raw = file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    public function write(int $matchId, array $payload): void
    {
        $path = $this->pathForMatch($matchId);
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $payload['match_id'] = $matchId;
        $payload['updated_at'] = gmdate('c');

        $tempPath = $path . '.tmp';
        file_put_contents($tempPath, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        rename($tempPath, $path);

        try {
            (new MatchVideoAnalysisService())->updateByMatchId($matchId, [
                'progress_stage' => (string)($payload['stage'] ?? ''),
                'progress_percent' => (int)($payload['percent'] ?? 0),
                'progress_message' => (string)($payload['message'] ?? ''),
                'updated_at' => gmdate('c'),
            ]);
        } catch (\Throwable $e) {
            error_log('[ai-progress-db] ' . $e->getMessage());
        }
    }

    public function queued(int $matchId): void
    {
        $this->write($matchId, [
            'status' => 'queued',
            'stage' => 'queued',
            'percent' => 5,
            'message' => 'Video uploaded. Waiting for the AI worker to pick it up...',
        ]);
    }

    public function processing(int $matchId, string $message = 'AI worker started. Preparing the job...'): void
    {
        $this->processingStage($matchId, 'startup', 8, $message);
    }

    public function processingStage(
        int $matchId,
        string $stage,
        int $percent,
        string $message,
        array $extra = []
    ): void {
        $this->write($matchId, array_merge($extra, [
            'status' => 'processing',
            'stage' => $stage,
            'percent' => max(0, min(99, $percent)),
            'message' => $message,
        ]));
    }

    public function processed(int $matchId, ?string $message = null): void
    {
        $current = $this->read($matchId) ?? [];
        $this->write($matchId, array_merge($current, [
            'status' => 'processed',
            'stage' => 'complete',
            'percent' => 100,
            'message' => $message ?: 'AI processing complete.',
        ]));
    }

    public function failed(int $matchId, string $message): void
    {
        $current = $this->read($matchId) ?? [];
        $this->write($matchId, array_merge($current, [
            'status' => 'failed',
            'stage' => 'failed',
            'percent' => 100,
            'message' => $message,
        ]));
    }
}
