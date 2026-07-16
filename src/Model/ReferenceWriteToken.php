<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Model;

use Soz\Drebedengi\Exception\InvalidArgumentException;

/**
 * Reusable client_id for an immediate retry of the exact same reference write.
 *
 * Drebedengi keeps only the mappings created by the latest successful write
 * for the same reference type and API ID. The caller must therefore reuse the
 * token before another write of that type, with exactly the same payload.
 */
final readonly class ReferenceWriteToken implements \JsonSerializable
{
    private const MAX_CLIENT_ID = 999_999_999;

    public function __construct(public int $clientId)
    {
        if ($clientId < 1 || $clientId > self::MAX_CLIENT_ID) {
            throw new InvalidArgumentException(sprintf(
                'Reference write client ID must be between 1 and %d.',
                self::MAX_CLIENT_ID,
            ));
        }
    }

    public static function generate(): self
    {
        return new self(random_int(1, self::MAX_CLIENT_ID));
    }

    public static function fromClientId(int|string $clientId): self
    {
        $value = trim((string)$clientId);
        $normalized = filter_var($value, FILTER_VALIDATE_INT);
        if (
            $normalized === false
            || $normalized < 1
            || $normalized > self::MAX_CLIENT_ID
            || !preg_match('/^\d+$/', $value)
        ) {
            throw new InvalidArgumentException(sprintf(
                'Reference write client ID must be between 1 and %d.',
                self::MAX_CLIENT_ID,
            ));
        }

        return new self($normalized);
    }

    /**
     * @return array{clientId: int}
     */
    public function jsonSerialize(): array
    {
        return ['clientId' => $this->clientId];
    }
}
