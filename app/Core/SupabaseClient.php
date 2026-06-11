<?php
namespace App\Core;

/**
 * Lightweight HTTP client for the Supabase PostgREST API.
 *
 * Usage:
 *   $sb = SupabaseClient::getInstance();
 *   $rows = $sb->from('accounts')->select('*')->execute();
 *   $sb->from('accounts')->insert(['fname' => 'Ali']);
 */
class SupabaseClient
{
    private static ?self $instance = null;

    private string $url;
    private string $apiKey;
    private ?string $serviceRoleKey = null;
    private string $table = '';
    private string $query = '';
    private array $headers = [];
    private array $baseHeaders = [];
    private bool $expectSingle = false;

    // Per-request circuit breaker. The shared host's DNS to *.supabase.co is
    // intermittently flaky; without this, a single page that fans out 10 Supabase
    // calls would wait `connectTimeout` on every single one (50+ seconds total)
    // before rendering. Once one call fails with a DNS / connect error, every
    // subsequent call in this PHP request short-circuits to a soft-fail.
    private static bool $breakerTripped = false;
    private static string $breakerReason = '';

    private function __construct()
    {
        [$url, $key, $serviceRoleKey] = $this->resolveCredentials();

        if (!$url || !$key) {
            throw new \RuntimeException(
                'Supabase configuration missing. Set url/key in app/Config/supabase.php.'
            );
        }

        $this->url = rtrim($url, '/');
        $this->apiKey = $key;
        $this->serviceRoleKey = $serviceRoleKey;
        $defaultKey = $this->serviceRoleKey ?: $this->apiKey;
        $this->baseHeaders = [
            'apikey: ' . $defaultKey,
            'Authorization: Bearer ' . $defaultKey,
            'Content-Type: application/json',
            'Prefer: return=representation',
        ];
        $this->headers = $this->baseHeaders;
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        // reset per-query state
        self::$instance->table = '';
        self::$instance->query = '';
        self::$instance->headers = self::$instance->baseHeaders;
        self::$instance->expectSingle = false;
        return self::$instance;
    }

    /* ------------------------------------------------------------------ */
    /*  Query builder                                                      */
    /* ------------------------------------------------------------------ */

    public function from(string $table): self
    {
        $this->table = $table;
        $this->query = '';
        $this->expectSingle = false;
        return $this;
    }

    public function select(string $columns = '*'): self
    {
        $this->query .= '&select=' . urlencode($columns);
        return $this;
    }

    /** PostgREST filter:  eq, neq, gt, gte, lt, lte, like, ilike, is, in */
    public function filter(string $column, string $operator, string $value): self
    {
        $this->query .= '&' . urlencode($column) . '=' . $operator . '.' . urlencode($value);
        return $this;
    }

    public function eq(string $column, string $value): self
    {
        return $this->filter($column, 'eq', $value);
    }

    public function neq(string $column, string $value): self
    {
        return $this->filter($column, 'neq', $value);
    }

    public function ilike(string $column, string $value): self
    {
        return $this->filter($column, 'ilike', $value);
    }

    /**
     * PostgREST OR filter: e.g. or=(col1.eq.val1,col2.eq.val2)
     * Pass raw PostgREST or-syntax string.
     */
    public function orFilter(string $conditions): self
    {
        $this->query .= '&or=(' . $conditions . ')';
        return $this;
    }

    public function order(string $column, bool $ascending = true): self
    {
        $dir = $ascending ? 'asc' : 'desc';
        $this->query .= '&order=' . urlencode($column) . '.' . $dir;
        return $this;
    }

    public function limit(int $count): self
    {
        $this->query .= '&limit=' . $count;
        return $this;
    }

    public function single(): self
    {
        $this->expectSingle = true;
        $this->query .= '&limit=1';
        return $this;
    }

    /** HEAD count with PostgREST Content-Range */
    public function countRows(): int
    {
        if (self::$breakerTripped) {
            $this->resetQueryState();
            return 0;
        }

        $url = $this->buildUrl();
        if (!str_contains($url, 'select=')) {
            $url .= (str_contains($url, '?') ? '&' : '?') . 'select=id';
        }

        $headers = $this->headers;
        $hasPrefer = false;
        foreach ($headers as $header) {
            if (str_starts_with(strtolower($header), 'prefer:')) {
                $hasPrefer = true;
                break;
            }
        }
        if (!$hasPrefer) {
            $headers[] = 'Prefer: count=exact';
        } else {
            $headers = array_map(static function (string $header): string {
                if (str_starts_with(strtolower($header), 'prefer:')) {
                    $value = trim(substr($header, 7));
                    return str_contains(strtolower($value), 'count=')
                        ? $header
                        : 'Prefer: ' . ($value === '' ? 'count=exact' : ($value . ',count=exact'));
                }
                return $header;
            }, $headers);
        }

        $responseHeaders = [];
        $ch = $this->prepareCurlHandle();
        try {
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_CUSTOMREQUEST => 'HEAD',
                CURLOPT_NOBODY => true,
                CURLOPT_SSL_VERIFYPEER => false,  // For development only
                CURLOPT_SSL_VERIFYHOST => false,  // For development only
                CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$responseHeaders): int {
                    $parts = explode(':', $line, 2);
                    if (count($parts) === 2) {
                        $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                    }
                    return strlen($line);
                },
            ]);

            curl_exec($ch);
            $error = curl_error($ch);
        } finally {
            $this->resetQueryState();
            $this->closeCurlHandle($ch);
        }

        if ($error !== '') {
            error_log("Supabase cURL error: $error");
            self::maybeTripBreaker($error);
            return 0;
        }

        $contentRange = (string)($responseHeaders['content-range'] ?? '');
        if (preg_match('#/(\d+)$#', $contentRange, $matches) === 1) {
            return (int)$matches[1];
        }

        return 0;
    }

    /* ------------------------------------------------------------------ */
    /*  CRUD operations                                                    */
    /* ------------------------------------------------------------------ */

    /** GET – fetch rows */
    public function execute(): array|null
    {
        $url = $this->buildUrl();
        return $this->request('GET', $url);
    }

    /** POST – insert row(s) */
    public function insert(array $data): array|null
    {
        $url = $this->buildUrl();
        return $this->request('POST', $url, $data);
    }

    /** PATCH – update rows matching current filters */
    public function update(array $data): array|null
    {
        $url = $this->buildUrl();
        return $this->request('PATCH', $url, $data);
    }

    /** DELETE – delete rows matching current filters */
    public function delete(): array|null
    {
        $url = $this->buildUrl();
        return $this->request('DELETE', $url);
    }

    /* ------------------------------------------------------------------ */
    /*  Supabase Auth (GoTrue)                                             */
    /* ------------------------------------------------------------------ */

    /** Sign up with email + password via GoTrue */
    public function authSignUp(string $email, string $password, array $metadata = []): array|null
    {
        $url = $this->url . '/auth/v1/signup';
        $body = ['email' => $email, 'password' => $password];
        if ($metadata) {
            $body['data'] = $metadata;
        }
        return $this->request('POST', $url, $body, $this->publicHeaders());
    }

    /** Create auth user via Admin API (service role), without sending confirmation email. */
    public function authAdminCreateUser(string $email, string $password, array $metadata = [], bool $emailConfirm = true): array|null
    {
        if (!$this->serviceRoleKey) {
            return ['error' => true, 'status' => 500, 'message' => 'Service role key is not configured.'];
        }

        $url = $this->url . '/auth/v1/admin/users';
        $body = [
            'email' => $email,
            'password' => $password,
            'email_confirm' => $emailConfirm,
        ];
        if ($metadata) {
            $body['user_metadata'] = $metadata;
        }

        $headers = [
            'apikey: ' . $this->serviceRoleKey,
            'Authorization: Bearer ' . $this->serviceRoleKey,
            'Content-Type: application/json',
        ];

        return $this->request('POST', $url, $body, $headers);
    }

    public function hasServiceRoleKey(): bool
    {
        return !empty($this->serviceRoleKey);
    }

    /** Sign in with email + password via GoTrue */
    public function authSignIn(string $email, string $password): array|null
    {
        $url = $this->url . '/auth/v1/token?grant_type=password';
        return $this->request('POST', $url, [
            'email'    => $email,
            'password' => $password,
        ], $this->publicHeaders());
    }

    /** Send password recovery email via GoTrue */
    public function authSendPasswordRecoveryEmail(string $email, ?string $redirectTo = null): array|null
    {
        $url = $this->url . '/auth/v1/recover';
        $body = ['email' => $email];
        if (is_string($redirectTo) && trim($redirectTo) !== '') {
            $body['redirect_to'] = trim($redirectTo);
        }
        return $this->request('POST', $url, $body, $this->publicHeaders());
    }

    /** Update the current authenticated user's password */
    public function authUpdateUserPassword(string $accessToken, string $password): array|null
    {
        $url = $this->url . '/auth/v1/user';
        $headers = [
            'apikey: ' . $this->apiKey,
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json',
            'Prefer: return=representation',
        ];
        return $this->request('PUT', $url, ['password' => $password], $headers);
    }

    /** Get user profile from access token */
    public function authGetUser(string $accessToken): array|null
    {
        $url = $this->url . '/auth/v1/user';
        $headers = [
            'apikey: ' . $this->apiKey,
            'Authorization: Bearer ' . $accessToken,
        ];
        return $this->request('GET', $url, null, $headers);
    }

    /** Make an authenticated API call with user's JWT */
    public function withAuth(string $accessToken): self
    {
        // Replace privileged headers with the public API key + user's JWT.
        foreach ($this->headers as $i => $h) {
            if (str_starts_with($h, 'apikey:')) {
                $this->headers[$i] = 'apikey: ' . $this->apiKey;
                continue;
            }
            if (str_starts_with($h, 'Authorization:')) {
                $this->headers[$i] = 'Authorization: Bearer ' . $accessToken;
            }
        }
        return $this;
    }

    private function publicHeaders(): array
    {
        return [
            'apikey: ' . $this->apiKey,
            'Authorization: Bearer ' . $this->apiKey,
            'Content-Type: application/json',
            'Prefer: return=representation',
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Internals                                                          */
    /* ------------------------------------------------------------------ */

    private function buildUrl(): string
    {
        $url = $this->url . '/rest/v1/' . $this->table;
        if ($this->query) {
            $url .= '?' . ltrim($this->query, '&');
        }
        return $url;
    }

    /**
     * @param array|null $overrideHeaders  Use custom headers (e.g. for auth endpoints)
     */
    private function request(string $method, string $url, ?array $body = null, ?array $overrideHeaders = null): array|null
    {
        if (self::$breakerTripped) {
            $this->resetQueryState();
            return null;
        }

        $ch = $this->prepareCurlHandle();
        $expectSingle = $this->expectSingle;
        try {
            curl_setopt_array($ch, [
                CURLOPT_URL            => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => $overrideHeaders ?? $this->headers,
                CURLOPT_CUSTOMREQUEST  => $method,
                CURLOPT_SSL_VERIFYPEER => false,  // For development only
                CURLOPT_SSL_VERIFYHOST => false,  // For development only
            ]);

            if ($body !== null) {
                $encoded = json_encode($body);
                if ($encoded === false) {
                    return ['error' => true, 'status' => 500, 'message' => 'Failed to encode request body'];
                }
                curl_setopt($ch, CURLOPT_POSTFIELDS, $encoded);
            }

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
        } finally {
            $this->resetQueryState();
            $this->closeCurlHandle($ch);
        }

        if ($error) {
            error_log("Supabase cURL error: $error");
            self::maybeTripBreaker($error);
            return null;
        }

        $decoded = json_decode($response, true);

        if ($httpCode >= 400) {
            $msg = $decoded['message'] ?? $decoded['error_description'] ?? $decoded['msg'] ?? $response;
            error_log("Supabase HTTP $httpCode: $msg");
            return ['error' => true, 'status' => $httpCode, 'message' => $msg];
        }

        if ($expectSingle) {
            if (is_array($decoded) && array_is_list($decoded)) {
                return $decoded[0] ?? null;
            }
            return is_array($decoded) ? $decoded : null;
        }

        return $decoded;
    }

    /**
     * Resolve credentials from app/Config/supabase.php only.
     *
     * @return array{0:?string,1:?string,2:?string}
     */
    private function resolveCredentials(): array
    {
        $config = $this->loadSupabaseConfig();

        return [
            $this->valueOrNull($config['url'] ?? null),
            $this->valueOrNull($config['key'] ?? null),
            $this->valueOrNull($config['service_role_key'] ?? null),
        ];
    }

    /** @return array<string,mixed> */
    private function loadSupabaseConfig(): array
    {
        $configPath = defined('BASE_PATH')
            ? BASE_PATH . '/app/Config/supabase.php'
            : dirname(__DIR__) . '/Config/supabase.php';

        if (!is_file($configPath) || !is_readable($configPath)) {
            return [];
        }

        $config = require $configPath;
        return is_array($config) ? $config : [];
    }

    private function valueOrNull(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        return $value === '' ? null : $value;
    }

    /**
     * Persistent cURL handle so successive Supabase calls reuse the same TCP +
     * TLS session. Without this every query pays a fresh handshake (~150–
     * 300 ms on shared hosting); with it the second+ query in a request is
     * effectively zero-cost on the network side.
     */
    private static ?\CurlHandle $sharedHandle = null;

    private function prepareCurlHandle(): \CurlHandle
    {
        if (self::$sharedHandle === null) {
            self::$sharedHandle = curl_init();
        } else {
            curl_reset(self::$sharedHandle);
        }
        $handle = self::$sharedHandle;

        $requestTimeoutMs = $this->requestTimeoutMs();
        curl_setopt_array($handle, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT_MS => $this->connectTimeoutMs(),
            CURLOPT_TIMEOUT_MS => $requestTimeoutMs,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_TCP_KEEPALIVE => 1,
            // Explicitly DO NOT set FORBID_REUSE / FRESH_CONNECT - those kill
            // keep-alive and were the root cause of the page being slow.
            CURLOPT_LOW_SPEED_LIMIT => 1,
            CURLOPT_LOW_SPEED_TIME => $this->secondsFromMilliseconds($requestTimeoutMs),
            CURLOPT_DNS_CACHE_TIMEOUT => 300,
        ]);

        if (defined('CURL_HTTP_VERSION_2TLS')) {
            curl_setopt($handle, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_2TLS);
        } elseif (defined('CURL_HTTP_VERSION_1_1')) {
            curl_setopt($handle, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
        }

        return $handle;
    }

    private function resetQueryState(): void
    {
        $this->headers = $this->baseHeaders;
        $this->expectSingle = false;
    }

    private function connectTimeoutMs(): int
    {
        $value = (int)(getenv('SUPABASE_CONNECT_TIMEOUT_MS') ?: 2500);
        return $value > 0 ? $value : 2500;
    }

    /**
     * Trip the per-request circuit breaker if a curl error indicates the host
     * cannot reach Supabase right now (DNS resolution, connection timeout).
     * HTTP 4xx/5xx responses do NOT trip it — those reach the API fine.
     */
    private static function maybeTripBreaker(string $curlError): void
    {
        if (self::$breakerTripped || $curlError === '') {
            return;
        }
        $needle = strtolower($curlError);
        $isReachabilityError =
            str_contains($needle, 'could not resolve host') ||
            str_contains($needle, 'resolving timed out') ||
            str_contains($needle, 'connection timed out') ||
            str_contains($needle, 'connect() timed out') ||
            str_contains($needle, 'failed to connect');
        if ($isReachabilityError) {
            self::$breakerTripped = true;
            self::$breakerReason = $curlError;
        }
    }

    /** True if Supabase has been declared unreachable for the rest of this request. */
    public static function isUnreachable(): bool
    {
        return self::$breakerTripped;
    }

    /** Reset the breaker. Tests / long-running CLI scripts only — normal request lifecycle handles cleanup. */
    public static function resetBreaker(): void
    {
        self::$breakerTripped = false;
        self::$breakerReason = '';
    }

    private function requestTimeoutMs(): int
    {
        $value = (int)(getenv('SUPABASE_TIMEOUT_MS') ?: 15000);
        return $value > 0 ? $value : 15000;
    }

    private function secondsFromMilliseconds(int $milliseconds): int
    {
        return max(1, (int)ceil($milliseconds / 1000));
    }

    private function closeCurlHandle(\CurlHandle $handle): void
    {
        // Intentional no-op: the shared handle lives for the lifetime of the
        // PHP process so successive queries can reuse the TLS connection. PHP
        // releases it automatically at shutdown.
    }
}
