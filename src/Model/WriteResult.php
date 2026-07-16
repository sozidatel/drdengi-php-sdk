<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Model;

use Soz\Drebedengi\Exception\InvalidArgumentException;
use Soz\Drebedengi\Exception\UnexpectedResponseException;

/**
 * Typed view of a Drebedengi SOAP write response.
 *
 * Iteration and array access intentionally expose the original response rows,
 * so most pre-0.6 code that iterated over the returned list keeps working.
 *
 * @implements \ArrayAccess<int, array<string, mixed>>
 * @implements \IteratorAggregate<int, array<string, mixed>>
 */
final readonly class WriteResult implements \ArrayAccess, \Countable, \IteratorAggregate, \JsonSerializable
{
    /** @var list<string> */
    public array $serverIds;

    /** @var list<int> */
    public array $clientIds;

    /** @var array<int, string> */
    private array $serverIdsByClientId;

    /**
     * @param list<array<string, mixed>> $raw
     * @param list<array<string, mixed>> $payloads
     */
    public function __construct(
        public array $raw,
        array $payloads = [],
    ) {
        $serverIds = [];
        $clientIds = [];
        $serverIdsByClientId = [];

        foreach ($raw as $response) {
            $serverId = self::idFrom($response, ['server_id', 'id'], 'write response');
            $clientId = self::clientIdFrom($response, 'write response');
            if ($serverId !== null && !in_array($serverId, $serverIds, true)) {
                $serverIds[] = $serverId;
            }
            if ($clientId !== null && !in_array($clientId, $clientIds, true)) {
                $clientIds[] = $clientId;
            }
            if ($serverId !== null && $clientId !== null) {
                $serverIdsByClientId[$clientId] = $serverId;
            }
        }

        foreach ($payloads as $payload) {
            $serverId = self::idFrom($payload, ['server_id'], 'write payload');
            $clientId = self::clientIdFrom($payload, 'write payload');
            if ($serverId !== null && !in_array($serverId, $serverIds, true)) {
                $serverIds[] = $serverId;
            }
            if ($clientId !== null && !in_array($clientId, $clientIds, true)) {
                $clientIds[] = $clientId;
            }
            if ($serverId !== null && $clientId !== null && !isset($serverIdsByClientId[$clientId])) {
                $serverIdsByClientId[$clientId] = $serverId;
            }
        }

        $this->serverIds = $serverIds;
        $this->clientIds = $clientIds;
        $this->serverIdsByClientId = $serverIdsByClientId;
    }

    public static function empty(): self
    {
        return new self([]);
    }

    public function firstServerId(): ?string
    {
        return $this->serverIds[0] ?? null;
    }

    public function serverIdForClientId(int|string $clientId): ?string
    {
        $value = trim((string)$clientId);
        $clientId = filter_var($value, FILTER_VALIDATE_INT);
        if ($clientId === false || $clientId <= 0 || !preg_match('/^\d+$/', $value)) {
            throw new InvalidArgumentException('Write result client ID must be a positive integer ID.');
        }

        return $this->serverIdsByClientId[$clientId] ?? null;
    }

    public function count(): int
    {
        return count($this->raw);
    }

    /**
     * @return \ArrayIterator<int, array<string, mixed>>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->raw);
    }

    public function offsetExists(mixed $offset): bool
    {
        return array_key_exists($offset, $this->raw);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function offsetGet(mixed $offset): ?array
    {
        return $this->raw[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): never
    {
        throw new \LogicException('WriteResult is immutable.');
    }

    public function offsetUnset(mixed $offset): never
    {
        throw new \LogicException('WriteResult is immutable.');
    }

    /**
     * @return array{
     *     serverIds: list<string>,
     *     clientIds: list<int>,
     *     serverIdsByClientId: array<int, string>,
     *     raw: list<array<string, mixed>>
     * }
     */
    public function jsonSerialize(): array
    {
        return [
            'serverIds' => $this->serverIds,
            'clientIds' => $this->clientIds,
            'serverIdsByClientId' => $this->serverIdsByClientId,
            'raw' => $this->raw,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param non-empty-list<string> $fields
     */
    private static function idFrom(array $row, array $fields, string $context): ?string
    {
        foreach ($fields as $field) {
            if (!array_key_exists($field, $row)) {
                continue;
            }

            $value = $row[$field];
            if (!is_int($value) && !is_string($value)) {
                throw new UnexpectedResponseException(sprintf(
                    'Drebedengi %s field "%s" must be a positive integer ID.',
                    $context,
                    $field,
                ));
            }

            $value = trim((string)$value);
            if (!preg_match('/^\d+$/', $value) || ltrim($value, '0') === '') {
                throw new UnexpectedResponseException(sprintf(
                    'Drebedengi %s field "%s" must be a positive integer ID.',
                    $context,
                    $field,
                ));
            }

            return $value;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function clientIdFrom(array $row, string $context): ?int
    {
        if (!array_key_exists('client_id', $row)) {
            return null;
        }

        return self::normalizeClientId($row['client_id'], $context);
    }

    private static function normalizeClientId(mixed $value, string $context): int
    {
        if (!is_int($value) && !is_string($value)) {
            throw new UnexpectedResponseException(sprintf(
                'Drebedengi %s field "client_id" must be a positive integer ID.',
                $context,
            ));
        }

        $value = trim((string)$value);
        $clientId = filter_var($value, FILTER_VALIDATE_INT);
        if ($clientId === false || $clientId <= 0 || !preg_match('/^\d+$/', $value)) {
            throw new UnexpectedResponseException(sprintf(
                'Drebedengi %s field "client_id" must be a positive integer ID.',
                $context,
            ));
        }

        return $clientId;
    }
}
