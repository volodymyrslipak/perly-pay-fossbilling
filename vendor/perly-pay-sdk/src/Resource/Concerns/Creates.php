<?php

declare(strict_types=1);

namespace PerlyPay\FOSSBilling\Vendor\Perly\Pay\Resource\Concerns;

if (!defined('PATH_LIBRARY')) {
    die('This file cannot be accessed directly');
}

/** @mixin \PerlyPay\FOSSBilling\Vendor\Perly\Pay\Resource\AbstractResource */
trait Creates
{
    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $options `idempotency_key`, `max_retries`, `timeout`
     *
     * @return array<string, mixed>
     */
    public function create(array $params, array $options = []): array
    {
        return $this->requestor->request('POST', $this->path, $params, [], $options);
    }
}
