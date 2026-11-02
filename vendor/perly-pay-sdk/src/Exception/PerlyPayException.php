<?php

declare(strict_types=1);

namespace PerlyPay\FOSSBilling\Vendor\Perly\Pay\Exception;

if (!defined('PATH_LIBRARY')) {
    die('This file cannot be accessed directly');
}

/**
 * Every failure the SDK raises: the API's own `{ type, code, message,
 * param }`, plus the HTTP status and the request id to quote to support.
 * Catch this one, or a subclass for one kind:
 *
 *   InvalidRequestException   a field is wrong (`getParam()` names it)
 *   AuthenticationException   the key is missing, wrong or revoked
 *   PermissionException       the key may not do this
 *   RateLimitException        too many requests (already retried)
 *   IdempotencyException      the same Idempotency-Key with a different body
 *   ApiException              anything else the API answered
 *   ConnectionException       no answer at all
 *   SignatureVerificationException  a webhook that does not check out
 */
class PerlyPayException extends \RuntimeException
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        string $message,
        private readonly string $type = 'api_error',
        private readonly string $errorCode = 'api_error',
        private readonly ?string $param = null,
        private readonly ?int $httpStatus = null,
        private readonly ?string $requestId = null,
        private readonly array $headers = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * The exception for an error the API answered, by its `type`.
     *
     * @param array<string, string> $headers
     */
    public static function fromApi(
        string $type,
        string $errorCode,
        string $message,
        ?string $param = null,
        ?int $httpStatus = null,
        ?string $requestId = null,
        array $headers = [],
    ): self {
        $class = match ($type) {
            'invalid_request_error' => InvalidRequestException::class,
            'authentication_error' => AuthenticationException::class,
            'permission_error' => PermissionException::class,
            'rate_limit_error' => RateLimitException::class,
            'idempotency_error' => IdempotencyException::class,
            default => ApiException::class,
        };

        return new $class($message, $type, $errorCode, $param, $httpStatus, $requestId, $headers);
    }

    /** `invalid_request_error`, `authentication_error`, … */
    public function getType(): string
    {
        return $this->type;
    }

    /** The API's snake_case code: `parameter_invalid`, `resource_missing`, `sanctioned_sender`, … */
    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    /** The field the error is about, when there is one. */
    public function getParam(): ?string
    {
        return $this->param;
    }

    public function getHttpStatus(): ?int
    {
        return $this->httpStatus;
    }

    /** The `Perly-Request-Id` of the answer: quote it to support. */
    public function getRequestId(): ?string
    {
        return $this->requestId;
    }

    /** @return array<string, string> the answer's headers, names in lower case */
    public function getHeaders(): array
    {
        return $this->headers;
    }
}
