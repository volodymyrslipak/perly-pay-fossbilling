<?php

declare(strict_types=1);

namespace PerlyPay\FOSSBilling;

if (!defined('PATH_LIBRARY')) {
    die('This file cannot be accessed directly');
}

/**
 * What a pasted key or secret is, by its shape alone: Perly Pay's secret
 * keys are sk_test_… or sk_live_… and 32 letters or digits; a webhook
 * signing secret is whsec_… and 20 to 80.
 */
final class Keys
{
    /** "test", "live", or null when it is not a secret key. */
    public static function mode(string $key): ?string
    {
        return preg_match('/^sk_(test|live)_[0-9A-Za-z]{32}$/', trim($key), $m) === 1 ? $m[1] : null;
    }

    public static function isWebhookSecret(string $secret): bool
    {
        return preg_match('/^whsec_[0-9A-Za-z]{20,80}$/', trim($secret)) === 1;
    }
}
