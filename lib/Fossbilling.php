<?php

declare(strict_types=1);

namespace PerlyPay\FOSSBilling;

if (!defined('PATH_LIBRARY')) {
    die('This file cannot be accessed directly');
}

/**
 * What the adapter asks of FOSSBilling, behind one interface so its rules
 * can be tested without FOSSBilling. Services is the real one.
 */
interface Fossbilling
{
    /**
     * An invoice, its total with tax as "24.50", or null. The hash is the random part of its link.
     *
     * @return array{id: int, number: string, hash: string, client_id: int, status: string, currency: string, total: string, email: string}|null
     */
    public function invoice(int $id): ?array;

    /**
     * A row of FOSSBilling's transaction table (each webhook delivery is one), or null.
     * received_at: when FOSSBilling stored it, unix seconds.
     *
     * @return array{id: int, invoice_id: ?int, status: string, received_at: int}|null
     */
    public function transaction(int $id): ?array;

    /** FOSSBilling's own claim on a transaction (received or error → processing): true for one caller only. */
    public function claimTransaction(int $id): bool;

    /** @param array<string, string|int|float|null> $fields columns of the transaction row (txn_id, txn_status, invoice_id, amount, currency, type, note, status, error) */
    public function updateTransaction(int $id, array $fields): void;

    /** Removes a transaction that recorded nothing worth keeping (a duplicate, another shop's event). */
    public function dropTransaction(int $id): void;

    /** Removes this gateway's failed deliveries of an event (txn_id) once a later one succeeded. */
    public function dropFailed(int $gatewayId, string $txnId, int $except): void;

    /**
     * Adds the amount to the client's balance and pays the invoice from it, as FOSSBilling's own
     * Stripe adapter does (a deposit invoice is marked paid). True when the invoice is paid now.
     */
    public function recordPayment(int $invoiceId, int $transactionId, string $amount, string $description): bool;

    /** A line in the invoice's notes, which admins read on the invoice. */
    public function note(int $invoiceId, string $text): void;

    /** A line in FOSSBilling's activity log. */
    public function log(string $message): void;
}
