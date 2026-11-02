<?php

declare(strict_types=1);

namespace PerlyPay\FOSSBilling\Vendor\Perly\Pay\Exception;

if (!defined('PATH_LIBRARY')) {
    die('This file cannot be accessed directly');
}

/** Too many requests; already retried with backoff before it is raised. */
final class RateLimitException extends PerlyPayException
{
}
