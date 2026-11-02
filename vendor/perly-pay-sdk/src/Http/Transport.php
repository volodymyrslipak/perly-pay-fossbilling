<?php

declare(strict_types=1);

namespace PerlyPay\FOSSBilling\Vendor\Perly\Pay\Http;

if (!defined('PATH_LIBRARY')) {
    die('This file cannot be accessed directly');
}

/**
 * How a request leaves the machine. The SDK ships `CurlTransport`; pass
 * your own (a proxy, a test double, your framework's client) as the
 * `transport` option.
 */
interface Transport
{
    /**
     * Sends one request and returns whatever the server answered, any status.
     *
     * @param array<string, string> $headers
     *
     * @throws TransportException when no answer came back (DNS, refused, timeout)
     */
    public function send(string $method, string $url, array $headers, ?string $body, float $timeoutSeconds): Response;
}
