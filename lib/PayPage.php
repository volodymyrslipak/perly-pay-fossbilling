<?php

declare(strict_types=1);

namespace PerlyPay\FOSSBilling;

use PerlyPay\FOSSBilling\Vendor\Perly\Pay\Exception\IdempotencyException;
use PerlyPay\FOSSBilling\Vendor\Perly\Pay\Exception\PerlyPayException;
use PerlyPay\FOSSBilling\Vendor\Perly\Pay\PerlyPay;

if (!defined('PATH_LIBRARY')) {
    die('This file cannot be accessed directly');
}

/**
 * What the client sees after choosing Perly Pay on an invoice (the
 * adapter's getHtml, on FOSSBilling's "Processing Payment" page): the
 * Perly Pay invoice for this FOSSBilling invoice — the open one again while
 * it can still be paid for the same amount, else a new one — and a button
 * that opens it, over the page (overlay) or on its own (redirect).
 *
 * FOSSBilling runs getHtml only when the client picks the gateway and
 * presses Pay, never for an email or a list, so the Perly Pay invoice is
 * made here. What it bills is read from FOSSBilling's own invoice (the
 * total with tax, in its currency), never from the request.
 */
final class PayPage
{
    /** Perly Pay invoices the client can still pay. */
    private const PAYABLE = ['open', 'seen', 'underpaid'];

    /** New invoices tried in one visit before giving up. */
    private const ATTEMPTS = 5;

    public function __construct(
        private readonly Settings $settings,
        private readonly Fossbilling $fossbilling,
        private readonly Store $store,
        private readonly ?PerlyPay $client,
        private readonly Lang $lang,
    ) {
    }

    public function render(int $invoiceId): string
    {
        if ($this->client === null || $this->settings->problem() !== null) {
            return self::note($this->lang->t('pay.not_set_up'));
        }
        $invoice = $this->fossbilling->invoice($invoiceId);
        if ($invoice === null || $invoice['status'] !== 'unpaid') {
            return self::note($this->lang->t('pay.not_payable'));
        }
        if (!in_array($invoice['currency'], Money::CURRENCIES, true)) {
            return self::note($this->lang->t('pay.currency', implode($this->lang->t('pay.or'), Money::CURRENCIES)));
        }
        $minor = Money::toMinor($invoice['total']);
        if ($minor === null || $minor <= 0) {
            return self::note($this->lang->t('pay.not_payable'));
        }

        try {
            $made = $this->reuse($invoice, $minor) ?? $this->create($invoice, $minor);
        } catch (PerlyPayException $error) {
            $this->fossbilling->log('Perly Pay could not make an invoice for FOSSBilling invoice #' . $invoiceId . ': ' . $error->getErrorCode() . ' (request ' . $error->getRequestId() . ')');

            return self::note($this->lang->t('pay.unavailable'));
        }
        $url = Html::url($made['url']);
        if ($url === null) {
            return self::note($this->lang->t('pay.unavailable'));
        }

        return $this->page($made['id'], $url);
    }

    /**
     * The last Perly Pay invoice this gateway made for the invoice, if it still bills the same and can be paid.
     *
     * @param array{id: int, currency: string} $invoice
     * @return array{id: string, url: string}|null
     */
    private function reuse(array $invoice, int $minor): ?array
    {
        $row = $this->store->latestFor($invoice['id'], $this->settings->gatewayId(), $this->settings->mode());
        if ($row === null || $row['status'] !== 'open') {
            return null;
        }
        if ($row['amount'] !== $minor || $row['currency'] !== $invoice['currency']) {
            $this->store->setStatus($row['pay_invoice'], 'closed');

            return null;
        }
        try {
            $last = $this->client?->invoices->retrieve($row['pay_invoice'], ['max_retries' => 1]) ?? [];
        } catch (PerlyPayException) {
            return null;
        }
        if (in_array($last['status'] ?? '', self::PAYABLE, true) && ($last['total'] ?? null) === $minor && ($last['currency'] ?? '') === $invoice['currency']) {
            return ['id' => $row['pay_invoice'], 'url' => is_string($last['url'] ?? null) ? $last['url'] : $row['url']];
        }
        if (!in_array($last['status'] ?? '', self::PAYABLE, true)) {
            $this->store->setStatus($row['pay_invoice'], 'closed');
        }

        return null;
    }

    /**
     * A new Perly Pay invoice.
     *
     * @param array{id: int, number: string, hash: string, client_id: int, currency: string, email: string} $invoice
     * @return array{id: string, url: string}
     */
    private function create(array $invoice, int $minor): array
    {
        $attempt = $this->store->count($invoice['id']) + 1;
        $host = (string) parse_url($this->settings->systemUrl(), PHP_URL_HOST);
        $params = [
            'amount' => $minor,
            'currency' => $invoice['currency'],
            'description' => Html::cut($this->lang->t('pay.description', $invoice['number'], $host), 300),
            'locale' => $this->lang->name === 'uk' ? 'uk' : 'en',
            'metadata' => [
                'fossbilling_invoice' => (string) $invoice['id'],
                'fossbilling_invoice_number' => Html::cut($invoice['number'], 100),
                'fossbilling_client' => (string) $invoice['client_id'],
                'fossbilling_gateway' => (string) $this->settings->gatewayId(),
                'fossbilling' => Html::cut($host, 200),
                'source' => 'fossbilling',
                'adapter' => PERLYPAY_FOSSBILLING_VERSION,
            ],
        ];
        $return = $this->settings->returnUrl();
        if ($return !== null) {
            $params['return_url'] = $return;
        }
        $email = $invoice['email'];
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false && strlen($email) <= 254) {
            $params['customer_email'] = $email;
        }
        // The same FOSSBilling, gateway, invoice (its id and its random hash, so a reinstall that reuses ids
        // is another invoice), attempt and amount make the same Perly Pay invoice, however often the page is
        // opened. A key Perly Pay already answered for an invoice that can no longer be paid, or for other
        // details (this FOSSBilling's table was emptied or restored), moves on to the next attempt.
        for ($tries = 0; $tries < self::ATTEMPTS; $tries++, $attempt++) {
            $key = 'fossbilling_' . substr(hash('sha256', $this->settings->systemUrl() . '|' . $this->settings->gatewayId() . '|' . $invoice['id'] . '|' . $invoice['hash'] . '|' . $attempt . '|' . $minor . $invoice['currency']), 0, 40);
            try {
                $made = $this->client?->invoices->create($params, ['idempotency_key' => $key]) ?? [];
            } catch (IdempotencyException) {
                continue;
            }
            if (!is_string($made['id'] ?? null) || !is_string($made['url'] ?? null)) {
                throw new PerlyPayException('Perly Pay answered without an invoice.');
            }
            // A replay is the stored first answer, with the status of that moment: ask again when it is not new.
            $created = is_string($made['created'] ?? null) ? strtotime($made['created']) : false;
            if ($created !== false && $created < time() - 60) {
                $made = $this->client?->invoices->retrieve($made['id'], ['max_retries' => 1]) ?? $made;
            }
            $payable = in_array($made['status'] ?? 'open', self::PAYABLE, true) && ($made['total'] ?? $minor) === $minor && ($made['currency'] ?? $invoice['currency']) === $invoice['currency'];
            if ($this->store->byPayInvoice($made['id']) === null) {
                $this->store->add([
                    'pay_invoice' => $made['id'],
                    'fb_invoice' => $invoice['id'],
                    'gateway_id' => $this->settings->gatewayId(),
                    'mode' => $this->settings->mode(),
                    'amount' => $minor,
                    'currency' => $invoice['currency'],
                    'url' => $made['url'],
                ]);
            }
            if ($payable) {
                return ['id' => $made['id'], 'url' => $made['url']];
            }
            $this->store->setStatus($made['id'], 'closed');
        }
        throw new PerlyPayException('Perly Pay kept answering with invoices that cannot be paid.');
    }

    /**
     * The button and its script. The button is a plain link to the payment page, so it works with no
     * script at all; with the overlay, the script loads pay.js from Perly Pay (its integrity pinned at
     * build time) and opens the checkout over FOSSBilling's page instead. If pay.js cannot load, the
     * link is followed. Started once by itself when FOSSBilling asks for it (the client pressed Pay).
     */
    private function page(string $payInvoice, string $url): string
    {
        $pin = self::payScript();
        $overlay = $this->settings->overlay() && $pin !== null;
        $html = '<div class="perlypay">'
            . '<p><a class="btn btn-primary" id="perlypay-pay" href="' . Html::e($url) . '" rel="noopener">' . Html::e($this->lang->t('pay.button')) . '</a></p>'
            . '<p class="perlypay-note small text-muted">' . Html::e($this->lang->t('pay.note')) . '</p>';
        if ($this->settings->mode() === 'test') {
            $html .= '<p class="perlypay-test small text-warning">' . Html::e($this->lang->t('pay.test_mode')) . '</p>';
        }
        $html .= '</div>';
        $options = [
            'invoice' => $payInvoice,
            'url' => $url,
            'overlay' => $overlay,
            'script' => $pin['path'] ?? null,
            'integrity' => $pin['integrity'] ?? null,
            'paidUrl' => $this->settings->returnUrl(),
            'start' => $this->settings->autoStart(),
        ];

        return $html . '<script>(' . self::SCRIPT . ')(' . Html::js($options) . ');</script>';
    }

    /** @return array{path: string, integrity: string}|null pay.js as published, pinned at build time */
    public static function payScript(): ?array
    {
        $file = __DIR__ . '/pay-script.php';
        $pin = is_readable($file) ? require $file : null;

        return is_array($pin) && is_string($pin['path'] ?? null) && is_string($pin['integrity'] ?? null) ? $pin : null;
    }

    private static function note(string $text): string
    {
        return '<p class="perlypay-note">' . Html::e($text) . '</p>';
    }

    /** The overlay, inline: FOSSBilling's payment page prints what getHtml returns and nothing else. */
    private const SCRIPT = <<<'JS'
        function (o) {
            var link = document.getElementById('perlypay-pay');
            if (!link) { return; }
            var loading = false;
            function follow() { window.location.assign(o.url); }
            function overlay() {
                var origin = new URL(o.url).origin;
                function open() {
                    window.PerlyPay.open({ invoice: o.invoice, baseUrl: origin, paidUrl: o.paidUrl || window.location.href, mode: 'auto' });
                }
                if (window.PerlyPay && typeof window.PerlyPay.open === 'function') { open(); return; }
                if (loading) { return; }
                loading = true;
                var script = document.createElement('script');
                script.src = origin + o.script;
                script.integrity = o.integrity;
                script.crossOrigin = 'anonymous';
                script.onload = function () {
                    if (window.PerlyPay && typeof window.PerlyPay.open === 'function') { open(); } else { follow(); }
                };
                script.onerror = follow;
                document.head.appendChild(script);
            }
            if (o.overlay) {
                link.addEventListener('click', function (event) { event.preventDefault(); overlay(); });
            }
            if (o.start) {
                if (o.overlay) { overlay(); } else { follow(); }
            }
        }
        JS;
}
