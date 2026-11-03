<?php

/**
 * Class loading without Composer on the FOSSBilling server: the adapter's
 * own classes under PerlyPay\FOSSBilling\ (lib/), and Perly Pay's PHP SDK,
 * copied in at build time under its own prefix
 * (PerlyPay\FOSSBilling\Vendor\Perly\Pay\, in vendor/perly-pay-sdk) so it
 * can never clash with another adapter that ships the SDK too.
 *
 * It lives in lib/, not beside Perlypay.php: FOSSBilling takes every .php
 * file directly in an adapter's folder for an adapter of its own.
 */

if (!defined('PATH_LIBRARY')) {
    die('This file cannot be accessed directly');
}

if (!defined('PERLYPAY_FOSSBILLING_VERSION')) {
    define('PERLYPAY_FOSSBILLING_VERSION', '1.0.1');

    spl_autoload_register(static function (string $class): void {
        $roots = [
            'PerlyPay\\FOSSBilling\\Vendor\\Perly\\Pay\\' => dirname(__DIR__) . '/vendor/perly-pay-sdk/src/',
            'PerlyPay\\FOSSBilling\\' => __DIR__ . '/',
        ];
        foreach ($roots as $prefix => $dir) {
            if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
                continue;
            }
            $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_readable($file)) {
                require_once $file;
            }

            return;
        }
    });
}
