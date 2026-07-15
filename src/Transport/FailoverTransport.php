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
        'getRightAccess' => true,
        'getSourceList' => true,
        'getSubscriptionStatus' => true,
        'getTagList' => true,
        'getUserIdByLogin' => true,
    ];

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
        if (!$this->isReadOnlyCall($method, $arguments)) {
            $this->selectEndpoint();

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

                throw new EndpointUnavailableException(
                    sprintf(
                        'Drebedengi mutation "%s" may have reached %s; it was not retried.',
                        $method,
                        $failedEndpoint,
                    ),
                    0,
                    $exception,
                );
            }
        }

        return $this->callReadOnly($method, $arguments);
    }

    /**
     * getRecordList is read-only only in explicit report mode. Drebedengi uses
     * is_report=false for initial synchronization and clears deduplication
     * state, so an unknown or legacy argument shape must remain a mutation.
     *
     * @param list<mixed> $arguments
     */
    private function isReadOnlyCall(string $method, array $arguments): bool
    {
        if ($method !== 'getRecordList') {
            return isset(self::READ_ONLY_METHODS[$method]);
        }

        $params = $arguments[0] ?? null;

        return is_array($params) && ($params['is_report'] ?? null) === true;
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
