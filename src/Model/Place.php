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
            description: DrebedengiNormalizer::nullableText($raw['description'] ?? null),
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
     * @return array{
     *     id: string,
     *     name: string,
     *     type: PlaceType,
     *     parentId: string|null,
     *     systemParentId: string|null,
     *     familyId: string|null,
     *     hidden: bool,
     *     forDuty: bool,
     *     description: string|null,
     *     iconId: string|null,
     *     sort: string|null,
     *     purseOfUserId: string|null,
     *     autoHide: bool,
     *     creditCard: bool,
     *     raw: array<string, mixed>
     * }
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'parentId' => $this->parentId,
            'systemParentId' => $this->systemParentId,
            'familyId' => $this->familyId,
            'hidden' => $this->hidden,
            'forDuty' => $this->forDuty,
            'description' => $this->description,
            'iconId' => $this->iconId,
            'sort' => $this->sort,
            'purseOfUserId' => $this->purseOfUserId,
            'autoHide' => $this->autoHide,
            'creditCard' => $this->creditCard,
            'raw' => $this->raw,
        ];
    }

    private static function normalParentId(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = DrebedengiNormalizer::string($value);
        if ($value === '' || $value[0] === '-') {
            return null;
        }

        return $value;
    }

    private static function systemParentId(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = DrebedengiNormalizer::string($value);
        if ($value === '' || $value === '-1' || $value[0] !== '-') {
            return null;
        }

        return $value;
    }
}
