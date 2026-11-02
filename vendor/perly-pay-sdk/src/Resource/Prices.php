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

/** `/prices`: how a product is sold (one-time, subscription, pay what you want, …). */
final class Prices extends AbstractResource
{
    use Creates, Lists, Retrieves, Updates;
}
