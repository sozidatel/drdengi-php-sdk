<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Exception;

class TransportException extends \RuntimeException implements DrebedengiException
{
    /**
     * retrySafe is true only when repeating the same call cannot duplicate a
     * mutation. It does not imply that another attempt is likely to succeed.
     */
    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
        public readonly ?string $method = null,
        public readonly ?string $endpoint = null,
        public readonly bool $retrySafe = false,
        public readonly ?string $faultCode = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
