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

/** `/customers`: who buys. */
final class Customers extends AbstractResource
{
    use Creates, Lists, Retrieves, Updates;
}
