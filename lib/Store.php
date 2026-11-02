<?php

declare(strict_types=1);

namespace PerlyPay\FOSSBilling;

if (!defined('PATH_LIBRARY')) {
    die('This file cannot be accessed directly');
}

/**
 * Which Perly Pay invoice was made for which FOSSBilling invoice, and what
 * came of it. One row per Perly Pay invoice:
 *
 *   pay_invoice     inv_…, unique
 *   fb_invoice      the FOSSBilling invoice id
 *   gateway_id      the FOSSBilling gateway (pay_gateway row) that made it
 *   mode            test | live (the gateway's, when it was made)
 *   amount          what Perly Pay bills, minor units, in `currency` (USD|EUR): the invoice's total then
 *   currency        the invoice's currency
 *   url             the hosted invoice
 *   status          open | closed | paid
 *   payment         the Perly Pay payment (pay_…) recorded in FOSSBilling once paid
 *   claim, claimed_at   who is recording the payment right now (one webhook at a time)
 *   events          the last event ids applied (a replay changes nothing)
 *
 * @phpstan-type Row array{pay_invoice: string, fb_invoice: int, gateway_id: int, mode: string, amount: int, currency: string, url: string, status: string, payment: ?string, claim: ?string, claimed_at: ?int, events: list<string>}
 */
interface Store
{
    /** Seconds after which a claim that never finished may be taken over. */
    public const CLAIM_SECONDS = 600;

    /** @return array<string, mixed>|null the newest row for this FOSSBilling invoice, gateway and mode */
    public function latestFor(int $fbInvoice, int $gatewayId, string $mode): ?array;

    /** How many Perly Pay invoices were made for this FOSSBilling invoice, by any gateway, in any mode. */
    public function count(int $fbInvoice): int;

    /** @param array<string, mixed> $row */
    public function add(array $row): void;

    /** @return array<string, mixed>|null */
    public function byPayInvoice(string $payInvoice): ?array;

    public function setStatus(string $payInvoice, string $status): void;

    /**
     * Takes the row for recording its payment: true only for the one caller
     * that finds it unpaid and unclaimed (or claimed longer ago than CLAIM_SECONDS).
     */
    public function claim(string $payInvoice, string $token, int $now): bool;

    public function release(string $payInvoice, string $token): void;

    public function markPaid(string $payInvoice, string $payment): void;

    public function hasEvent(string $payInvoice, string $eventId): bool;

    public function rememberEvent(string $payInvoice, string $eventId): void;
}
