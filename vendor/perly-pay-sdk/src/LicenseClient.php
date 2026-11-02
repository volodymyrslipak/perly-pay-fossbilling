<?php

declare(strict_types=1);

namespace PerlyPay\FOSSBilling\Vendor\Perly\Pay;

if (!defined('PATH_LIBRARY')) {
    die('This file cannot be accessed directly');
}

use PerlyPay\FOSSBilling\Vendor\Perly\Pay\Exception\InvalidRequestException;
use PerlyPay\FOSSBilling\Vendor\Perly\Pay\Http\CurlTransport;
use PerlyPay\FOSSBilling\Vendor\Perly\Pay\Http\Requestor;
use PerlyPay\FOSSBilling\Vendor\Perly\Pay\Http\Transport;

/**
 * License checks from software you ship (a plugin, a desktop app): no
 * secret key, only the buyer's license key and your `product` (required:
 * without it a key from another account could answer, so the API refuses
 * the call, and so does this, before sending it). Never ship a secret key.
 *
 *   $result = (new LicenseClient())->activate(['key' => $key, 'product' => 'prod_…', 'instance_name' => gethostname()]);
 *   if (!$result['valid']) { … $result['reason'] … }
 */
final class LicenseClient
{
    private readonly Requestor $requestor;

    /**
     * @param array{base_url?: string, max_retries?: int, timeout?: float|int, transport?: Transport, sleep?: callable(int): void} $options
     */
    public function __construct(array $options = [])
    {
        $this->requestor = new Requestor(
            '',
            rtrim($options['base_url'] ?? PerlyPay::DEFAULT_BASE_URL, '/') . '/api/v1',
            $options['transport'] ?? new CurlTransport(),
            $options['max_retries'] ?? 2,
            (float) ($options['timeout'] ?? 15),
            $options['sleep'] ?? null,
        );
    }

    /**
     * @param array<string, mixed> $params `key`, `product`, and `instance` or `instance_name`
     *
     * @return array<string, mixed>
     */
    public function validate(array $params): array
    {
        return $this->check('/license_keys/validate', $params);
    }

    /**
     * @param array<string, mixed> $params `key`, `product`, `instance_name`
     *
     * @return array<string, mixed>
     */
    public function activate(array $params): array
    {
        return $this->check('/license_keys/activate', $params);
    }

    /**
     * @param array<string, mixed> $params `key`, `product`, and `instance` or `instance_name`
     *
     * @return array<string, mixed>
     */
    public function deactivate(array $params): array
    {
        return $this->check('/license_keys/deactivate', $params);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function check(string $path, array $params): array
    {
        if (!isset($params['product']) || !is_string($params['product']) || $params['product'] === '') {
            throw new InvalidRequestException(
                "Pass `product` (your product's id, `prod_…`) with the license key: without it a key from another account could answer.",
                'invalid_request_error',
                'parameter_missing',
                'product',
            );
        }

        return $this->requestor->request('POST', $path, $params);
    }
}
