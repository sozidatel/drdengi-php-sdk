<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Model;

use Soz\Drebedengi\Support\DrebedengiNormalizer;

final readonly class Place implements \JsonSerializable
{
    public const SYSTEM_PARENT_HIDDEN_AMOUNTS = '-3';

    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public string $id,
        public string $name,
        public PlaceType $type,
        public ?string $parentId,
        public ?string $systemParentId,
        public ?string $familyId,
        public bool $hidden,
        public bool $forDuty,
        public ?string $description,
        public ?string $iconId,
        public ?string $sort,
        public ?string $purseOfUserId,
        public bool $autoHide,
        public bool $creditCard,
        public array $raw,
    ) {
    }

    /**
     * @param array<string, mixed> $raw
     */
    public static function fromSoap(array $raw): self
    {
        return new self(
            id: DrebedengiNormalizer::requiredString($raw, 'id', 'place'),
            name: DrebedengiNormalizer::requiredString($raw, 'name', 'place'),
            type: PlaceType::fromSoap($raw['type'] ?? null),
            parentId: self::normalParentId($raw['parent_id'] ?? null),
            systemParentId: self::systemParentId($raw['parent_id'] ?? null),
            familyId: DrebedengiNormalizer::nullableId($raw['budget_family_id'] ?? $raw['family_id'] ?? null),
            hidden: DrebedengiNormalizer::bool($raw['is_hidden'] ?? false),
            forDuty: DrebedengiNormalizer::bool($raw['is_for_duty'] ?? false),
            description: array_key_exists('description', $raw) ? (string)$raw['description'] : null,
            iconId: DrebedengiNormalizer::nullableId($raw['icon_id'] ?? null),
            sort: DrebedengiNormalizer::nullableId($raw['sort'] ?? null),
            purseOfUserId: DrebedengiNormalizer::nullableId($raw['purse_of_nuid'] ?? null),
            autoHide: DrebedengiNormalizer::bool($raw['is_autohide'] ?? false),
            creditCard: DrebedengiNormalizer::bool($raw['is_credit_card'] ?? false),
            raw: $raw,
        );
    }

    public function isAccount(): bool
    {
        return $this->type === PlaceType::Account;
    }

    public function isFolder(): bool
    {
        return $this->type === PlaceType::Folder;
    }

    public function canHaveTransactions(): bool
    {
        return $this->isAccount();
    }

    public function isExcludedFromTotal(): bool
    {
        return $this->systemParentId === self::SYSTEM_PARENT_HIDDEN_AMOUNTS;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return get_object_vars($this);
    }

    private static function normalParentId(mixed $value): ?string
    {
        $value = trim((string)$value);
        if ($value === '' || $value[0] === '-') {
            return null;
        }

        return $value;
    }

    private static function systemParentId(mixed $value): ?string
    {
        $value = trim((string)$value);
        if ($value === '' || $value === '-1' || $value[0] !== '-') {
            return null;
        }

        return $value;
    }
}
