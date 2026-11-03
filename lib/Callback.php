<?php

declare(strict_types=1);

namespace PerlyPay\FOSSBilling;

use PerlyPay\FOSSBilling\Vendor\Perly\Pay\Exception\PerlyPayException;
use PerlyPay\FOSSBilling\Vendor\Perly\Pay\Exception\SignatureVerificationException;
use PerlyPay\FOSSBilling\Vendor\Perly\Pay\Webhook;

if (!defined('PATH_LIBRARY')) {
    die('This file cannot be accessed directly');
}

/**
 * What a Perly Pay event does in FOSSBilling (the adapter's
 * processTransaction). Perly Pay posts its events to FOSSBilling's
 * ipn.php?gateway_id=…, which stores each delivery as a transaction and
 * hands it here.
 *
 * A delivery counts only with a good Perly-Signature (HMAC-SHA256 of the
 * timestamp and the raw body under the webhook's secret, checked by the
 * SDK in constant time), at most five minutes old when FOSSBilling received
 * it — so an admin may press Process on a failed delivery later — and
 * signed with the secret of the mode it claims (a test event never pays a
 * live invoice). One that fails changes nothing. Then, for a Perly Pay
 * invoice this gateway made:
 *
 *   invoice.fulfilled (or paid/overpaid once fulfilled)  → the amount the Perly Pay invoice was made
 *       for goes to the client's balance and pays the invoice, as FOSSBilling's own Stripe adapter
 *       does; the transaction keeps the Perly Pay payment (pay_…) as its id, the amount, and a note
 *       naming the network, the coin amount and a transaction link
 *   invoice.underpaid / review / expired / canceled  → a note on the transaction and on the invoice, no payment
 *   invoice.refunded, refund.*                       → a note on the transaction
 *   invoice.seen, paid/overpaid before fulfilment    → a line in the activity log; the delivery is not kept
 *
 * A signed event is not taken at its word: the webhook secret alone is not
 * proof of payment. For invoice.* the invoice is retrieved from Perly Pay
 * with the gateway's API key for its mode and only that answer is used
 * (status, mode, fulfilment, amounts); an event it does not bear out (an
 * "invoice.fulfilled" for an invoice still open) is acknowledged and leaves
 * no transaction behind. For refund.* the refund is retrieved the same way,
 * must be the one the event names and of the invoice it names, and its own
 * status, amounts, address and transaction are what the note says: an
 * address in a signed body is never one the admin is told to send to.
 *
 * A payment is recorded once: the row is claimed before the balance is
 * touched (a second delivery at the same moment waits for a retry) and
 * FOSSBilling's own claim on the transaction stands behind that. Each event
 * id is applied once; a duplicate, or another shop's event on the same
 * Perly Pay account, leaves no transaction behind. Keys, secrets and
 * signatures are never written anywhere.
 *
 * Errors carry an HTTP status, which FOSSBilling's ipn.php answers with:
 * 400 for a bad signature, 503 until configured, while another delivery is
 * recording the same payment, while the invoice or the refund cannot be
 * retrieved from Perly Pay, or when recording failed, and 500 when the
 * FOSSBilling invoice is gone. Perly Pay retries anything but 2xx for 72
 * hours. FOSSBilling before 0.8.9 answers 200 for every code but 503, so
 * only those are retried there; the failed delivery stays in Transactions,
 * where Process records it.
 */
final class Callback
{
    public const EVENT_TYPES = [
        'invoice.seen',
        'invoice.underpaid',
        'invoice.paid',
        'invoice.overpaid',
        'invoice.review',
        'invoice.expired',
        'invoice.canceled',
        'invoice.fulfilled',
        'invoice.refunded',
        'refund.requested',
        'refund.address_received',
        'refund.sent',
        'refund.confirmed',
        'refund.canceled',
    ];

    /** Seconds a delivery waits for another one recording the same payment before asking to be sent again. */
    private const WAIT_SECONDS = 8;

    /** @var callable(): int */
    private $clock;

    /** @var callable(int): void */
    private $sleep;

    /**
     * @param (callable(): int)|null $clock unix seconds (tests)
     * @param (callable(int): void)|null $sleep waits that many seconds (tests)
     */
    public function __construct(
        private readonly Settings $settings,
        private readonly Fossbilling $fossbilling,
        private readonly Store $store,
        ?callable $clock = null,
        ?callable $sleep = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
        $this->sleep = $sleep ?? static function (int $seconds): void {
            sleep($seconds);
        };
    }

    /**
     * @param array<string, mixed> $ipn what FOSSBilling stored for the delivery: http_raw_post_data, server (its headers)
     * @return string what was done: paid, already_paid, noted, logged, test, duplicate, not_ours, not_what_perly_pay_says,
     *                ignored, in_progress
     *
     * @throws CallbackError
     */
    public function handle(int $transactionId, array $ipn, int $gatewayId): string
    {
        $tx = $this->fossbilling->transaction($transactionId);
        if ($tx === null) {
            throw new CallbackError('Perly Pay: transaction #' . $transactionId . ' is not in FOSSBilling.', 500);
        }
        [$event, $mode] = $this->verify($ipn, $tx['received_at']);

        $type = is_string($event['type'] ?? null) ? $event['type'] : '';
        $id = is_string($event['id'] ?? null) ? $event['id'] : '';
        $object = is_array($event['data']['object'] ?? null) ? $event['data']['object'] : null;
        if ($type === 'webhook.test') {
            $this->fossbilling->updateTransaction($transactionId, [
                'txn_id' => $id,
                'txn_status' => $type,
                'status' => 'processed',
                'note' => 'Perly Pay test event (' . $mode . '): this gateway\'s webhook and its signing secret work.',
            ]);

            return 'test';
        }
        if (!in_array($type, self::EVENT_TYPES, true) || $id === '' || $object === null) {
            $this->fossbilling->dropTransaction($transactionId);

            return 'ignored';
        }

        $payInvoice = str_starts_with($type, 'refund.') ? ($object['invoice'] ?? null) : ($object['id'] ?? null);
        $row = is_string($payInvoice) ? $this->store->byPayInvoice($payInvoice) : null;
        if ($row === null || $row['gateway_id'] !== $gatewayId || $row['mode'] !== $mode) {
            // Another FOSSBilling (or gateway, or shop) on the same Perly Pay account made it: theirs to handle.
            $this->fossbilling->dropTransaction($transactionId);

            return 'not_ours';
        }
        if ($this->store->hasEvent($row['pay_invoice'], $id)) {
            $this->fossbilling->dropTransaction($transactionId);

            return 'duplicate';
        }
        // Until it is recorded, the delivery is named by its event: a failed one is found again by it.
        $this->fossbilling->updateTransaction($transactionId, ['txn_id' => $id, 'txn_status' => $type, 'invoice_id' => $row['fb_invoice']]);

        // The webhook secret alone is not proof of payment: anyone who holds it (a developer who revealed it, a
        // leaked config) can sign an "invoice.fulfilled" for any invoice, or a "refund.address_received" naming their
        // own address. So the event only names the invoice or the refund; it is asked of Perly Pay with this
        // gateway's own key, and that answer decides everything below.
        $object = str_starts_with($type, 'refund.') ? $this->retrieveRefund($row, $object['id'] ?? null, $id, $type) : $this->retrieve($row, $id, $type);
        if ($object === null) {
            $this->fossbilling->dropTransaction($transactionId);

            return 'not_ours';
        }
        if (!(str_starts_with($type, 'refund.') ? self::refundAgrees($type, $object) : self::agrees($type, $object))) {
            // Signed, but Perly Pay says otherwise (forged, or overtaken by a later event): nothing to do or retry.
            $this->fossbilling->dropTransaction($transactionId);

            return 'not_what_perly_pay_says';
        }

        $fulfilled = !str_starts_with($type, 'refund.') && !empty($object['fulfilled_at'])
            && in_array($type, ['invoice.fulfilled', 'invoice.paid', 'invoice.overpaid'], true);
        if ($fulfilled) {
            $result = $this->recordPayment($transactionId, $row, $id, $object, $gatewayId);
        } else {
            $result = str_starts_with($type, 'refund.') ? $this->noteRefund($transactionId, $row, $type, $object) : $this->noteInvoice($transactionId, $row, $type, $object);
        }
        if ($result !== 'in_progress') {
            $this->store->rememberEvent($row['pay_invoice'], $id);
        }

        return $result;
    }

    /**
     * The event, and the mode whose secret signed it.
     *
     * @param array<string, mixed> $ipn
     * @return array{0: array<string, mixed>, 1: string}
     *
     * @throws CallbackError
     */
    private function verify(array $ipn, int $receivedAt): array
    {
        $payload = is_string($ipn['http_raw_post_data'] ?? null) ? $ipn['http_raw_post_data'] : '';
        $server = is_array($ipn['server'] ?? null) ? $ipn['server'] : [];
        $header = is_string($server['HTTP_PERLY_SIGNATURE'] ?? null) ? $server['HTTP_PERLY_SIGNATURE'] : null;
        $refused = null;
        $any = false;
        foreach (['live', 'test'] as $mode) {
            $secret = $this->settings->webhookSecret($mode);
            if (!Keys::isWebhookSecret($secret)) {
                continue;
            }
            $any = true;
            try {
                $event = Webhook::verify($payload, $header, $secret, Webhook::DEFAULT_TOLERANCE_SECONDS, $receivedAt);
            } catch (SignatureVerificationException $error) {
                // With both secrets saved, the one that does not match says less than why the other failed.
                $refused = $refused === null || $error->getErrorCode() !== 'signature_mismatch' ? $error->getErrorCode() : $refused;
                continue;
            }
            $livemode = $event['data']['object']['livemode'] ?? null;
            if (is_bool($livemode) && ($livemode ? 'live' : 'test') !== $mode) {
                throw new CallbackError('Perly Pay: an event signed with the ' . $mode . ' secret is for ' . ($livemode ? 'live' : 'test') . ' mode.', 400);
            }

            return [$event, $mode];
        }
        if (!$any) {
            throw new CallbackError('Perly Pay: paste the webhook\'s signing secret (whsec_…) in the gateway settings.', 503);
        }

        throw new CallbackError('Perly Pay: Perly-Signature refused (' . ($refused ?? 'unknown') . '). Check the webhook signing secret in the gateway settings.', 400);
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $invoice
     *
     * @throws CallbackError
     */
    private function recordPayment(int $transactionId, array $row, string $eventId, array $invoice, int $gatewayId): string
    {
        $token = bin2hex(random_bytes(12));
        // Perly Pay sends invoice.paid and invoice.fulfilled together: the one that comes second waits a
        // moment for the first to finish, rather than leaving a failed delivery behind until a retry.
        for ($waited = 0; !$this->store->claim($row['pay_invoice'], $token, ($this->clock)()); $waited++) {
            $again = $this->store->byPayInvoice($row['pay_invoice']);
            if ($again !== null && $again['payment'] !== null) {
                $this->fossbilling->dropFailed($gatewayId, $eventId, $transactionId);
                $this->fossbilling->dropTransaction($transactionId);

                return 'already_paid';
            }
            if ($waited >= self::WAIT_SECONDS) {
                throw new CallbackError('Perly Pay: another delivery is recording this payment; Perly Pay will send it again.', 503);
            }
            ($this->sleep)(1);
        }
        if (!$this->fossbilling->claimTransaction($transactionId)) {
            // Someone pressed Process on this very delivery at the same moment: theirs to finish.
            $this->store->release($row['pay_invoice'], $token);

            return 'in_progress';
        }
        $fbInvoice = $this->fossbilling->invoice($row['fb_invoice']);
        if ($fbInvoice === null) {
            $this->store->release($row['pay_invoice'], $token);

            throw new CallbackError('Perly Pay: invoice ' . $row['pay_invoice'] . ' is paid, but FOSSBilling invoice #' . $row['fb_invoice'] . ' is gone. Record it by hand.', 500);
        }
        $payments = $this->payments($row);
        $payment = self::paymentId($payments) ?? $row['pay_invoice'];
        $amount = Money::fromMinor($row['amount']);
        try {
            $paidNow = $this->fossbilling->recordPayment($row['fb_invoice'], $transactionId, $amount, 'Perly Pay payment ' . $payment . ' (' . $row['pay_invoice'] . ')');
        } catch (\Throwable $error) {
            $this->store->release($row['pay_invoice'], $token);

            throw new CallbackError('Perly Pay: the payment was not recorded (' . get_class($error) . ': ' . Html::cut($error->getMessage(), 200) . '). Perly Pay will send it again.', 503);
        }
        $this->store->markPaid($row['pay_invoice'], $payment);

        $lines = [
            'Perly Pay ' . $row['pay_invoice'] . ' (' . ($row['mode'] === 'test' ? 'test mode, no real money' : 'live') . '): ' . $amount . ' ' . $row['currency'] . ' paid.',
        ];
        foreach (self::transfers($payments, $invoice) as $line) {
            $lines[] = $line;
        }
        if (($invoice['status'] ?? '') === 'overpaid') {
            $lines[] = 'Overpaid: ' . self::usd($invoice, 'received_usd') . ' received for ' . self::usd($invoice, 'total_usd') . '. Refund the difference in Perly Pay if you need to.';
        }
        if (!$paidNow) {
            $lines[] = 'The amount is on the client\'s balance, but did not pay the invoice: it was paid already, or its total is now ' . $fbInvoice['total'] . ' ' . $fbInvoice['currency'] . '.';
            $this->fossbilling->note($row['fb_invoice'], implode(' ', $lines));
        }
        $this->fossbilling->dropFailed($gatewayId, $eventId, $transactionId);
        $this->fossbilling->updateTransaction($transactionId, [
            'invoice_id' => $row['fb_invoice'],
            'txn_id' => $payment,
            'txn_status' => 'invoice.fulfilled',
            'amount' => $amount,
            'currency' => $row['currency'],
            'type' => 'payment',
            'status' => 'processed',
            'error' => null,
            'error_code' => null,
            'note' => Html::cut(implode("\n", $lines), 2000),
        ]);

        return 'paid';
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $invoice
     */
    private function noteInvoice(int $transactionId, array $row, string $type, array $invoice): string
    {
        $head = 'Perly Pay ' . $row['pay_invoice'] . ' (' . ($row['mode'] === 'test' ? 'test mode' : 'live') . ')';
        $chain = is_array($invoice['payment'] ?? null) && is_string($invoice['payment']['chain'] ?? null) ? $invoice['payment']['chain'] : '';
        $on = $chain !== '' ? ' on ' . Chains::name($chain) : '';
        $received = self::usd($invoice, 'received_usd') . ' of ' . self::usd($invoice, 'total_usd');
        $toInvoice = true;
        switch ($type) {
            case 'invoice.seen':
                $this->fossbilling->log($head . ': payment seen' . $on . ' for FOSSBilling invoice #' . $row['fb_invoice'] . ', waiting for confirmations.');
                $this->fossbilling->dropTransaction($transactionId);

                return 'logged';
            case 'invoice.paid':
            case 'invoice.overpaid':
                $this->fossbilling->log($head . ': payment received' . $on . ' for FOSSBilling invoice #' . $row['fb_invoice'] . '; Perly Pay is checking the sender, the invoice is paid when that is done.');
                $this->fossbilling->dropTransaction($transactionId);

                return 'logged';
            case 'invoice.underpaid':
                $note = $head . ': part paid' . $on . ', ' . $received . '. No payment recorded; the client can still send the rest.';
                break;
            case 'invoice.review':
                $reason = is_string($invoice['review_reason'] ?? null) ? $invoice['review_reason'] : 'review';
                $note = $head . ': payment' . $on . ' held for review (' . $reason . '). No payment recorded until you decide in Perly Pay.';
                break;
            case 'invoice.expired':
            case 'invoice.canceled':
                if ($row['status'] === 'open') {
                    $this->store->setStatus($row['pay_invoice'], 'closed');
                }
                $what = $type === 'invoice.expired' ? 'expired' : 'canceled';
                if (is_int($invoice['received_usd'] ?? null) && $invoice['received_usd'] > 0) {
                    $note = $head . ': ' . $what . ' with part paid, ' . $received . '. No payment recorded; refund it or accept it in Perly Pay.';
                } else {
                    $note = $head . ': ' . $what . ' unpaid. No payment recorded; paying again makes a new Perly Pay invoice.';
                    $toInvoice = false;
                }
                break;
            case 'invoice.refunded':
                $note = $head . ': ' . (($invoice['status'] ?? '') === 'refunded' ? 'refunded' : 'partly refunded') . ' in Perly Pay.';
                break;
            default:
                $note = $head . ': ' . $type . '.';
        }
        $this->fossbilling->updateTransaction($transactionId, ['status' => 'processed', 'note' => Html::cut($note, 2000)]);
        if ($toInvoice) {
            $this->fossbilling->note($row['fb_invoice'], $note);
        }

        return 'noted';
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $refund
     */
    private function noteRefund(int $transactionId, array $row, string $type, array $refund): string
    {
        $chain = is_string($refund['chain'] ?? null) ? $refund['chain'] : '';
        $decimals = is_int($refund['decimals'] ?? null) ? $refund['decimals'] : 0;
        $asset = Chains::asset(is_string($refund['asset'] ?? null) ? $refund['asset'] : '', $chain);
        $txid = is_string($refund['txid'] ?? null) ? $refund['txid'] : '';
        $what = match ($type) {
            'refund.requested' => 'refund opened',
            'refund.address_received' => 'refund ready to send',
            'refund.sent' => 'refund sent',
            'refund.confirmed' => 'refund confirmed',
            default => 'refund canceled',
        };
        $note = 'Perly Pay ' . $row['pay_invoice'] . ': ' . $what . ' (' . (is_string($refund['id'] ?? null) ? $refund['id'] : '') . ')';
        $send = is_string($refund['amount_to_send'] ?? null) ? $refund['amount_to_send'] : (is_string($refund['amount'] ?? null) ? $refund['amount'] : '');
        if ($send !== '') {
            $note .= ', ' . Money::units($send, $decimals) . ' ' . $asset . ($chain !== '' ? ' on ' . Chains::name($chain) : '');
        }
        $note .= '.';
        if ($type === 'refund.address_received' && is_string($refund['refund_address'] ?? null)) {
            $note .= ' Send it to ' . $refund['refund_address'] . ' from your wallet, then paste the transaction id in Perly Pay.';
        }
        if ($txid !== '') {
            $note .= ' Transaction: ' . (Chains::txUrl($chain, $txid) ?? $txid);
        }
        $this->fossbilling->updateTransaction($transactionId, ['status' => 'processed', 'type' => 'refund', 'note' => Html::cut($note, 2000)]);

        return 'noted';
    }

    /**
     * The invoice as Perly Pay's API has it for the row's mode; null when that key has no such invoice (another
     * shop's), a 503 (Perly Pay retries) when there is no key or Perly Pay could not be asked.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>|null
     *
     * @throws CallbackError
     */
    private function retrieve(array $row, string $eventId, string $type): ?array
    {
        $client = $this->settings->client(1, $row['mode']);
        if ($client === null) {
            throw new CallbackError('Perly Pay: paste the ' . $row['mode'] . ' API key in the gateway settings; the event is checked with it before anything is recorded.', 503);
        }
        try {
            $invoice = $client->invoices->retrieve($row['pay_invoice'], ['max_retries' => 1]);
        } catch (PerlyPayException $error) {
            if ($error->getHttpStatus() === 404) {
                return null;
            }
            $this->fossbilling->log('Perly Pay ' . $row['pay_invoice'] . ': ' . $type . ' (' . $eventId . ') not applied yet, the invoice could not be retrieved (' . $error->getErrorCode() . ').');

            throw new CallbackError('Perly Pay: invoice ' . $row['pay_invoice'] . ' could not be retrieved (' . $error->getErrorCode() . '); Perly Pay will send the event again.', 503);
        }
        if (($invoice['id'] ?? null) !== $row['pay_invoice'] || ($invoice['livemode'] ?? null) !== ($row['mode'] === 'live')) {
            return null;
        }

        return $invoice;
    }

    /**
     * The refund as Perly Pay's API has it for the row's mode, which must be the refund the event named and of the
     * row's invoice; null when that key has no such refund (another shop's), a 503 (Perly Pay retries) when there is
     * no key or Perly Pay could not be asked.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>|null
     *
     * @throws CallbackError
     */
    private function retrieveRefund(array $row, mixed $refundId, string $eventId, string $type): ?array
    {
        if (!is_string($refundId) || $refundId === '') {
            return null;
        }
        $client = $this->settings->client(1, $row['mode']);
        if ($client === null) {
            throw new CallbackError('Perly Pay: paste the ' . $row['mode'] . ' API key in the gateway settings; the event is checked with it before anything is recorded.', 503);
        }
        try {
            $refund = $client->refunds->retrieve($refundId, ['max_retries' => 1]);
        } catch (PerlyPayException $error) {
            if ($error->getHttpStatus() === 404) {
                return null;
            }
            $this->fossbilling->log('Perly Pay ' . $row['pay_invoice'] . ': ' . $type . ' (' . $eventId . ') not applied yet, the refund could not be retrieved (' . $error->getErrorCode() . ').');

            throw new CallbackError('Perly Pay: refund ' . Html::cut($refundId, 64) . ' could not be retrieved (' . $error->getErrorCode() . '); Perly Pay will send the event again.', 503);
        }
        if (($refund['id'] ?? null) !== $refundId || ($refund['invoice'] ?? null) !== $row['pay_invoice'] || ($refund['livemode'] ?? null) !== ($row['mode'] === 'live')) {
            return null;
        }

        return $refund;
    }

    /**
     * Whether the refund Perly Pay returned is in a state the event can mean, now or after it moved on: one that was
     * opened is any refund; "address_received" needs one ready to send to an address (only then is there something
     * to send); "sent" one sent or confirmed; "confirmed" and "canceled" their own status.
     *
     * @param array<string, mixed> $refund
     */
    private static function refundAgrees(string $type, array $refund): bool
    {
        $status = is_string($refund['status'] ?? null) ? $refund['status'] : '';

        return match ($type) {
            'refund.requested' => in_array($status, ['requested', 'awaiting_address', 'ready_to_send', 'sent', 'confirmed', 'canceled'], true),
            'refund.address_received' => $status === 'ready_to_send' && is_string($refund['refund_address'] ?? null) && $refund['refund_address'] !== '',
            'refund.sent' => in_array($status, ['sent', 'confirmed'], true),
            'refund.confirmed' => $status === 'confirmed',
            'refund.canceled' => $status === 'canceled',
            default => false,
        };
    }

    /**
     * Whether the invoice Perly Pay returned is in a state the event can mean. An "invoice.fulfilled" needs a paid
     * invoice that is fulfilled; the rest need the status they are named for (a refund event, either refund status).
     *
     * @param array<string, mixed> $invoice
     */
    private static function agrees(string $type, array $invoice): bool
    {
        $status = is_string($invoice['status'] ?? null) ? $invoice['status'] : '';

        return match ($type) {
            'invoice.fulfilled' => in_array($status, ['paid', 'overpaid'], true) && !empty($invoice['fulfilled_at']),
            'invoice.paid', 'invoice.overpaid' => in_array($status, ['paid', 'overpaid'], true),
            'invoice.refunded' => in_array($status, ['refunded', 'partially_refunded'], true),
            default => 'invoice.' . $status === $type,
        };
    }

    /**
     * The invoice's transfers from the API, newest first; null when it could not be asked.
     *
     * @param array<string, mixed> $row
     * @return list<array<string, mixed>>|null
     */
    private function payments(array $row): ?array
    {
        try {
            $page = $this->settings->client(1, $row['mode'])?->payments->list(['invoice' => $row['pay_invoice'], 'limit' => 100], ['max_retries' => 1]);
        } catch (\Throwable) {
            return null;
        }
        $data = is_array($page['data'] ?? null) ? $page['data'] : null;

        return $data === null ? null : array_values(array_filter($data, static fn ($p): bool => is_array($p) && empty($p['dropped_at'])));
    }

    /**
     * The transaction id FOSSBilling keeps: the first transfer that arrived for the invoice (pay_…).
     * The list is newest first, so it is the last one.
     *
     * @param list<array<string, mixed>>|null $payments
     */
    private static function paymentId(?array $payments): ?string
    {
        if ($payments === null || $payments === []) {
            return null;
        }
        $first = $payments[count($payments) - 1];

        return is_string($first['id'] ?? null) && preg_match('/^pay_[A-Za-z0-9]{1,60}$/', $first['id']) === 1 ? $first['id'] : null;
    }

    /**
     * "0.0003125 BTC on Bitcoin testnet4: https://…" for each transfer, oldest first.
     *
     * @param list<array<string, mixed>>|null $payments
     * @param array<string, mixed> $invoice
     * @return list<string>
     */
    private static function transfers(?array $payments, array $invoice): array
    {
        $lines = [];
        foreach (array_reverse($payments ?? []) as $payment) {
            $chain = is_string($payment['chain'] ?? null) ? $payment['chain'] : '';
            $txid = is_string($payment['txid'] ?? null) ? $payment['txid'] : '';
            $amount = Money::units(is_string($payment['amount'] ?? null) ? $payment['amount'] : '0', is_int($payment['decimals'] ?? null) ? $payment['decimals'] : 0);
            $asset = Chains::asset(is_string($payment['asset'] ?? null) ? $payment['asset'] : '', $chain);
            $lines[] = $amount . ' ' . $asset . ' on ' . Chains::name($chain) . ': ' . (Chains::txUrl($chain, $txid) ?? $txid);
        }
        if ($lines === [] && is_array($invoice['payment'] ?? null)) {
            $rail = $invoice['payment'];
            $chain = is_string($rail['chain'] ?? null) ? $rail['chain'] : '';
            $decimals = is_int($rail['decimals'] ?? null) ? $rail['decimals'] : 0;
            $lines[] = Money::units(is_string($invoice['amount_received'] ?? null) ? $invoice['amount_received'] : '0', $decimals) . ' '
                . Chains::asset(is_string($rail['asset'] ?? null) ? $rail['asset'] : '', $chain) . ' on ' . Chains::name($chain);
        }

        return $lines;
    }

    /** @param array<string, mixed> $invoice */
    private static function usd(array $invoice, string $field): string
    {
        return '$' . Money::fromMinor(is_int($invoice[$field] ?? null) ? $invoice[$field] : 0);
    }
}
