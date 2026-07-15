<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Model;

use Soz\Drebedengi\Support\DrebedengiNormalizer;

final readonly class Category implements \JsonSerializable
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
            id: DrebedengiNormalizer::requiredString($raw, 'id', 'category'),
            name: DrebedengiNormalizer::requiredString($raw, 'name', 'category'),
            parentId: DrebedengiNormalizer::nullableId($raw['parent_id'] ?? null),
            familyId: DrebedengiNormalizer::nullableId($raw['budget_family_id'] ?? $raw['family_id'] ?? null),
            hidden: DrebedengiNormalizer::bool($raw['is_hidden'] ?? false),
            sort: DrebedengiNormalizer::nullableId($raw['sort'] ?? null),
            raw: $raw,
        );
    }

    /**
     * @return array{
     *     id: string,
     *     name: string,
     *     parentId: string|null,
     *     familyId: string|null,
     *     hidden: bool,
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
            'hidden' => $this->hidden,
            'sort' => $this->sort,
            'raw' => $this->raw,
        ];
    }
}
