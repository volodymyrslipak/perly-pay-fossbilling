<?php

declare(strict_types=1);

namespace PerlyPay\FOSSBilling\Vendor\Perly\Pay\Resource;

if (!defined('PATH_LIBRARY')) {
    die('This file cannot be accessed directly');
}

use PerlyPay\FOSSBilling\Vendor\Perly\Pay\Resource\Concerns\Lists;
use PerlyPay\FOSSBilling\Vendor\Perly\Pay\Resource\Concerns\Retrieves;

/** `/license_keys`: keys issued with a product; check them from your app with LicenseClient. */
final class LicenseKeys extends AbstractResource
{
    use Lists, Retrieves;

    /**
     * @param array<string, mixed> $params `key`, `product`, and `instance` or `instance_name`
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public function validate(array $params, array $options = []): array
    {
        return $this->requestor->request('POST', $this->path . '/validate', $params, [], $options);
    }

    /**
     * @param array<string, mixed> $params `key`, `product`, `instance_name`
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public function activate(array $params, array $options = []): array
    {
        return $this->requestor->request('POST', $this->path . '/activate', $params, [], $options);
    }

    /**
     * @param array<string, mixed> $params `key`, `product`, and `instance` or `instance_name`
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public function deactivate(array $params, array $options = []): array
    {
        return $this->requestor->request('POST', $this->path . '/deactivate', $params, [], $options);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public function revoke(string $id, array $options = []): array
    {
        return $this->action($id, 'revoke', [], $options);
    }
}
