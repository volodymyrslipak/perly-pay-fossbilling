<?php

declare(strict_types=1);

namespace PerlyPay\FOSSBilling\Vendor\Perly\Pay\Exception;

if (!defined('PATH_LIBRARY')) {
    die('This file cannot be accessed directly');
}

/** The request never got an answer: network down, DNS, timeout. */
final class ConnectionException extends PerlyPayException
{
}
