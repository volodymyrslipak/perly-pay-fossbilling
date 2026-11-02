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

/** `/coupons`: codes that take an amount or a share off. */
final class Coupons extends AbstractResource
{
    use Creates, Lists, Retrieves, Updates;
}
