<?php

declare(strict_types=1);

namespace PerlyPay\FOSSBilling\Vendor\Perly\Pay\Resource;

if (!defined('PATH_LIBRARY')) {
    die('This file cannot be accessed directly');
}

use PerlyPay\FOSSBilling\Vendor\Perly\Pay\Resource\Concerns\Creates;
use PerlyPay\FOSSBilling\Vendor\Perly\Pay\Resource\Concerns\Lists;
use PerlyPay\FOSSBilling\Vendor\Perly\Pay\Resource\Concerns\Retrieves;

/** `/refunds`: you send from your own wallet; the buyer gives the address. */
final class Refunds extends AbstractResource
{
    use Creates, Lists, Retrieves;

    /**
     * What a refund of this invoice would send now.
     *
     * @param array{invoice: string} $params
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public function quote(array $params, array $options = []): array
    {
        return $this->requestor->request('GET', $this->path . '/quote', null, $params, $options);
    }

    /**
     * After you sent `amount_to_send` to `refund_address`: the transaction that did it.
     *
     * @param array{txid: string} $params
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public function markSent(string $id, array $params, array $options = []): array
    {
        return $this->action($id, 'mark_sent', $params, $options);
    }
}
