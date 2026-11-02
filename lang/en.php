<?php

/**
 * Perly Pay for FOSSBilling, in English. One line per string: build.mjs reads
 * this file and lang/uk.php and fails when either misses a string the
 * adapter uses or their placeholders differ.
 */

if (!defined('PATH_LIBRARY')) {
    die('This file cannot be accessed directly');
}

return [
    'pay.button' => 'Pay with crypto',
    'pay.note' => 'Bitcoin, USDC or USDT from any wallet.',
    'pay.test_mode' => 'Test mode: nothing real moves.',
    'pay.or' => ' or ',
    'pay.currency' => 'Crypto payment takes %s only. Please choose another way to pay.',
    'pay.not_set_up' => 'Crypto payment is not set up yet. Please choose another way to pay.',
    'pay.unavailable' => 'Crypto payment is not available right now. Please try again, or choose another way to pay.',
    'pay.not_payable' => 'This invoice is not waiting for payment.',
    'pay.description' => 'Invoice %1$s at %2$s',
];
