<?php

/**
 * Perly Pay for FOSSBilling: bitcoin and USDC/USDT, paid straight to your
 * own wallet. Perly Pay never holds the money; it charges 2.5¢ a payment,
 * never more than 0.5% of it, billed after the month.
 *
 * A payment adapter (the client pays on Perly Pay's checkout, over the
 * invoice or on its own page). It lives in library/Payment/Adapter/Perlypay/:
 *
 *   Perlypay.php        this file: what FOSSBilling calls (getConfig, getHtml, processTransaction)
 *   lib/                the rules, testable without FOSSBilling, and the autoloader
 *   lang/               the client's words in English and Ukrainian
 *   vendor/perly-pay-sdk   Perly Pay's PHP SDK, prefixed at build time
 *
 * Perly Pay's signed webhooks land on FOSSBilling's own ipn.php
 * (the gateway's Callback URL), which hands each one to processTransaction.
 *
 *   https://docs.fossbilling.org/extensions-and-development/extensions/
 *   https://github.com/FOSSBilling/FOSSBilling/tree/main/src/library/Payment/Adapter
 */

if (!defined('PATH_LIBRARY')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/lib/autoload.php';

use PerlyPay\FOSSBilling\Callback;
use PerlyPay\FOSSBilling\CallbackError;
use PerlyPay\FOSSBilling\DbalStore;
use PerlyPay\FOSSBilling\Lang;
use PerlyPay\FOSSBilling\PayPage;
use PerlyPay\FOSSBilling\Services;
use PerlyPay\FOSSBilling\Settings;

class Payment_Adapter_Perlypay
{
    /** @var \ArrayAccess<string, mixed>|null FOSSBilling's dependency container */
    protected $di;

    private readonly Settings $settings;

    /**
     * FOSSBilling makes the adapter with the saved settings for every use, and once more to check them
     * when the gateway is enabled: a missing or wrong key for the gateway's mode stops it there.
     * The code is an HTTP status, so a webhook that arrives meanwhile is answered 503 and sent again.
     *
     * @param array<string, mixed> $config
     */
    public function __construct($config)
    {
        $this->settings = new Settings(is_array($config) ? $config : []);
        $problem = $this->settings->problem();
        if ($problem !== null) {
            throw new Payment_Exception($problem, null, 503);
        }
    }

    /** @param \ArrayAccess<string, mixed> $di */
    public function setDi($di): void
    {
        $this->di = $di;
    }

    /** @return \ArrayAccess<string, mixed>|null */
    public function getDi()
    {
        return $this->di;
    }

    /** @return array<string, mixed> */
    public static function getConfig(): array
    {
        $secret = static fn (string $label, string $help, bool $test): array => [
            'password', [
                'label' => $label,
                'description' => $help,
                'required_when' => ['enabled' => true, 'test_mode' => $test],
                'secret' => true,
            ],
        ];

        return [
            'supports_one_time_payments' => true,
            'supports_subscriptions' => false,
            'can_load_in_iframe' => false,
            'description' => '<p>Bitcoin, USDC and USDT, paid straight to your own wallet. Perly Pay never holds the money: 2.5¢ a payment, never more than 0.5% of it, billed after the month; a month of up to 21 payments costs nothing.</p>'
                . '<ol>'
                . '<li>Sign up at pay.perly.io and connect your wallet\'s public keys.</li>'
                . '<li>Paste a secret key from Perly Pay → Developers → API keys: the test key while <b>Enable Test Mode</b> is on, the live key for real money.</li>'
                . '<li>In Perly Pay → Developers → Webhooks, add this gateway\'s <b>Callback URL</b> (below, under Settings) with the invoice and refund events, once in test mode and once in live mode, and paste each signing secret. <b>Send test</b> there puts a "Perly Pay test event" in Invoices → Transactions when it works.</li>'
                . '<li>Set <b>Accepted Currencies</b> to USD and EUR: Perly Pay bills in those two only.</li>'
                . '</ol>',
            'logo' => [
                'logo' => 'Perlypay/logo.png',
                'height' => '40px',
                'width' => '40px',
            ],
            'form' => [
                'test_api_key' => $secret('Test secret key', 'Perly Pay → Developers → API keys, in test mode: `sk_test_…`. Used while Enable Test Mode is on; payments are test coins on test networks.', true),
                'test_webhook_secret' => $secret('Test webhook signing secret', 'The `whsec_…` secret of the test-mode webhook pointing at this gateway\'s Callback URL.', true),
                'api_key' => $secret('Live secret key', 'Perly Pay → Developers → API keys, in live mode: `sk_live_…`. Takes real money.', false),
                'webhook_secret' => $secret('Live webhook signing secret', 'The `whsec_…` secret of the live-mode webhook pointing at this gateway\'s Callback URL.', false),
                'checkout_mode' => [
                    'select', [
                        'label' => 'Payment page',
                        'description' => 'The overlay needs this FOSSBilling\'s address in Perly Pay → Settings → Checkout → "Sites that may embed the checkout". Phones always get the page itself.',
                        'multiOptions' => [
                            'overlay' => 'Over FOSSBilling (overlay)',
                            'redirect' => 'On pay.perly.io (redirect)',
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * The client chose Perly Pay and pressed Pay: the Perly Pay invoice for this one, and the button that opens it.
     *
     * @param mixed $api_admin FOSSBilling\Api\Proxy (unused: the adapter reads FOSSBilling's services directly)
     */
    public function getHtml($api_admin, $invoice_id, $subscription): string
    {
        return (new PayPage($this->settings, new Services($this->di), $this->store(), $this->settings->client(1), Lang::named($this->locale())))->render((int) $invoice_id);
    }

    /**
     * A Perly Pay event, as FOSSBilling's ipn.php stored it.
     *
     * @param mixed $api_admin
     * @param array<string, mixed> $data
     */
    public function processTransaction($api_admin, $id, $data, $gateway_id): void
    {
        try {
            (new Callback($this->settings, new Services($this->di), $this->store()))->handle((int) $id, is_array($data) ? $data : [], (int) $gateway_id);
        } catch (CallbackError $error) {
            throw new Payment_Exception($error->getMessage(), null, $error->status);
        }
    }

    private function store(): DbalStore
    {
        return new DbalStore($this->di['dbal']);
    }

    /** FOSSBilling's locale for this visitor ("uk_UA"), or null. */
    private function locale(): ?string
    {
        try {
            return class_exists('\\FOSSBilling\\i18n') ? \FOSSBilling\i18n::getActiveLocale($this->di['request'], false) : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
