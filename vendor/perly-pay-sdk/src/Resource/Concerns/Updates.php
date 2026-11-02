<?php

declare(strict_types=1);

namespace PerlyPay\FOSSBilling\Vendor\Perly\Pay\Resource\Concerns;

if (!defined('PATH_LIBRARY')) {
    die('This file cannot be accessed directly');
}

/** @mixin \PerlyPay\FOSSBilling\Vendor\Perly\Pay\Resource\AbstractResource */
trait Updates
{
    /**
     * @param array<string, mixed> $params only the fields to change
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public function update(string $id, array $params, array $options = []): array
    {
        return $this->requestor->request('POST', $this->idPath($id), $params, [], $options);
    }
}
