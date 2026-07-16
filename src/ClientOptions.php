<?php

declare(strict_types=1);

namespace Soz\Drebedengi;

use Soz\Drebedengi\Exception\InvalidArgumentException;

final readonly class ClientOptions
{
    public const DEFAULT_CONNECT_TIMEOUT = 10;
    public const DEFAULT_READ_TIMEOUT = 30.0;

    public \DateTimeZone $timezone;

    public function __construct(
        ?\DateTimeZone $timezone = null,
        public ?int $connectTimeout = self::DEFAULT_CONNECT_TIMEOUT,
        public ?float $readTimeout = self::DEFAULT_READ_TIMEOUT,
        public WsdlCache $wsdlCache = WsdlCache::Memory,
    ) {
        if ($connectTimeout !== null && $connectTimeout <= 0) {
            throw new InvalidArgumentException('SOAP connect timeout must be greater than zero seconds.');
        }
        if ($readTimeout !== null && (!is_finite($readTimeout) || $readTimeout <= 0)) {
            throw new InvalidArgumentException('SOAP read timeout must be a finite value greater than zero seconds.');
        }

        $this->timezone = $timezone ?? new \DateTimeZone(date_default_timezone_get());
    }
}
