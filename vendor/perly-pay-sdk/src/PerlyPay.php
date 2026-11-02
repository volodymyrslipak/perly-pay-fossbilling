<?php

declare(strict_types=1);

namespace PerlyPay\FOSSBilling\Vendor\Perly\Pay;

if (!defined('PATH_LIBRARY')) {
    die('This file cannot be accessed directly');
}

use PerlyPay\FOSSBilling\Vendor\Perly\Pay\Http\CurlTransport;
use PerlyPay\FOSSBilling\Vendor\Perly\Pay\Http\Requestor;
use PerlyPay\FOSSBilling\Vendor\Perly\Pay\Http\Transport;
use PerlyPay\FOSSBilling\Vendor\Perly\Pay\Resource\CheckoutSessions;
use PerlyPay\FOSSBilling\Vendor\Perly\Pay\Resource\Coupons;
use PerlyPay\FOSSBilling\Vendor\Perly\Pay\Resource\Credits;
use PerlyPay\FOSSBilling\Vendor\Perly\Pay\Resource\Customers;
use PerlyPay\FOSSBilling\Vendor\Perly\Pay\Resource\Entitlements;
use PerlyPay\FOSSBilling\Vendor\Perly\Pay\Resource\Events;
use PerlyPay\FOSSBilling\Vendor\Perly\Pay\Resource\Invoices;
use PerlyPay\FOSSBilling\Vendor\Perly\Pay\Resource\LicenseKeys;
use PerlyPay\FOSSBilling\Vendor\Perly\Pay\Resource\PaymentLinks;
use PerlyPay\FOSSBilling\Vendor\Perly\Pay\Resource\Payments;
use PerlyPay\FOSSBilling\Vendor\Perly\Pay\Resource\PortalSessions;
use PerlyPay\FOSSBilling\Vendor\Perly\Pay\Resource\Prices;
use PerlyPay\FOSSBilling\Vendor\Perly\Pay\Resource\Products;
use PerlyPay\FOSSBilling\Vendor\Perly\Pay\Resource\Receipts;
use PerlyPay\FOSSBilling\Vendor\Perly\Pay\Resource\Refunds;
use PerlyPay\FOSSBilling\Vendor\Perly\Pay\Resource\Subscriptions;
use PerlyPay\FOSSBilling\Vendor\Perly\Pay\Resource\WebhookEndpoints;

/**
 * Perly Pay's server client. Keep the secret key on your server.
 *
 *   $perly = new PerlyPay(getenv('PERLY_PAY_SECRET_KEY'));
 *   $session = $perly->checkoutSessions->create(['line_items' => [['price' => 'price_…']]]);
 *   header('Location: ' . $session['url']);
 *
 * Options: `base_url` (default https://pay.perly.io), `max_retries` (2),
 * `timeout` in seconds (30), `transport` (your own Transport).
 */
final class PerlyPay
{
    public const VERSION = '0.2.0';
    public const DEFAULT_BASE_URL = 'https://pay.perly.io';

    /** `test` for an sk_test_ key, `live` for sk_live_. */
    public readonly string $mode;
    public readonly string $baseUrl;

    public readonly Invoices $invoices;
    public readonly CheckoutSessions $checkoutSessions;
    public readonly Products $products;
    public readonly Prices $prices;
    public readonly Customers $customers;
    public readonly Subscriptions $subscriptions;
    public readonly PaymentLinks $paymentLinks;
    public readonly Coupons $coupons;
    public readonly Entitlements $entitlements;
    public readonly Credits $credits;
    public readonly LicenseKeys $licenseKeys;
    public readonly PortalSessions $portalSessions;
    public readonly Payments $payments;
    public readonly Refunds $refunds;
    public readonly Receipts $receipts;
    public readonly Events $events;
    public readonly WebhookEndpoints $webhookEndpoints;

    /**
     * @param array{base_url?: string, max_retries?: int, timeout?: float|int, transport?: Transport, sleep?: callable(int): void} $options
     */
    public function __construct(string $secretKey, array $options = [])
    {
        if (preg_match('/^sk_(test|live)_/', $secretKey) !== 1) {
            throw new \InvalidArgumentException('Perly Pay needs a secret key: sk_test_… or sk_live_…');
        }
        $this->mode = str_starts_with($secretKey, 'sk_live_') ? 'live' : 'test';
        $this->baseUrl = rtrim($options['base_url'] ?? self::DEFAULT_BASE_URL, '/');

        $requestor = new Requestor(
            $secretKey,
            $this->baseUrl . '/api/v1',
            $options['transport'] ?? new CurlTransport(),
            $options['max_retries'] ?? 2,
            (float) ($options['timeout'] ?? 30),
            $options['sleep'] ?? null,
        );

        $this->invoices = new Invoices($requestor, '/invoices');
        $this->checkoutSessions = new CheckoutSessions($requestor, '/checkout_sessions');
        $this->products = new Products($requestor, '/products');
        $this->prices = new Prices($requestor, '/prices');
        $this->customers = new Customers($requestor, '/customers');
        $this->subscriptions = new Subscriptions($requestor, '/subscriptions');
        $this->paymentLinks = new PaymentLinks($requestor, '/payment_links');
        $this->coupons = new Coupons($requestor, '/coupons');
        $this->entitlements = new Entitlements($requestor, '/entitlements');
        $this->credits = new Credits($requestor, '/credits');
        $this->licenseKeys = new LicenseKeys($requestor, '/license_keys');
        $this->portalSessions = new PortalSessions($requestor, '/portal_sessions');
        $this->payments = new Payments($requestor, '/payments');
        $this->refunds = new Refunds($requestor, '/refunds');
        $this->receipts = new Receipts($requestor, '/receipts');
        $this->events = new Events($requestor, '/events');
        $this->webhookEndpoints = new WebhookEndpoints($requestor, '/webhook_endpoints');
    }
}
