<?php
namespace App\Core;

use RuntimeException;

/**
 * Shared HMAC helper for signing video URLs and verifying AI worker callbacks.
 *
 * Two distinct secrets:
 *   NUTMEG_VIDEO_SIGNING_SECRET   - signs /v/<file>?exp=&sig= URLs handed to the AI
 *   NUTMEG_AI_CALLBACK_SECRET     - verifies AI -> PHP webhook callbacks
 *
 * Both fall back to NUTMEG_AI_SHARED_SECRET if set, then to a key derived from
 * the Supabase service-role key so a working dev deployment requires zero new env.
 */
class AiSecurity
{
    public static function videoSigningSecret(): string
    {
        return self::resolveSecret('NUTMEG_VIDEO_SIGNING_SECRET', 'video');
    }

    public static function callbackSecret(): string
    {
        return self::resolveSecret('NUTMEG_AI_CALLBACK_SECRET', 'callback');
    }

    public static function signVideoUrl(string $videoPath, int $ttlSeconds = 7200): string
    {
        $exp = time() + max(60, $ttlSeconds);
        $sig = self::computeVideoSignature($videoPath, $exp);
        $separator = str_contains($videoPath, '?') ? '&' : '?';
        return $videoPath . $separator . 'exp=' . $exp . '&sig=' . $sig;
    }

    public static function verifyVideoSignature(string $videoPath, int $exp, string $signature): bool
    {
        if ($exp <= 0 || time() > $exp) {
            return false;
        }
        $expected = self::computeVideoSignature($videoPath, $exp);
        return hash_equals($expected, $signature);
    }

    private static function computeVideoSignature(string $videoPath, int $exp): string
    {
        $payload = $videoPath . '|' . $exp;
        return hash_hmac('sha256', $payload, self::videoSigningSecret());
    }

    public static function signCallback(array $payload): string
    {
        $canonical = self::canonicalJson($payload);
        return hash_hmac('sha256', $canonical, self::callbackSecret());
    }

    public static function verifyCallback(string $rawBody, string $signature, int $maxAgeSeconds = 600): array
    {
        if (trim($signature) === '') {
            throw new RuntimeException('Missing callback signature.');
        }

        $decoded = json_decode($rawBody, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Callback body must be a JSON object.');
        }

        $issuedAt = (int)($decoded['issued_at'] ?? 0);
        if ($issuedAt <= 0 || abs(time() - $issuedAt) > $maxAgeSeconds) {
            throw new RuntimeException('Callback timestamp is missing or too old.');
        }

        $expected = hash_hmac('sha256', self::canonicalJson($decoded), self::callbackSecret());
        if (!hash_equals($expected, $signature)) {
            throw new RuntimeException('Invalid callback signature.');
        }

        return $decoded;
    }

    private static function canonicalJson(array $payload): string
    {
        ksort($payload);
        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function resolveSecret(string $envName, string $purpose): string
    {
        foreach ([$envName, 'NUTMEG_AI_SHARED_SECRET'] as $candidate) {
            $value = trim((string)(getenv($candidate) ?: ''));
            if ($value !== '') {
                return $value;
            }
        }

        $derivation = trim((string)(getenv('SUPABASE_SERVICE_ROLE_KEY') ?: getenv('SUPABASE_KEY') ?: ''));
        if ($derivation === '') {
            throw new RuntimeException(
                'Set NUTMEG_AI_SHARED_SECRET (or NUTMEG_VIDEO_SIGNING_SECRET / NUTMEG_AI_CALLBACK_SECRET) before using the secured AI flow.'
            );
        }

        return hash('sha256', 'nutmeg:ai:' . $purpose . ':' . $derivation);
    }
}
