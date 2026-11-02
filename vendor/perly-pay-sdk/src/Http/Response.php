<?php

declare(strict_types=1);

namespace PerlyPay\FOSSBilling\Vendor\Perly\Pay\Http;

if (!defined('PATH_LIBRARY')) {
    die('This file cannot be accessed directly');
}

/** One HTTP answer: its status, its headers (names in lower case) and its raw body. */
final class Response
{
    /**
     * @param array<string, string> $headers lower-case names
     */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
    ) {
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function isSuccessful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }
}
