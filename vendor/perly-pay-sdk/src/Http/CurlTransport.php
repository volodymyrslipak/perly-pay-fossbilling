<?php

declare(strict_types=1);

namespace PerlyPay\FOSSBilling\Vendor\Perly\Pay\Http;

if (!defined('PATH_LIBRARY')) {
    die('This file cannot be accessed directly');
}

/**
 * The default transport: ext-curl, TLS verified, redirects never
 * followed (an API answer is never a redirect to somewhere else).
 */
final class CurlTransport implements Transport
{
    public function send(string $method, string $url, array $headers, ?string $body, float $timeoutSeconds): Response
    {
        $handle = curl_init();
        if ($handle === false) {
            throw new TransportException('Could not start a curl handle.');
        }

        $lines = ['Expect:'];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        $timeoutMs = max(1, (int) round($timeoutSeconds * 1000));

        /** @var array<string, string> $responseHeaders */
        $responseHeaders = [];
        curl_setopt_array($handle, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_TIMEOUT_MS => $timeoutMs,
            CURLOPT_CONNECTTIMEOUT_MS => min($timeoutMs, 10_000),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        $result = curl_exec($handle);
        if (!is_string($result)) {
            $errno = curl_errno($handle);
            $message = curl_error($handle);
            throw new TransportException($message !== '' ? $message : 'The request failed.', $errno === CURLE_OPERATION_TIMEDOUT);
        }
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        return new Response($status, $responseHeaders, $result);
    }
}
