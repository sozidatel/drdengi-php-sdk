<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Model;

use Soz\Drebedengi\Exception\InvalidArgumentException;
use Soz\Drebedengi\Exception\UnexpectedResponseException;
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
        $placeId = self::requiredString($raw, 'place_id');
        $currencyId = self::requiredString($raw, 'currency_id');
        $sum = self::requiredInteger($raw, 'sum');

        if ($currency !== null && $currency->id !== $currencyId) {
            throw new InvalidArgumentException(sprintf(
                'Balance currency ID "%s" does not match supplied currency "%s".',
                $currencyId,
                $currency->id,
            ));
        }

        return new self(
            placeId: $placeId,
            placeName: (string)($raw['place_name'] ?? ''),
            currencyId: $currencyId,
            currencyName: (string)($raw['currency_name'] ?? ''),
            sum: $currency?->amountFromMinorUnits($sum) ?? MoneyAmount::fromMinorUnits($sum),
            parentId: DrebedengiNormalizer::nullableId($raw['parent_id'] ?? null),
            forDuty: DrebedengiNormalizer::bool($raw['is_for_duty'] ?? false),
            creditCard: DrebedengiNormalizer::bool($raw['is_credit_card'] ?? false),
            description: array_key_exists('description', $raw) ? (string)$raw['description'] : null,
            raw: $raw,
        );
    }

    /** @param array<string, mixed> $raw */
    private static function requiredString(array $raw, string $field): string
    {
        if (array_key_exists($field, $raw) && is_scalar($raw[$field])) {
            $value = trim((string)$raw[$field]);
            if ($value !== '') {
                return $value;
            }
        }

        throw new UnexpectedResponseException(sprintf(
            'Drebedengi balance response is missing required field "%s".',
            $field,
        ));
    }

    /** @param array<string, mixed> $raw */
    private static function requiredInteger(array $raw, string $field): int
    {
        $value = self::requiredString($raw, $field);
        if (!preg_match('/^-?\d+$/', $value)) {
            throw new UnexpectedResponseException(sprintf(
                'Drebedengi balance response contains non-integer field "%s".',
                $field,
            ));
        }

        return (int)$value;
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
