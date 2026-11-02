<?php

declare(strict_types=1);

namespace PerlyPay\FOSSBilling\Vendor\Perly\Pay\Resource;

if (!defined('PATH_LIBRARY')) {
    die('This file cannot be accessed directly');
}

use PerlyPay\FOSSBilling\Vendor\Perly\Pay\Resource\Concerns\Lists;
use PerlyPay\FOSSBilling\Vendor\Perly\Pay\Resource\Concerns\Retrieves;

/** `/entitlements`: what a customer may use now. */
final class Entitlements extends AbstractResource
{
    use Lists, Retrieves;

    /**
     * May this customer use this product now? `['has_access' => bool, …]`.
     *
     * @param array<string, mixed> $params `customer` or `customer_email`, and `product`
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public function check(array $params, array $options = []): array
    {
        return $this->requestor->request('GET', $this->path . '/check', null, $params, $options);
    }

    /**
     * Spends credits from one credit pack; `action` labels it for your reports.
     *
     * @param array{credits: int, action?: string} $params
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public function consume(string $id, array $params, array $options = []): array
    {
        return $this->action($id, 'consume', $params, $options);
    }
}
