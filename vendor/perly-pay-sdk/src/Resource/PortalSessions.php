<?php

declare(strict_types=1);

namespace PerlyPay\FOSSBilling\Vendor\Perly\Pay\Resource;

if (!defined('PATH_LIBRARY')) {
    die('This file cannot be accessed directly');
}

use PerlyPay\FOSSBilling\Vendor\Perly\Pay\Resource\Concerns\Creates;

/** `/portal_sessions`: a link that lets a customer manage their subscriptions and receipts. */
final class PortalSessions extends AbstractResource
{
    use Creates;
}
