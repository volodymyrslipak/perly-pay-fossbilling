<?php

declare(strict_types=1);

namespace PerlyPay\FOSSBilling\Vendor\Perly\Pay\Resource\Concerns;

if (!defined('PATH_LIBRARY')) {
    die('This file cannot be accessed directly');
}

/** @mixin \PerlyPay\FOSSBilling\Vendor\Perly\Pay\Resource\AbstractResource */
trait Lists
{
    /**
     * One page, newest first: `['object' => 'list', 'data' => [...], 'has_more' => bool, 'next_cursor' => ?string]`.
     *
     * @param array<string, mixed> $params filters, `limit` (1–100), `cursor`
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public function list(array $params = [], array $options = []): array
    {
        return $this->requestor->request('GET', $this->path, null, $params, $options);
    }

    /**
     * Every object, page after page: `foreach ($perly->invoices->listAll(['limit' => 100]) as $invoice)`.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $options
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function listAll(array $params = [], array $options = []): \Generator
    {
        while (true) {
            $page = $this->list($params, $options);
            $data = isset($page['data']) && is_array($page['data']) ? $page['data'] : [];
            foreach ($data as $item) {
                yield $item;
            }
            $cursor = $page['next_cursor'] ?? null;
            if (empty($page['has_more']) || !is_string($cursor) || $cursor === '') {
                return;
            }
            $params['cursor'] = $cursor;
        }
    }
}
