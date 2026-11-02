<?php

declare(strict_types=1);

namespace PerlyPay\FOSSBilling\Vendor\Perly\Pay\Resource;

if (!defined('PATH_LIBRARY')) {
    die('This file cannot be accessed directly');
}

use PerlyPay\FOSSBilling\Vendor\Perly\Pay\Resource\Concerns\Creates;
use PerlyPay\FOSSBilling\Vendor\Perly\Pay\Resource\Concerns\Lists;
use PerlyPay\FOSSBilling\Vendor\Perly\Pay\Resource\Concerns\Retrieves;

/** `/checkout_sessions`: a cart of prices; send the buyer to its `url`. */
final class CheckoutSessions extends AbstractResource
{
    use Creates, Lists, Retrieves;

    /**
     * Ends an open session now.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public function expire(string $id, array $options = []): array
    {
        return $this->action($id, 'expire', [], $options);
    }
}
