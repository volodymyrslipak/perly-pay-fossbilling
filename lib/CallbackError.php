<?php

declare(strict_types=1);

namespace PerlyPay\FOSSBilling;

if (!defined('PATH_LIBRARY')) {
    die('This file cannot be accessed directly');
}

/**
 * A delivery the callback refused or could not finish. Its code is the
 * HTTP status: FOSSBilling's ipn.php answers with an exception's code when
 * it is between 400 and 599, and stores the message on the transaction.
 */
final class CallbackError extends \RuntimeException
{
    public function __construct(string $message, public readonly int $status)
    {
        parent::__construct($message, $status);
    }
}
