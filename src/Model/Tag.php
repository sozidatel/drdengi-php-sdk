<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Model;

use Soz\Drebedengi\Support\DrebedengiNormalizer;

final readonly class Tag implements \JsonSerializable
{
    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public string $id,
        public string $name,
        public ?string $parentId,
        public ?string $familyId,
        public bool $hidden,
        public bool $family,
        public ?string $sort,
        public array $raw,
    ) {
    }

    /**
     * @param array<string, mixed> $raw
     */
    public static function fromSoap(array $raw): self
    {
        return new self(
            id: DrebedengiNormalizer::requiredString($raw, 'id', 'tag'),
            name: DrebedengiNormalizer::requiredString($raw, 'name', 'tag'),
            parentId: DrebedengiNormalizer::nullableId($raw['parent_id'] ?? null),
            familyId: DrebedengiNormalizer::nullableId($raw['family_id'] ?? null),
            hidden: DrebedengiNormalizer::bool($raw['is_hidden'] ?? false),
            family: DrebedengiNormalizer::bool($raw['is_family'] ?? false),
            sort: DrebedengiNormalizer::nullableId($raw['sort'] ?? null),
            raw: $raw,
        );
    }

    public function jsonSerialize(): array
    {
        return get_object_vars($this);
    }
}
