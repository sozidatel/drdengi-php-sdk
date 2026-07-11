<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Transport;

use Soz\Drebedengi\Exception\EndpointUnavailableException;
use Soz\Drebedengi\Exception\TransportException;

final class FailoverTransport implements TransportInterface
{
    /**
     * Methods not listed here are treated as potentially mutating.
     */
    private const READ_ONLY_METHODS = [
        'getAccessStatus' => true,
        'getBalance' => true,
        'getCategoryList' => true,
        'getChangeList' => true,
        'getCurrencyList' => true,
        'getCurrentRevision' => true,
        'getExpireDate' => true,
        'getPlaceList' => true,
        'getRecordList' => true,
        'getRightAccess' => true,
        'getSourceList' => true,
        'getSubscriptionStatus' => true,
        'getTagList' => true,
        'getUserIdByLogin' => true,
    ];

    private ?int $activeIndex = null;

    /**
     * @param non-empty-array<string, TransportInterface> $transports Map of base URI to transport.
     */
    public function __construct(private readonly array $transports)
    {
        if ($transports === []) {
            throw new \InvalidArgumentException('Failover transport requires at least one endpoint.');
        }
    }

    public function call(string $method, array $arguments = []): mixed
    {
        if (!isset(self::READ_ONLY_METHODS[$method])) {
            $this->selectEndpoint();

            // A mutation is deliberately sent only once. If its response is lost,
            // retrying against another domain could duplicate the operation.
            try {
                return $this->activeTransport()->call($method, $arguments);
            } catch (EndpointUnavailableException $exception) {
                throw new EndpointUnavailableException(
                    sprintf(
                        'Drebedengi mutation "%s" may have reached %s; it was not retried.',
                        $method,
                        $this->activeEndpoint(),
                    ),
                    0,
                    $exception,
                );
            }
        }

        return $this->callReadOnly($method, $arguments);
    }

    private function selectEndpoint(): void
    {
        if ($this->activeIndex !== null) {
            return;
        }

        $this->callReadOnly('getAccessStatus');
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

        for ($index = $startIndex, $count = count($transports); $index < $count; $index++) {
            $attempts[] = $endpoints[$index];

            try {
                $result = $transports[$index]->call($method, $arguments);
                $this->activeIndex = $index;

                return $result;
            } catch (EndpointUnavailableException $exception) {
                if ($index === $count - 1) {
                    throw new EndpointUnavailableException(
                        sprintf(
                            'Drebedengi SOAP endpoints are unavailable; attempted: %s.',
                            implode(', ', $attempts),
                        ),
                        0,
                        $exception,
                    );
                }
            } catch (TransportException $exception) {
                // A valid SOAP fault proves that the endpoint answered. It is not
                // a reason to try the same business request on another domain.
                $this->activeIndex = $index;

                throw $exception;
            }
        }

        throw new EndpointUnavailableException('No Drebedengi SOAP endpoint was attempted.');
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
