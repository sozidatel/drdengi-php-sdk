<?php

declare(strict_types=1);

namespace Soz\Drebedengi;

final readonly class ClientOptions
{
    public \DateTimeZone $timezone;

    public function __construct(?\DateTimeZone $timezone = null)
    {
        $this->timezone = $timezone ?? new \DateTimeZone(date_default_timezone_get());
    }
}
