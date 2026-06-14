<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Service;

use Soz\Drebedengi\Transport\TransportInterface;

final readonly class RawService
{
    public function __construct(private TransportInterface $transport)
    {
    }

    /**
     * Calls a Drebedengi SOAP method with credentials automatically prepended.
     *
     * @param list<mixed> $arguments
     */
    public function call(string $method, array $arguments = []): mixed
    {
        return $this->transport->call($method, $arguments);
    }
}
