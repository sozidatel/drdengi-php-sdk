<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Model;

use Soz\Drebedengi\Exception\InvalidArgumentException;

/**
 * Reusable client_id set for an immediate retry of the exact same payload.
 *
 * Drebedengi keeps only the mappings created by the latest successful
 * setRecordList call for the same API ID. A token therefore stops being an
 * idempotency guarantee after another successful record write or an explicit
 * initial synchronization. The caller must also reuse every other argument,
 * including dates and amounts. With failover, retry through a single-endpoint
 * client pinned to the endpoint reported by AmbiguousMutationException.
 */
final readonly class RecordWriteToken implements \JsonSerializable
{
    private const MAX_CLIENT_ID = 999_999_999;

    /** @var non-empty-list<int> */
    public array $clientIds;

    public int $groupId;

    /**
     * @param list<int|string> $clientIds
     */
    public function __construct(array $clientIds, int|string|null $groupId = null)
    {
        if ($clientIds === []) {
            throw new InvalidArgumentException('Record write token must contain at least one client ID.');
        }

        $normalized = array_map(
            static fn (int|string $clientId): int => self::normalizeId($clientId, 'Record write client ID'),
            $clientIds,
        );
        if (count(array_unique($normalized)) !== count($normalized)) {
            throw new InvalidArgumentException('Record write token client IDs must be unique.');
        }

        $this->clientIds = $normalized;
        $this->groupId = $groupId === null
            ? self::randomId(except: $this->clientIds)
            : self::normalizeId($groupId, 'Record write group ID');
    }

    public static function generate(int $recordCount = 1): self
    {
        if ($recordCount < 1) {
            throw new InvalidArgumentException('Record write token size must be at least 1.');
        }

        $clientIds = [];
        while (count($clientIds) < $recordCount) {
            $clientId = self::randomId(except: $clientIds);
            $clientIds[] = $clientId;
        }

        return new self($clientIds);
    }

    public static function fromClientId(int|string $clientId): self
    {
        return new self([$clientId]);
    }

    public static function fromClientIds(int|string ...$clientIds): self
    {
        return new self(array_values($clientIds));
    }

    public function clientId(int $index = 0): int
    {
        if (!array_key_exists($index, $this->clientIds)) {
            throw new InvalidArgumentException(sprintf(
                'Record write token does not contain client ID at index %d.',
                $index,
            ));
        }

        return $this->clientIds[$index];
    }

    public function assertRecordCount(int $expected, string $operation): void
    {
        $actual = count($this->clientIds);
        if ($actual !== $expected) {
            throw new InvalidArgumentException(sprintf(
                '%s requires a record write token with %d client ID%s; %d provided.',
                $operation,
                $expected,
                $expected === 1 ? '' : 's',
                $actual,
            ));
        }
    }

    /**
     * @return array{clientIds: non-empty-list<int>, groupId: int}
     */
    public function jsonSerialize(): array
    {
        return [
            'clientIds' => $this->clientIds,
            'groupId' => $this->groupId,
        ];
    }

    /**
     * @param list<int> $except
     */
    private static function randomId(array $except = []): int
    {
        do {
            $id = random_int(1, self::MAX_CLIENT_ID);
        } while (in_array($id, $except, true));

        return $id;
    }

    private static function normalizeId(int|string $id, string $context): int
    {
        $value = trim((string)$id);
        $normalized = filter_var($value, FILTER_VALIDATE_INT);
        if (
            $normalized === false
            || $normalized < 1
            || $normalized > self::MAX_CLIENT_ID
            || !preg_match('/^\d+$/', $value)
        ) {
            throw new InvalidArgumentException(sprintf(
                '%s must be between 1 and %d.',
                $context,
                self::MAX_CLIENT_ID,
            ));
        }

        return $normalized;
    }
}
