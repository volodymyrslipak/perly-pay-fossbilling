<?php

declare(strict_types=1);

namespace PerlyPay\FOSSBilling\Vendor\Perly\Pay;

if (!defined('PATH_LIBRARY')) {
    die('This file cannot be accessed directly');
}

use PerlyPay\FOSSBilling\Vendor\Perly\Pay\Exception\SignatureVerificationException;

/**
 * Webhook signatures. Every event Perly Pay POSTs carries
 *
 *   Perly-Signature: t=<unix seconds>,v1=<hex HMAC-SHA256(secret, t + "." + rawBody)>
 *
 * `verify` checks it on the RAW body (before any JSON decoding — a decoded
 * and re-encoded body does not match), in constant time, and refuses a
 * timestamp more than five minutes from now (a replayed request). The
 * header may carry several `v1=` while a secret rolls; any one matching
 * is enough. The same scheme as the Node SDK's `webhooks.verify`.
 *
 *   $event = Webhook::verify(file_get_contents('php://input'), $_SERVER['HTTP_PERLY_SIGNATURE'] ?? null, $secret);
 */
final class Webhook
{
    public const SIGNATURE_HEADER = 'Perly-Signature';
    public const DEFAULT_TOLERANCE_SECONDS = 300;

    /**
     * Checks a delivery and returns its event, decoded.
     *
     * @param string      $payload   the raw request body, exactly as it arrived
     * @param string|null $header    the Perly-Signature header
     * @param string      $secret    the endpoint's signing secret, `whsec_…`
     * @param int         $tolerance seconds a signature stays good
     * @param int|null    $now       unix seconds "now" (tests)
     *
     * @return array<string, mixed> the Event: `id`, `type`, `data.object`, …
     *
     * @throws SignatureVerificationException
     */
    public static function verify(string $payload, ?string $header, string $secret, int $tolerance = self::DEFAULT_TOLERANCE_SECONDS, ?int $now = null): array
    {
        if ($secret === '') {
            throw self::fail('no_secret', "Pass your endpoint's signing secret (whsec_…).");
        }
        if ($header === null || trim($header) === '') {
            throw self::fail('no_signature', 'No ' . self::SIGNATURE_HEADER . ' header.');
        }
        $parsed = self::parseHeader($header);
        if ($parsed === null) {
            throw self::fail('malformed_signature', self::SIGNATURE_HEADER . ' is not t=…,v1=….');
        }
        $now ??= time();
        if (abs($now - $parsed['timestamp']) > $tolerance) {
            throw self::fail('timestamp_out_of_tolerance', 'The signature is too old (or from the future).');
        }
        $expected = self::computeSignature($secret, $parsed['timestamp'], $payload);
        $matched = false;
        foreach ($parsed['signatures'] as $signature) {
            $matched = hash_equals($expected, $signature) || $matched;
        }
        if (!$matched) {
            throw self::fail('signature_mismatch', 'The signature does not match.');
        }
        try {
            $event = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw self::fail('body_not_json', 'The body is not JSON.', $error);
        }
        if (!is_array($event)) {
            throw self::fail('body_not_json', 'The body is not a JSON object.');
        }

        return $event;
    }

    /**
     * Verifies the request PHP is handling now: its raw body and its header.
     *
     * @return array<string, mixed>
     *
     * @throws SignatureVerificationException
     */
    public static function verifyCurrentRequest(string $secret, int $tolerance = self::DEFAULT_TOLERANCE_SECONDS): array
    {
        $payload = file_get_contents('php://input');
        $header = $_SERVER['HTTP_PERLY_SIGNATURE'] ?? null;

        return self::verify(is_string($payload) ? $payload : '', is_string($header) ? $header : null, $secret, $tolerance);
    }

    /** HMAC-SHA256 of `t + "." + payload` under the secret, lowercase hex. */
    public static function computeSignature(string $secret, int $timestamp, string $payload): string
    {
        return hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
    }

    /**
     * @return array{timestamp: int, signatures: list<string>}|null
     */
    public static function parseHeader(string $header): ?array
    {
        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            $pair = explode('=', trim($part), 2);
            if (count($pair) !== 2) {
                continue;
            }
            [$key, $value] = $pair;
            if ($key === 't' && preg_match('/^\d{1,12}$/', $value) === 1) {
                $timestamp = (int) $value;
            } elseif ($key === 'v1' && preg_match('/^[0-9a-fA-F]{64}$/', $value) === 1) {
                $signatures[] = strtolower($value);
            }
        }

        return $timestamp === null || $signatures === [] ? null : ['timestamp' => $timestamp, 'signatures' => $signatures];
    }

    /** For tests: the header Perly Pay would send for this body. */
    public static function signatureHeader(string $payload, string $secret, ?int $timestamp = null): string
    {
        $timestamp ??= time();

        return 't=' . $timestamp . ',v1=' . self::computeSignature($secret, $timestamp, $payload);
    }

    private static function fail(string $code, string $message, ?\Throwable $previous = null): SignatureVerificationException
    {
        return new SignatureVerificationException($message, 'signature_verification_error', $code, previous: $previous);
    }
}
