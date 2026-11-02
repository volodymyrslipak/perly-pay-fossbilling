<?php

declare(strict_types=1);

namespace PerlyPay\FOSSBilling\Vendor\Perly\Pay\Exception;

if (!defined('PATH_LIBRARY')) {
    die('This file cannot be accessed directly');
}

/** The key is good but may not do this. */
final class PermissionException extends PerlyPayException
{
}
