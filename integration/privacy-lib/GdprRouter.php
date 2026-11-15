<?php
declare(strict_types=1);

/** Routes policy decisions; the calling application executes action plans. */
final class GdprRouter
{
    private const USER_POLICIES = [
        'consent' => 'consent.may_process_or_store',
        'lawful' => 'lawful.lawful_processing',
        'information' => 'information.information',
        'access' => 'access.access_response',
        'rectification' => 'rectification.rectification_response',
    ];
    private const REQUEST_POLICIES = [
        'erasure' => 'erasure', 'restriction' => 'restriction',
        'portability' => 'portability', 'objection' => 'objection',
        'breach' => 'breach', 'complaint' => 'complaint',
    ];
    private string $url;
    private int $timeoutMs;

    public function __construct(?string $url = null, int $timeoutMs = 10000)
    {
        if ($timeoutMs <= 0) throw new InvalidArgumentException('Invalid timeout');
        $this->url = $url ?? (getenv('OPA_QUERY_URL') ?: 'http://localhost:8181/v1/query');
        $this->timeoutMs = $timeoutMs;
    }

    private function query(string $expression, object $input): mixed
    {
        if (!function_exists('curl_init')) throw new RuntimeException('PHP cURL extension is required');
        $handle = curl_init($this->url);
        if ($handle === false) throw new RuntimeException('Could not initialize cURL');
        try {
            curl_setopt_array($handle, [
                CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_POSTFIELDS => json_encode(['query' => 'x := ' . $expression, 'input' => $input], JSON_THROW_ON_ERROR),
                CURLOPT_TIMEOUT_MS => $this->timeoutMs,
            ]);
            $raw = curl_exec($handle);
            if ($raw === false) throw new RuntimeException('OPA connection failed: ' . curl_error($handle));
            $status = curl_getinfo($handle, CURLINFO_HTTP_CODE);
            if ($status < 200 || $status >= 300) throw new RuntimeException("OPA query failed (HTTP $status)");
            $body = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
            if (!$body instanceof stdClass || (isset($body->result) && !is_array($body->result))) {
                throw new RuntimeException('Invalid OPA response');
            }
            return $body->result[0]->x ?? null;
        } finally {
            curl_close($handle);
        }
    }

    public function route(string $policy, object $input, ?string $userId = null): mixed
    {
        if (array_key_exists($policy, self::USER_POLICIES)) {
            if ($userId === null || $userId === '') throw new InvalidArgumentException('userId must be a nonempty string');
            $expression = 'data.' . self::USER_POLICIES[$policy] . '(' . json_encode($userId, JSON_THROW_ON_ERROR) . ')';
            return $this->query($expression, $input) ?? (in_array($policy, ['consent', 'lawful'], true) ? false : new stdClass());
        }
        if (!array_key_exists($policy, self::REQUEST_POLICIES)) throw new InvalidArgumentException("Unknown policy: $policy");
        $prefix = 'data.' . self::REQUEST_POLICIES[$policy];
        return (object) [
            'decision' => $this->query($prefix . '.decision', $input) ?? ($policy === 'breach' ? 'no_notify' : 'reject'),
            'justification' => $this->query($prefix . '.justification', $input) ?? [],
            'actions' => $this->query($prefix . '.actions', $input) ?? new stdClass(),
        ];
    }
}
