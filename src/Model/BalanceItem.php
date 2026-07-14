<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Model;

use Soz\Drebedengi\Exception\InvalidArgumentException;
use Soz\Drebedengi\Support\DrebedengiNormalizer;

final readonly class BalanceItem implements \JsonSerializable
{
    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public string $placeId,
        public string $placeName,
        public string $currencyId,
        public string $currencyName,
        public MoneyAmount $sum,
        public ?string $parentId,
        public bool $forDuty,
        public bool $creditCard,
        public ?string $description,
        public array $raw,
    ) {
    }

    /**
     * @param array<string, mixed> $raw
     */
    public static function fromSoap(array $raw, ?Currency $currency = null): self
    {
        $currencyId = DrebedengiNormalizer::string($raw['currency_id'] ?? '');

        if ($currency !== null && $currency->id !== $currencyId) {
            throw new InvalidArgumentException(sprintf(
                'Balance currency ID "%s" does not match supplied currency "%s".',
                $currencyId,
                $currency->id,
            ));
        }

        return new self(
            placeId: DrebedengiNormalizer::string($raw['place_id'] ?? ''),
            placeName: (string)($raw['place_name'] ?? ''),
            currencyId: $currencyId,
            currencyName: (string)($raw['currency_name'] ?? ''),
            sum: $currency?->amountFromMinorUnits((int)($raw['sum'] ?? 0))
                ?? MoneyAmount::fromMinorUnits((int)($raw['sum'] ?? 0)),
            parentId: DrebedengiNormalizer::nullableId($raw['parent_id'] ?? null),
            forDuty: DrebedengiNormalizer::bool($raw['is_for_duty'] ?? false),
            creditCard: DrebedengiNormalizer::bool($raw['is_credit_card'] ?? false),
            description: array_key_exists('description', $raw) ? (string)$raw['description'] : null,
            raw: $raw,
        );
    }

    public function isExcludedFromTotal(): bool
    {
        return $this->parentId === Place::SYSTEM_PARENT_HIDDEN_AMOUNTS;
    }

    public function jsonSerialize(): array
    {
        return get_object_vars($this);
    }
}
