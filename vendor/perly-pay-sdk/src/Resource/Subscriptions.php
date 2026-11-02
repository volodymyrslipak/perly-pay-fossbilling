<?php

declare(strict_types=1);

namespace PerlyPay\FOSSBilling\Vendor\Perly\Pay\Resource;

if (!defined('PATH_LIBRARY')) {
    die('This file cannot be accessed directly');
}

use PerlyPay\FOSSBilling\Vendor\Perly\Pay\Resource\Concerns\Creates;
use PerlyPay\FOSSBilling\Vendor\Perly\Pay\Resource\Concerns\Lists;
use PerlyPay\FOSSBilling\Vendor\Perly\Pay\Resource\Concerns\Retrieves;
use PerlyPay\FOSSBilling\Vendor\Perly\Pay\Resource\Concerns\Updates;

/** `/subscriptions`: renew by invoice and reminder — never an automatic debit. */
final class Subscriptions extends AbstractResource
{
    use Creates, Lists, Retrieves, Updates;

    /**
     * Cancels now, or at the end of the period with `at_period_end`.
     *
     * @param array{at_period_end?: bool} $params
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public function cancel(string $id, array $params = [], array $options = []): array
    {
        return $this->action($id, 'cancel', $params, $options);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public function pause(string $id, array $options = []): array
    {
        return $this->action($id, 'pause', [], $options);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public function resume(string $id, array $options = []): array
    {
        return $this->action($id, 'resume', [], $options);
    }

    /**
     * Opens one invoice for `periods` periods ahead; returns that invoice.
     *
     * @param array{periods: int} $params
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public function prepay(string $id, array $params, array $options = []): array
    {
        return $this->action($id, 'prepay', $params, $options);
    }
}
