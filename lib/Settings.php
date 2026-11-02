<?php

declare(strict_types=1);

namespace PerlyPay\FOSSBilling;

use PerlyPay\FOSSBilling\Vendor\Perly\Pay\Http\Transport;
use PerlyPay\FOSSBilling\Vendor\Perly\Pay\PerlyPay;

if (!defined('PATH_LIBRARY')) {
    die('This file cannot be accessed directly');
}

/**
 * The gateway's settings as FOSSBilling passes them to the adapter: what
 * the admin saved (the keys, the payment page) merged with what
 * FOSSBilling adds itself (test_mode, gateway_id, the invoice's URLs).
 *
 * Like FOSSBilling's own Stripe adapter, a gateway holds a test and a live
 * key, and its "Enable Test Mode" switch picks one: sk_test_… pays on test
 * networks, sk_live_… takes real money. Each has its own webhook secret,
 * since Perly Pay signs test and live events with different endpoints.
 */
final class Settings
{
    public const DEFAULT_BASE = 'https://pay.perly.io';

    /** Tests (and only tests) answer the API from here instead of the network. */
    public static ?Transport $transport = null;

    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config)
    {
    }

    /** "test" or "live": FOSSBilling's test mode switch for this gateway. */
    public function mode(): string
    {
        $flag = $this->config['test_mode'] ?? false;

        return $flag === true || $flag === 1 || $flag === '1' || $flag === 'true' ? 'test' : 'live';
    }

    public function apiKey(?string $mode = null): string
    {
        return trim($this->string(($mode ?? $this->mode()) === 'test' ? 'test_api_key' : 'api_key'));
    }

    public function webhookSecret(?string $mode = null): string
    {
        return trim($this->string(($mode ?? $this->mode()) === 'test' ? 'test_webhook_secret' : 'webhook_secret'));
    }

    /**
     * Why this gateway cannot take payments in its mode, for the admin; null when it can.
     * A key of the other mode in a field counts as missing: test keys never take real money.
     */
    public function problem(): ?string
    {
        $mode = $this->mode();
        $field = $mode === 'test' ? 'Test secret key' : 'Live secret key';
        if (Keys::mode($this->apiKey()) !== $mode) {
            return $this->apiKey() === ''
                ? 'Perly Pay: paste the ' . strtolower($field) . ' (sk_' . $mode . '_…) from Perly Pay → Developers → API keys.'
                : 'Perly Pay: the ' . strtolower($field) . ' must start with sk_' . $mode . '_ and have 32 letters or digits after it.';
        }
        if (!Keys::isWebhookSecret($this->webhookSecret())) {
            return 'Perly Pay: paste the ' . $mode . ' webhook\'s signing secret (whsec_…) from Perly Pay → Developers → Webhooks.';
        }

        return null;
    }

    public function overlay(): bool
    {
        return $this->string('checkout_mode') !== 'redirect';
    }

    /**
     * The pay_gateway row this adapter was made for. FOSSBilling passes it from 0.8.5; before that it is
     * read from the callback URL it passes (ipn.php?gateway_id=…), which every version has.
     */
    public function gatewayId(): int
    {
        $id = $this->config['gateway_id'] ?? null;
        if ($id === null) {
            parse_str((string) parse_url($this->string('notify_url'), PHP_URL_QUERY), $query);
            $id = $query['gateway_id'] ?? 0;
        }

        return is_int($id) || (is_string($id) && ctype_digit($id)) ? (int) $id : 0;
    }

    /** FOSSBilling's System URL, with its trailing slash. */
    public function systemUrl(): string
    {
        $url = $this->string('system_url');
        if ($url === '' && defined('SYSTEM_URL') && is_string(SYSTEM_URL)) {
            $url = SYSTEM_URL;
        }

        return rtrim($url, '/') . '/';
    }

    /** Where Perly Pay sends events: FOSSBilling's ipn.php for this gateway (the "Callback URL" on its settings page). */
    public function callbackUrl(): string
    {
        return $this->systemUrl() . 'ipn.php?' . http_build_query(['gateway_id' => $this->gatewayId()]);
    }

    /**
     * Where the client goes back to once paid: FOSSBilling's payment return page for the invoice being
     * paid (it forwards to the invoice in a way the client's session survives), else the invoice itself.
     */
    public function returnUrl(): ?string
    {
        return Html::url($this->string('return_url')) ?? Html::url($this->string('invoice_url'));
    }

    /** True when FOSSBilling asks the payment page to start by itself (its "Pay now" on the invoice). */
    public function autoStart(): bool
    {
        return ($this->config['auto_redirect'] ?? false) === true;
    }

    /**
     * Perly Pay's address. Locally (Perly Pay's own tests) PERLY_PAY_API_BASE is
     * defined in FOSSBilling's config.php; on a real FOSSBilling it is pay.perly.io.
     */
    public static function apiBase(): string
    {
        $base = defined('PERLY_PAY_API_BASE') && is_string(PERLY_PAY_API_BASE) ? PERLY_PAY_API_BASE : self::DEFAULT_BASE;

        return rtrim(Html::url($base) ?? self::DEFAULT_BASE, '/');
    }

    /** An API client for the key of a mode (this gateway's by default), or null without a valid one. */
    public function client(int $maxRetries = 2, ?string $mode = null): ?PerlyPay
    {
        $mode ??= $this->mode();
        $key = $this->apiKey($mode);
        if (Keys::mode($key) !== $mode) {
            return null;
        }
        $options = ['base_url' => self::apiBase(), 'max_retries' => $maxRetries, 'timeout' => 20];
        if (self::$transport !== null) {
            $options['transport'] = self::$transport;
            $options['sleep'] = static function (int $ms): void {
            };
        }

        return new PerlyPay($key, $options);
    }

    private function string(string $key): string
    {
        $value = $this->config[$key] ?? '';

        return is_string($value) ? $value : (is_scalar($value) ? (string) $value : '');
    }
}
