<?php
declare(strict_types=1);
namespace TranscriptDock;

final class Client
{
    private string $baseUrl;

    public function __construct(
        private readonly string $apiKey,
        string $baseUrl = 'https://www.transcriptdock.com',
        private readonly float $timeout = 30,
    ) {
        if (!preg_match('/\A[!-~]+\z/', $apiKey)) {
            throw new \InvalidArgumentException('Provide an API key without whitespace or control characters.');
        }
        $url = parse_url($baseUrl);
        $host = trim($url['host'] ?? '', '[]');
        $loopback = $host === 'localhost' || $host === '::1' ||
            (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && str_starts_with($host, '127.'));
        if (!$url || $host === '' || (($url['scheme'] ?? '') !== 'https' && !(($url['scheme'] ?? '') === 'http' && $loopback)) ||
            isset($url['user'], $url['pass']) || isset($url['user']) || isset($url['query']) || isset($url['fragment']) ||
            !in_array($url['path'] ?? '', ['', '/'], true) || preg_match('/[\x00-\x20\x7f]/', $baseUrl)) {
            throw new \InvalidArgumentException('Use an HTTPS origin, or loopback HTTP, without credentials, path, query or fragment.');
        }
        self::positive($timeout);
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    /** Return all API fields as associative arrays, or raw export text. No redirects or retries. */
    public function request(string $method, string $path, ?array $body = null, array $query = [], ?string $idempotencyKey = null, bool $text = false, ?float $timeout = null): array|string
    {
        $method = strtoupper($method);
        if (!in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'], true)) throw new \InvalidArgumentException('Provide a supported HTTP method.');
        if (!str_starts_with($path, '/v1/') || preg_match('/[?#\\\\]/', $path) || array_intersect(explode('/', rawurldecode($path)), ['.', '..'])) {
            throw new \InvalidArgumentException('Use a /v1/ path and pass query parameters separately.');
        }
        $needsKey = $method === 'POST' && (in_array($path, ['/v1/jobs', '/v1/batches', '/v1/video-discoveries', '/v1/language-discoveries'], true) || str_ends_with($path, '/retry'));
        if (($needsKey || $idempotencyKey !== null) && !preg_match('/\A[ -~]{8,128}\z/', $idempotencyKey ?? '')) {
            throw new \InvalidArgumentException('Idempotency keys need 8 to 128 printable ASCII characters.');
        }
        $seconds = $timeout ?? $this->timeout;
        self::positive($seconds);
        $headers = ['Authorization: Bearer ' . $this->apiKey, 'Accept: application/json', 'User-Agent: transcriptdock-php/0.1.0'];
        if ($idempotencyKey !== null) $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
        $url = $this->baseUrl . $path;
        $query = array_filter($query, fn($v) => $v !== null);
        if ($query) $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $options = [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT_MS => max(1, (int) ceil($seconds * 1000)), CURLOPT_CONNECTTIMEOUT_MS => max(1, (int) ceil($seconds * 1000)),
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2];
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = json_encode((object) $body, JSON_THROW_ON_ERROR);
            $headers[] = 'Content-Type: application/json';
        }
        $options[CURLOPT_HTTPHEADER] = $headers;
        $curl = curl_init($url);
        curl_setopt_array($curl, $options);
        $raw = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        unset($curl);
        if ($raw === false) throw new ApiError('NETWORK_ERROR', 'Could not reach the API. Check the job before retrying a submission.', retryable: true);
        if ($status < 200 || $status >= 300) {
            $envelope = json_decode($raw, true);
            $error = is_array($envelope) && is_array($envelope['error'] ?? null) ? $envelope['error'] : [];
            throw new ApiError($this->redact(is_string($error['code'] ?? null) ? $error['code'] : 'HTTP_ERROR'),
                $this->redact(is_string($error['message'] ?? null) ? $error['message'] : "Request failed with status $status."), $status,
                is_bool($error['retryable'] ?? null) ? $error['retryable'] : ($status === 429 || $status >= 500),
                is_string($envelope['request_id'] ?? null) ? $this->redact($envelope['request_id']) : null,
                is_int($error['retry_after_seconds'] ?? null) ? $error['retry_after_seconds'] : null);
        }
        if ($text) return $raw;
        try {
            $decoded = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ApiError('INVALID_RESPONSE', 'The API did not return JSON.');
        }
        if (!$decoded instanceof \stdClass) throw new ApiError('INVALID_RESPONSE', 'The API did not return a JSON object.');
        return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    }

    public function capabilities(): array { return $this->request('GET', '/v1/capabilities'); }
    public function createJob(array $body, string $idempotencyKey): array { return $this->request('POST', '/v1/jobs', $body, idempotencyKey: $idempotencyKey); }
    public function getJob(string $id): array { return $this->request('GET', '/v1/jobs/' . self::id($id)); }
    public function getTranscript(string $id): array { return $this->request('GET', '/v1/transcripts/' . self::id($id)); }
    public function exportTranscript(string $id, string $format = 'txt'): string
    {
        if (!in_array($format, ['json', 'txt', 'srt', 'vtt'], true)) throw new \InvalidArgumentException('Choose json, txt, srt or vtt.');
        return $this->request('GET', '/v1/transcripts/' . self::id($id) . '/export', query: ['format' => $format], text: true);
    }
    public function waitForJob(string $id, float $timeout = 120, float $pollInterval = 3): array
    {
        self::positive($timeout);
        self::positive($pollInterval);
        $deadline = hrtime(true) / 1e9 + $timeout;
        $last = null;
        while (true) {
            $remaining = $deadline - hrtime(true) / 1e9;
            if ($remaining <= 0) throw new JobTimeoutError($last);
            $last = $this->request('GET', '/v1/jobs/' . self::id($id), timeout: min($remaining, $this->timeout));
            if (in_array($last['status'] ?? '', ['succeeded', 'failed', 'cancelled'], true)) return $last;
            $remaining = $deadline - hrtime(true) / 1e9;
            if ($remaining <= 0) throw new JobTimeoutError($last);
            usleep((int) (min($remaining, $pollInterval) * 1e6));
        }
    }
    private static function positive(float $value): void
    {
        if (!is_finite($value) || $value <= 0 || $value > 86400) throw new \InvalidArgumentException('Timeout and poll intervals must be positive and at most one day.');
    }
    private static function id(string $id): string
    {
        if ($id === '' || $id === '.' || $id === '..') throw new \InvalidArgumentException('Provide a nonempty ID.');
        return rawurlencode($id);
    }
    private function redact(string $value): string
    {
        return preg_replace('/td_live_[A-Za-z0-9_-]+|Bearer\s+\S+/i', '[REDACTED]', str_replace($this->apiKey, '[REDACTED]', $value));
    }
}
