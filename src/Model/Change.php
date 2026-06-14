<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Model;

use Soz\Drebedengi\Support\DrebedengiNormalizer;
use Soz\Drebedengi\Support\DrebedengiDateTime;

final readonly class Change implements \JsonSerializable
{
    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public int $revision,
        public ChangeAction $action,
        public ChangedObjectType $objectType,
        public string $objectId,
        public ?\DateTimeImmutable $date,
        public array $raw,
    ) {
    }

    /**
     * @param array<string, mixed> $raw
     */
    public static function fromSoap(array $raw, ?\DateTimeZone $timezone = null): self
    {
        $timezone ??= new \DateTimeZone(date_default_timezone_get());
        $date = null;
        if (!empty($raw['date'])) {
            try {
                $date = DrebedengiDateTime::parseDateTime((string)$raw['date'], $timezone);
            } catch (\Exception) {
                $date = null;
            }
        }

        return new self(
            revision: (int)($raw['revision'] ?? 0),
            action: ChangeAction::from((int)($raw['action_id'] ?? 0)),
            objectType: ChangedObjectType::from((int)($raw['object_type_id'] ?? 0)),
            objectId: DrebedengiNormalizer::string($raw['object_id'] ?? ''),
            date: $date,
            raw: $raw,
        );
    }

    public function jsonSerialize(): array
    {
        return get_object_vars($this);
    }
}
