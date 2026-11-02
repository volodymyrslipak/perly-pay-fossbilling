<?php

declare(strict_types=1);

namespace PerlyPay\FOSSBilling;

if (!defined('PATH_LIBRARY')) {
    die('This file cannot be accessed directly');
}

/**
 * The Store in FOSSBilling's own database, through the Doctrine DBAL
 * connection FOSSBilling gives every service ($di['dbal']): the table
 * perlypay_invoice, made the first time it is needed. Plain SQL that
 * MySQL and MariaDB (the databases FOSSBilling runs on) both take.
 */
final class DbalStore implements Store
{
    public const TABLE = 'perlypay_invoice';

    private static bool $ready = false;

    /** @param object $db Doctrine\DBAL\Connection: executeStatement() and fetchAssociative() */
    public function __construct(private readonly object $db)
    {
        if (!self::$ready) {
            $this->db->executeStatement(
                'CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    pay_invoice VARCHAR(64) NOT NULL,
                    fb_invoice INT UNSIGNED NOT NULL,
                    gateway_id INT UNSIGNED NOT NULL,
                    mode VARCHAR(4) NOT NULL,
                    amount BIGINT UNSIGNED NOT NULL,
                    currency CHAR(3) NOT NULL,
                    url TEXT NOT NULL,
                    status VARCHAR(10) NOT NULL,
                    payment VARCHAR(64) NULL,
                    claim VARCHAR(40) NULL,
                    claimed_at INT UNSIGNED NULL,
                    events TEXT NULL,
                    created_at INT UNSIGNED NOT NULL,
                    updated_at INT UNSIGNED NOT NULL,
                    UNIQUE KEY perlypay_invoice_pay_invoice (pay_invoice),
                    KEY perlypay_invoice_fb_invoice (fb_invoice)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
            );
            self::$ready = true;
        }
    }

    public function latestFor(int $fbInvoice, int $gatewayId, string $mode): ?array
    {
        return self::row($this->db->fetchAssociative(
            'SELECT * FROM ' . self::TABLE . ' WHERE fb_invoice = ? AND gateway_id = ? AND mode = ? ORDER BY id DESC LIMIT 1',
            [$fbInvoice, $gatewayId, $mode]
        ));
    }

    public function count(int $fbInvoice): int
    {
        $found = $this->db->fetchAssociative('SELECT COUNT(*) AS n FROM ' . self::TABLE . ' WHERE fb_invoice = ?', [$fbInvoice]);

        return is_array($found) ? (int) $found['n'] : 0;
    }

    public function add(array $row): void
    {
        $now = time();
        $this->db->executeStatement(
            'INSERT INTO ' . self::TABLE . ' (pay_invoice, fb_invoice, gateway_id, mode, amount, currency, url, status, events, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [(string) $row['pay_invoice'], (int) $row['fb_invoice'], (int) $row['gateway_id'], (string) $row['mode'], (int) $row['amount'], (string) $row['currency'], (string) $row['url'], 'open', '[]', $now, $now]
        );
    }

    public function byPayInvoice(string $payInvoice): ?array
    {
        return self::row($this->db->fetchAssociative('SELECT * FROM ' . self::TABLE . ' WHERE pay_invoice = ?', [$payInvoice]));
    }

    public function setStatus(string $payInvoice, string $status): void
    {
        $this->db->executeStatement('UPDATE ' . self::TABLE . " SET status = ?, updated_at = ? WHERE pay_invoice = ? AND status <> 'paid'", [$status, time(), $payInvoice]);
    }

    public function claim(string $payInvoice, string $token, int $now): bool
    {
        $taken = $this->db->executeStatement(
            'UPDATE ' . self::TABLE . ' SET claim = ?, claimed_at = ?, updated_at = ?
             WHERE pay_invoice = ? AND payment IS NULL AND (claim IS NULL OR claimed_at < ?)',
            [$token, $now, $now, $payInvoice, $now - self::CLAIM_SECONDS]
        );

        return (int) $taken === 1;
    }

    public function release(string $payInvoice, string $token): void
    {
        $this->db->executeStatement('UPDATE ' . self::TABLE . ' SET claim = NULL, claimed_at = NULL, updated_at = ? WHERE pay_invoice = ? AND claim = ?', [time(), $payInvoice, $token]);
    }

    public function markPaid(string $payInvoice, string $payment): void
    {
        $this->db->executeStatement(
            'UPDATE ' . self::TABLE . " SET status = 'paid', payment = ?, claim = NULL, claimed_at = NULL, updated_at = ? WHERE pay_invoice = ?",
            [$payment, time(), $payInvoice]
        );
    }

    public function hasEvent(string $payInvoice, string $eventId): bool
    {
        $row = $this->byPayInvoice($payInvoice);

        return $row !== null && in_array($eventId, $row['events'], true);
    }

    public function rememberEvent(string $payInvoice, string $eventId): void
    {
        $row = $this->byPayInvoice($payInvoice);
        if ($row === null || in_array($eventId, $row['events'], true)) {
            return;
        }
        $events = array_slice([...$row['events'], $eventId], -30);
        $this->db->executeStatement('UPDATE ' . self::TABLE . ' SET events = ?, updated_at = ? WHERE pay_invoice = ?', [json_encode($events), time(), $payInvoice]);
    }

    /** @return array<string, mixed>|null */
    private static function row(mixed $found): ?array
    {
        if (!is_array($found)) {
            return null;
        }
        $events = is_string($found['events'] ?? null) ? json_decode($found['events'], true) : null;

        return [
            'pay_invoice' => (string) $found['pay_invoice'],
            'fb_invoice' => (int) $found['fb_invoice'],
            'gateway_id' => (int) $found['gateway_id'],
            'mode' => (string) $found['mode'],
            'amount' => (int) $found['amount'],
            'currency' => (string) $found['currency'],
            'url' => (string) $found['url'],
            'status' => (string) $found['status'],
            'payment' => isset($found['payment']) ? (string) $found['payment'] : null,
            'claim' => isset($found['claim']) ? (string) $found['claim'] : null,
            'claimed_at' => isset($found['claimed_at']) ? (int) $found['claimed_at'] : null,
            'events' => is_array($events) ? array_values(array_filter($events, 'is_string')) : [],
        ];
    }
}
