<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Transport;

use Soz\Drebedengi\Exception\AmbiguousMutationException;
use Soz\Drebedengi\Exception\EndpointUnavailableException;
use Soz\Drebedengi\Exception\TransportException;

final class FailoverTransport implements TransportInterface
{
    private ?int $activeIndex = null;

    /** @var non-empty-array<string, TransportInterface> */
    private readonly array $transports;

    /**
     * @param array<string, TransportInterface> $transports Map of base URI to transport.
     */
    public function __construct(array $transports)
    {
        if ($transports === []) {
            throw new \InvalidArgumentException('Failover transport requires at least one endpoint.');
        }

        $this->transports = $transports;
    }

    public function call(string $method, array $arguments = []): mixed
    {
        if (!CallPolicy::isReadOnly($method, $arguments)) {
            $this->selectEndpoint($method);

            // A mutation is deliberately sent only once. If its response is lost,
            // retrying against another domain could duplicate the operation.
            try {
                return $this->activeTransport()->call($method, $arguments);
            } catch (EndpointUnavailableException $exception) {
                $failedEndpoint = $this->activeEndpoint();
                // The failed mutation is never retried. Clear only the sticky
                // selection so a later, distinct mutation performs a fresh
                // read-only endpoint probe before it is sent once.
                $this->activeIndex = null;

                if ($exception->retrySafe) {
                    throw new EndpointUnavailableException(
                        message: sprintf(
                            'Drebedengi mutation "%s" was not sent to %s; it may be retried.',
                            $method,
                            $failedEndpoint,
                        ),
                        previous: $exception,
                        method: $method,
                        endpoint: $failedEndpoint,
                        retrySafe: true,
                        faultCode: $exception->faultCode,
                    );
                }

                throw new AmbiguousMutationException(
                    message: sprintf(
                        'Drebedengi mutation "%s" may have reached %s; it was not retried.',
                        $method,
                        $failedEndpoint,
                    ),
                    previous: $exception,
                    method: $method,
                    endpoint: $failedEndpoint,
                    faultCode: $exception->faultCode,
                );
            }
        }

        return $this->callReadOnly($method, $arguments);
    }

    private function selectEndpoint(string $mutationMethod): void
    {
        if ($this->activeIndex !== null) {
            return;
        }

        try {
            $this->callReadOnly('getAccessStatus');
        } catch (EndpointUnavailableException $exception) {
            throw new EndpointUnavailableException(
                message: sprintf(
                    'Cannot select a Drebedengi endpoint for mutation "%s": %s',
                    $mutationMethod,
                    $exception->getMessage(),
                ),
                previous: $exception,
                method: $mutationMethod,
                endpoint: $exception->endpoint,
                retrySafe: true,
                faultCode: $exception->faultCode,
            );
        }
    }

    /**
     * @param list<mixed> $arguments
     */
    private function callReadOnly(string $method, array $arguments = []): mixed
    {
        $attempts = [];
        $startIndex = $this->activeIndex ?? 0;
        $endpoints = array_keys($this->transports);
        $transports = array_values($this->transports);
        $count = count($transports);

        for ($offset = 0; $offset < $count; $offset++) {
            $index = ($startIndex + $offset) % $count;
            $attempts[] = $endpoints[$index];

            try {
                $result = $transports[$index]->call($method, $arguments);
                $this->activeIndex = $index;

                return $result;
            } catch (EndpointUnavailableException $exception) {
                if ($offset === $count - 1) {
                    // No endpoint is currently known to be healthy. A later
                    // mutation must run a fresh read-only probe from primary.
                    $this->activeIndex = null;

                    throw new EndpointUnavailableException(
                        message: sprintf(
                            'Drebedengi SOAP endpoints are unavailable; attempted: %s.',
                            implode(', ', $attempts),
                        ),
                        previous: $exception,
                        method: $method,
                        endpoint: $endpoints[$index],
                        retrySafe: true,
                        faultCode: $exception->faultCode,
                    );
                }
            } catch (TransportException $exception) {
                // A valid SOAP fault proves that the endpoint answered. It is not
                // a reason to try the same business request on another domain.
                $this->activeIndex = $index;

                throw $exception;
            }
        }

        throw new EndpointUnavailableException(
            message: 'No Drebedengi SOAP endpoint was attempted.',
            method: $method,
            retrySafe: true,
        );
    }

    private function activeTransport(): TransportInterface
    {
        return array_values($this->transports)[$this->activeIndex ?? 0];
    }

    private function activeEndpoint(): string
    {
        return array_keys($this->transports)[$this->activeIndex ?? 0];
    }
}
