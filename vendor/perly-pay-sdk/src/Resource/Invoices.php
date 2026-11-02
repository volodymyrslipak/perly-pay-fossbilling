<?php

declare(strict_types=1);

namespace PerlyPay\FOSSBilling\Vendor\Perly\Pay\Resource;

if (!defined('PATH_LIBRARY')) {
    die('This file cannot be accessed directly');
}

use PerlyPay\FOSSBilling\Vendor\Perly\Pay\Resource\Concerns\Creates;
use PerlyPay\FOSSBilling\Vendor\Perly\Pay\Resource\Concerns\Lists;
use PerlyPay\FOSSBilling\Vendor\Perly\Pay\Resource\Concerns\Retrieves;

/** `/invoices`: one bill the buyer pays at its `url`. */
final class Invoices extends AbstractResource
{
    use Creates, Lists, Retrieves;

    /**
     * Cancels an invoice while nothing has arrived for it.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public function cancel(string $id, array $options = []): array
    {
        return $this->action($id, 'cancel', [], $options);
    }

    /**
     * Test mode only: the buyer pays in full (default), half (`short`) or a tenth more (`over`).
     *
     * @param array{outcome?: string, chain?: string, asset?: string} $params
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public function simulate(string $id, array $params = [], array $options = []): array
    {
        return $this->action($id, 'simulate', $params, $options);
    }
}
