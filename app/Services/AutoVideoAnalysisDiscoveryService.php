<?php
namespace App\Services;

/**
 * Finds newly available server-side match videos and queues them for AI once.
 *
 * Mobile clients do not start inference anymore. This service is the server
 * authority: when a website/B2 video exists for a match and no current analysis
 * is already queued, processing, or processed for that video, it creates the
 * match_video_analysis queue row. scripts/process-queued-videos.php performs
 * the actual inference work.
 */
class AutoVideoAnalysisDiscoveryService
{
    private MatchService $matches;
    private MatchVideoAnalysisService $analyses;
    private VideoAnalysisService $videoAnalysis;

    public function __construct()
    {
        $this->matches = new MatchService();
        $this->analyses = new MatchVideoAnalysisService();
        $this->videoAnalysis = new VideoAnalysisService();
    }

    /**
     * @return array{queued:int, skipped:int, errors:array<int, string>}
     */
    public function queueNewVideos(int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        $queued = 0;
        $skipped = 0;
        $errors = [];

        $matches = $this->matches->list(1000);
        $matchesById = [];
        foreach ($matches as $match) {
            $matchId = (int)($match['id'] ?? 0);
            if ($matchId > 0) {
                $matchesById[$matchId] = $match;
            }
        }

        $analysisByMatch = $this->analyses->listByMatchIds(array_keys($matchesById));
        foreach ($matchesById as $matchId => $match) {
            if ($queued >= $limit) {
                break;
            }

            $videoUrl = trim((string)($match['video_url'] ?? ''));
            if ($videoUrl === '') {
                $skipped++;
                continue;
            }

            $sourceType = $this->inferSourceType($videoUrl);
            if (!$this->isServerVideo($videoUrl, $sourceType)) {
                $skipped++;
                continue;
            }

            try {
                if ($this->queueCandidate($match, $videoUrl, $sourceType, $analysisByMatch[$matchId] ?? null, [])) {
                    $queued++;
                    $analysisByMatch[$matchId] = $this->analyses->getByMatchId($matchId) ?? [];
                } else {
                    $skipped++;
                }
            } catch (\Throwable $e) {
                $errors[] = sprintf('match %d: %s', $matchId, $e->getMessage());
            }
        }

        if ($queued < $limit && B2VideoStorageService::isConfigured()) {
            try {
                $storage = new B2VideoStorageService();
                foreach ($storage->listVideos(1000) as $video) {
                    if ($queued >= $limit) {
                        break;
                    }

                    $matchId = (int)($video['match_id'] ?? 0);
                    if ($matchId <= 0 || !isset($matchesById[$matchId])) {
                        $skipped++;
                        continue;
                    }

                    $videoUrl = trim((string)($video['url'] ?? ''));
                    if ($videoUrl === '') {
                        $skipped++;
                        continue;
                    }

                    $metadata = [];
                    foreach (['recording_id', 'file_name', 'folder_name', 'folder_path', 'video_part_number', 'video_group_key', 'camera_number'] as $key) {
                        $value = trim((string)($video[$key] ?? ''));
                        if ($value !== '') {
                            $metadata[$key] = $value;
                        }
                    }
                    try {
                        $optimizedUrl = $storage->aiOptimizedUrlForVideoUrl($videoUrl);
                        if (is_string($optimizedUrl) && trim($optimizedUrl) !== '') {
                            $metadata['ai_video_url'] = trim($optimizedUrl);
                        }
                    } catch (\Throwable) {
                    }

                    try {
                        if ($this->queueCandidate($matchesById[$matchId], $videoUrl, 'b2_storage', $analysisByMatch[$matchId] ?? null, $metadata)) {
                            $queued++;
                            $analysisByMatch[$matchId] = $this->analyses->getByMatchId($matchId) ?? [];
                        } else {
                            $skipped++;
                        }
                    } catch (\Throwable $e) {
                        $errors[] = sprintf('b2 match %d: %s', $matchId, $e->getMessage());
                    }
                }
            } catch (\Throwable $e) {
                $errors[] = 'b2 scan: ' . $e->getMessage();
            }
        }

        return [
            'queued' => $queued,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }

    private function queueCandidate(
        array $match,
        string $videoUrl,
        string $sourceType,
        ?array $existingAnalysis,
        array $metadata
    ): bool {
        $matchId = (int)($match['id'] ?? 0);
        if ($matchId <= 0 || trim($videoUrl) === '') {
            return false;
        }

        if ($this->analysisAlreadyCoversVideo($existingAnalysis, $videoUrl)) {
            return false;
        }

        $uploadedBy = trim((string)($match['challenger_id'] ?? ''))
            ?: trim((string)($match['opponent_id'] ?? ''))
            ?: '00000000-0000-0000-0000-000000000000';

        $this->videoAnalysis->queueAnalysis(
            $matchId,
            $videoUrl,
            $uploadedBy,
            $sourceType,
            $metadata
        );

        return true;
    }

    private function analysisAlreadyCoversVideo(?array $analysis, string $videoUrl): bool
    {
        if (!is_array($analysis)) {
            return false;
        }

        $status = strtolower(trim((string)($analysis['processing_status'] ?? '')));
        if (!in_array($status, ['queued', 'processing', 'processed'], true)) {
            return false;
        }

        $existingUrl = trim((string)($analysis['video_url'] ?? ''));
        if ($existingUrl === '') {
            return true;
        }

        return $this->videoKey($existingUrl) === $this->videoKey($videoUrl);
    }

    private function inferSourceType(string $videoUrl): string
    {
        if (B2VideoStorageService::isConfigured()) {
            try {
                if ((new B2VideoStorageService())->isManagedUrl($videoUrl)) {
                    return 'b2_storage';
                }
            } catch (\Throwable) {
            }
        }

        return str_starts_with(trim($videoUrl), '/videos/') ? 'local_upload' : 'external_url';
    }

    private function isServerVideo(string $videoUrl, string $sourceType): bool
    {
        if ($sourceType === 'b2_storage') {
            return true;
        }

        if ($sourceType !== 'local_upload') {
            return false;
        }

        $path = BASE_PATH . '/public' . parse_url($videoUrl, PHP_URL_PATH);
        return is_file($path);
    }

    private function videoKey(string $videoUrl): string
    {
        $parts = parse_url(trim($videoUrl));
        $host = strtolower((string)($parts['host'] ?? ''));
        $path = rawurldecode((string)($parts['path'] ?? trim($videoUrl)));
        return $host . '/' . ltrim($path, '/');
    }
}
