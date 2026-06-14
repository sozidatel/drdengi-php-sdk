<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Transport;

interface TransportInterface
{
    /**
     * @param list<mixed> $arguments Arguments after apiId, login and password.
     */
    public function call(string $method, array $arguments = []): mixed;
}
