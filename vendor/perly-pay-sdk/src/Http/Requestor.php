<?php

declare(strict_types=1);

namespace PerlyPay\FOSSBilling\Vendor\Perly\Pay\Http;

if (!defined('PATH_LIBRARY')) {
    die('This file cannot be accessed directly');
}

use PerlyPay\FOSSBilling\Vendor\Perly\Pay\Exception\ConnectionException;
use PerlyPay\FOSSBilling\Vendor\Perly\Pay\Exception\PerlyPayException;
use PerlyPay\FOSSBilling\Vendor\Perly\Pay\PerlyPay;

/**
 * One request to the API, with what a server SDK should do for you: the
 * key, JSON in and out, an Idempotency-Key on every POST (the same one
 * across its retries), retries with backoff on 429 / 5xx / no answer
 * (honouring Retry-After), and a timeout.
 *
 * Request options, the last argument of every call:
 *   idempotency_key  your own key for a POST (default: a fresh UUID)
 *   max_retries      this call's retries (default: the client's)
 *   timeout          this call's timeout in seconds (default: the client's)
 *
 * @phpstan-type RequestOptions array{idempotency_key?: string, max_retries?: int, timeout?: float|int}
 */
final class Requestor
{
    /** @var \Closure(int): void */
    private readonly \Closure $sleep;

    /**
     * @param string $secretKey empty for the public endpoints (license checks from an app)
     * @param string $baseUrl   the API's root, e.g. https://pay.perly.io/api/v1
     * @param (callable(int): void)|null $sleep how to wait between retries, in milliseconds (tests)
     */
    public function __construct(
        private readonly string $secretKey,
        private readonly string $baseUrl,
        private readonly Transport $transport,
        private readonly int $maxRetries = 2,
        private readonly float $timeout = 30.0,
        ?callable $sleep = null,
    ) {
        $this->sleep = $sleep !== null
            ? \Closure::fromCallable($sleep)
            : static function (int $ms): void {
                usleep($ms * 1000);
            };
    }

    /**
     * A JSON request; the decoded answer as an array.
     *
     * @param array<string, mixed>|null $body    POST body (sent as a JSON object)
     * @param array<string, mixed>      $query   query string (null values are left out)
     * @param array<string, mixed>      $options request options (see the class)
     *
     * @return array<string, mixed>
     */
    public function request(string $method, string $path, ?array $body = null, array $query = [], array $options = []): array
    {
        $response = $this->send($method, $path, $body, $query, $options, 'application/json');
        if ($response->body === '') {
            return [];
        }
        $decoded = json_decode($response->body, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * The raw bytes of a GET (a receipt's PDF).
     *
     * @param array<string, mixed> $options
     */
    public function raw(string $path, array $options = []): string
    {
        return $this->send('GET', $path, null, [], $options, '*/*')->body;
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, mixed>      $query
     * @param array<string, mixed>      $options
     */
    private function send(string $method, string $path, ?array $body, array $query, array $options, string $accept): Response
    {
        $url = $this->baseUrl . $path . self::queryString($query);
        $headers = ['Accept' => $accept, 'User-Agent' => 'perly-pay-php/' . PerlyPay::VERSION . ' PHP/' . PHP_VERSION];
        if ($this->secretKey !== '') {
            $headers['Authorization'] = 'Bearer ' . $this->secretKey;
            $headers['Perly-Client'] = 'php/' . PerlyPay::VERSION;
        }
        $payload = null;
        if ($method === 'POST') {
            $headers['Content-Type'] = 'application/json';
            $headers['Idempotency-Key'] = isset($options['idempotency_key']) && is_string($options['idempotency_key']) && $options['idempotency_key'] !== ''
                ? $options['idempotency_key']
                : self::uuid();
            $payload = self::encode($body ?? []);
        }
        $retries = isset($options['max_retries']) && is_int($options['max_retries']) ? max(0, $options['max_retries']) : $this->maxRetries;
        $timeout = isset($options['timeout']) && (is_int($options['timeout']) || is_float($options['timeout'])) ? (float) $options['timeout'] : $this->timeout;

        for ($attempt = 0; ; $attempt++) {
            try {
                $response = $this->transport->send($method, $url, $headers, $payload, $timeout);
            } catch (TransportException $error) {
                if ($attempt < $retries) {
                    ($this->sleep)(self::backoffMs($attempt, null));
                    continue;
                }
                throw new ConnectionException(
                    'Could not reach Perly Pay: ' . $error->getMessage(),
                    'connection_error',
                    $error->timedOut ? 'timeout' : 'connection_failed',
                    previous: $error,
                );
            }

            if ($response->isSuccessful()) {
                return $response;
            }

            $json = json_decode($response->body, true);
            $error = is_array($json) && isset($json['error']) && is_array($json['error']) ? $json['error'] : [];
            $code = isset($error['code']) && is_string($error['code']) ? $error['code'] : 'http_' . $response->status;
            // A conflict is retried only while the first request with this key is still running.
            $retriable = $response->status === 429
                || $response->status >= 500
                || ($response->status === 409 && $code === 'idempotency_in_progress');
            if ($retriable && $attempt < $retries) {
                ($this->sleep)(self::backoffMs($attempt, $response->header('retry-after')));
                continue;
            }

            throw PerlyPayException::fromApi(
                isset($error['type']) && is_string($error['type']) ? $error['type'] : 'api_error',
                $code,
                isset($error['message']) && is_string($error['message']) ? $error['message'] : 'Perly Pay answered ' . $response->status . '.',
                isset($error['param']) && is_string($error['param']) ? $error['param'] : null,
                $response->status,
                $response->header('perly-request-id'),
                $response->headers,
            );
        }
    }

    /**
     * Exponential backoff with full jitter: ~0.5 s, 1 s, 2 s … capped at 8 s;
     * Retry-After (seconds) wins when given, up to a minute.
     *
     * @param (callable(): float)|null $random a number in [0, 1) (tests)
     */
    public static function backoffMs(int $attempt, ?string $retryAfter, ?callable $random = null): int
    {
        if ($retryAfter !== null && is_numeric($retryAfter) && (float) $retryAfter >= 0) {
            return (int) min((float) $retryAfter * 1000, 60_000);
        }
        $cap = min(8000, 500 * (2 ** $attempt));
        $r = $random !== null ? (float) $random() : mt_rand() / (mt_getrandmax() + 1);

        return (int) round($cap / 2 + $r * ($cap / 2));
    }

    /** A random v4 UUID, the default Idempotency-Key. */
    public static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    /**
     * `?a=1&b=true`, null values left out, booleans as `true`/`false`.
     *
     * @param array<string, mixed> $query
     */
    public static function queryString(array $query): string
    {
        $pairs = [];
        foreach ($query as $name => $value) {
            if ($value === null) {
                continue;
            }
            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            } elseif (!is_scalar($value)) {
                throw new \InvalidArgumentException(sprintf('Query parameter "%s" must be a string, a number or a boolean.', $name));
            }
            $pairs[] = rawurlencode((string) $name) . '=' . rawurlencode((string) $value);
        }

        return $pairs === [] ? '' : '?' . implode('&', $pairs);
    }

    /**
     * A body as a JSON object: an empty array is `{}`, never `[]`.
     *
     * @param array<string, mixed> $body
     */
    public static function encode(array $body): string
    {
        if ($body === []) {
            return '{}';
        }

        return json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }
}
