<?php

declare(strict_types=1);

namespace PerlyPay\FOSSBilling\Vendor\Perly\Pay\Exception;

if (!defined('PATH_LIBRARY')) {
    die('This file cannot be accessed directly');
}

/** A field is wrong, missing or not allowed; getParam() names it. */
final class InvalidRequestException extends PerlyPayException
{
}
