<?php

declare(strict_types=1);

namespace PerlyPay\FOSSBilling\Vendor\Perly\Pay\Resource;

if (!defined('PATH_LIBRARY')) {
    die('This file cannot be accessed directly');
}

/**
 * `/credits`: credits, one line each — the buyer tops up themselves; you spend.
 *
 *   $left = $perly->credits->balance('user_42')['balance'];
 *   $perly->credits->consume('user_42', 3, ['action' => 'chat', 'idempotency_key' => $requestId]);
 *   $url = $perly->credits->topUpUrl('user_42');
 *
 * The customer is a `cus_…` id, or any other string as your own id for them
 * (`external_id`), even one with an `@` — or say which with `['id' => …]`,
 * `['email' => …]` or `['external_id' => …]`.
 */
final class Credits extends AbstractResource
{
    /**
     * What the customer has left (on `product`'s packs, or all of them), with
     * `top_up_url`, their own top-up page.
     *
     * @param string|array<string, mixed> $customer
     * @param array{product?: string}     $params
     * @param array<string, mixed>        $options
     *
     * @return array<string, mixed>
     */
    public function balance(string|array $customer, array $params = [], array $options = []): array
    {
        return $this->requestor->request('GET', $this->path . '/balance', null, $params + self::customer($customer), $options);
    }

    /**
     * Spends `credits`, never below zero: an `InvalidRequestException` with
     * code `insufficient_credits` when fewer are left. Give `idempotency_key`
     * (your request's id) and a retry never spends twice.
     *
     * Also takes the API's own body: `consume(['customer_external_id' => 'user_42', 'credits' => 1])`.
     *
     * @param string|array<string, mixed>                                           $customer
     * @param array{action?: string, product?: string, idempotency_key?: string}   $params
     * @param array<string, mixed>                                                  $options
     *
     * @return array<string, mixed>
     */
    public function consume(string|array $customer, ?int $credits = null, array $params = [], array $options = []): array
    {
        if (is_array($customer) && self::isApiParams($customer)) {
            return $this->requestor->request('POST', $this->path . '/consume', $customer, [], $params + $options);
        }
        if ($credits === null) {
            throw new \InvalidArgumentException('credits->consume($customer, $credits): give how many credits.');
        }
        if (isset($params['idempotency_key'])) {
            $options['idempotency_key'] = $params['idempotency_key'];
            unset($params['idempotency_key']);
        }
        $body = self::customer($customer) + ['credits' => $credits] + $params;

        return $this->requestor->request('POST', $this->path . '/consume', $body, [], $options);
    }

    /**
     * The buyer's own top-up page — stable for the customer; on one product when named.
     *
     * @param string|array<string, mixed> $customer
     * @param array{product?: string}     $params
     * @param array<string, mixed>        $options
     */
    public function topUpUrl(string|array $customer, array $params = [], array $options = []): string
    {
        return (string) $this->balance($customer, $params, $options)['top_up_url'];
    }

    /**
     * Spends, newest first: by `customer`, `product` or `action`.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public function usage(array $params = [], array $options = []): array
    {
        return $this->requestor->request('GET', $this->path . '/usage', null, $params, $options);
    }

    /**
     * @param string|array<string, mixed> $customer
     *
     * @return array<string, string>
     */
    public static function customer(string|array $customer): array
    {
        if (is_array($customer)) {
            if (self::isApiParams($customer)) {
                return array_filter(
                    array_intersect_key($customer, ['customer' => 1, 'customer_email' => 1, 'customer_external_id' => 1]),
                    'is_string',
                );
            }
            if (isset($customer['id']) && is_string($customer['id'])) {
                return ['customer' => $customer['id']];
            }
            if (isset($customer['email']) && is_string($customer['email'])) {
                return ['customer_email' => $customer['email']];
            }
            if (isset($customer['external_id']) && is_string($customer['external_id'])) {
                return ['customer_external_id' => $customer['external_id']];
            }
            throw new \InvalidArgumentException("Name the customer: ['id' => …], ['email' => …] or ['external_id' => …].");
        }
        if (preg_match('/^cus_[0-9a-f]{32}$/i', $customer) === 1
            || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $customer) === 1) {
            return ['customer' => $customer];
        }

        return ['customer_external_id' => $customer];
    }

    /**
     * @param array<string, mixed> $value
     */
    private static function isApiParams(array $value): bool
    {
        return array_key_exists('customer', $value) || array_key_exists('customer_email', $value) || array_key_exists('customer_external_id', $value);
    }
}
