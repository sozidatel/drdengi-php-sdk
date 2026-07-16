<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Exception;

/**
 * The request may have reached Drebedengi, so repeating it can duplicate data.
 */
final class AmbiguousMutationException extends EndpointUnavailableException
{
    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
        ?string $method = null,
        ?string $endpoint = null,
        ?string $faultCode = null,
    ) {
        parent::__construct(
            message: $message,
            code: $code,
            previous: $previous,
            method: $method,
            endpoint: $endpoint,
            retrySafe: false,
            faultCode: $faultCode,
        );
    }
}
