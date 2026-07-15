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
        $placeId = DrebedengiNormalizer::requiredString($raw, 'place_id', 'balance');
        $currencyId = DrebedengiNormalizer::requiredString($raw, 'currency_id', 'balance');
        $sum = DrebedengiNormalizer::requiredInteger($raw, 'sum', 'balance');

        if ($currency !== null && $currency->id !== $currencyId) {
            throw new InvalidArgumentException(sprintf(
                'Balance currency ID "%s" does not match supplied currency "%s".',
                $currencyId,
                $currency->id,
            ));
        }

        return new self(
            placeId: $placeId,
            placeName: DrebedengiNormalizer::text($raw['place_name'] ?? ''),
            currencyId: $currencyId,
            currencyName: DrebedengiNormalizer::text($raw['currency_name'] ?? ''),
            sum: $currency?->amountFromMinorUnits($sum) ?? MoneyAmount::fromMinorUnits($sum),
            parentId: DrebedengiNormalizer::nullableId($raw['parent_id'] ?? null),
            forDuty: DrebedengiNormalizer::bool($raw['is_for_duty'] ?? false),
            creditCard: DrebedengiNormalizer::bool($raw['is_credit_card'] ?? false),
            description: array_key_exists('description', $raw)
                ? DrebedengiNormalizer::nullableText($raw['description'])
                : null,
            raw: $raw,
        );
    }

    public function isExcludedFromTotal(): bool
    {
        return $this->parentId === Place::SYSTEM_PARENT_HIDDEN_AMOUNTS;
    }

    /**
     * @return array{
     *     placeId: string,
     *     placeName: string,
     *     currencyId: string,
     *     currencyName: string,
     *     sum: MoneyAmount,
     *     parentId: string|null,
     *     forDuty: bool,
     *     creditCard: bool,
     *     description: string|null,
     *     raw: array<string, mixed>
     * }
     */
    public function jsonSerialize(): array
    {
        return [
            'placeId' => $this->placeId,
            'placeName' => $this->placeName,
            'currencyId' => $this->currencyId,
            'currencyName' => $this->currencyName,
            'sum' => $this->sum,
            'parentId' => $this->parentId,
            'forDuty' => $this->forDuty,
            'creditCard' => $this->creditCard,
            'description' => $this->description,
            'raw' => $this->raw,
        ];
    }
}
