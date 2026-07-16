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
        public ?string $userId = null,
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
            userId: DrebedengiNormalizer::nullableId($raw['user_id'] ?? $raw['nuid'] ?? null),
        );
    }

    /**
     * @return array{
     *     id: string,
     *     name: string,
     *     parentId: string|null,
     *     familyId: string|null,
     *     userId: string|null,
     *     hidden: bool,
     *     family: bool,
     *     sort: string|null,
     *     raw: array<string, mixed>
     * }
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'parentId' => $this->parentId,
            'familyId' => $this->familyId,
            'userId' => $this->userId,
            'hidden' => $this->hidden,
            'family' => $this->family,
            'sort' => $this->sort,
            'raw' => $this->raw,
        ];
    }
}
