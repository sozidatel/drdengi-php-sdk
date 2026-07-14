<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Model;

use Soz\Drebedengi\Exception\InvalidArgumentException;
use Soz\Drebedengi\Exception\UnexpectedResponseException;
use Soz\Drebedengi\Support\DrebedengiNormalizer;

final readonly class Currency implements \JsonSerializable
{
    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public string $id,
        public string $name,
        public ?string $code,
        public ?string $course,
        public ?string $familyId,
        public bool $default,
        public bool $autoUpdate,
        public bool $hidden,
        public bool $investing,
        public int $ratio,
        public int $decimalPlaces,
        public array $raw,
    ) {
    }

    /**
     * @param array<string, mixed> $raw
     */
    public static function fromSoap(array $raw): self
    {
        $ratio = DrebedengiNormalizer::requiredInteger($raw, 'ratio', 'currency');

        try {
            $decimalPlaces = self::decimalPlacesFromRatio($ratio);
        } catch (InvalidArgumentException $exception) {
            throw new UnexpectedResponseException(
                sprintf('Drebedengi currency response contains invalid ratio %d.', $ratio),
                0,
                $exception,
            );
        }

        return new self(
            id: DrebedengiNormalizer::requiredString($raw, 'id', 'currency'),
            name: DrebedengiNormalizer::requiredString($raw, 'name', 'currency'),
            code: DrebedengiNormalizer::nullableId($raw['code'] ?? null),
            course: array_key_exists('course', $raw) ? (string)$raw['course'] : null,
            familyId: DrebedengiNormalizer::nullableId($raw['family_id'] ?? null),
            default: DrebedengiNormalizer::bool($raw['is_default'] ?? false),
            autoUpdate: DrebedengiNormalizer::bool($raw['is_autoupdate'] ?? false),
            hidden: DrebedengiNormalizer::bool($raw['is_hidden'] ?? false),
            investing: DrebedengiNormalizer::bool($raw['is_investing'] ?? false),
            ratio: $ratio,
            decimalPlaces: $decimalPlaces,
            raw: $raw,
        );
    }

    /**
     * Drebedengi historically stores amounts in hundredths. Modern crypto
     * currencies expose extra precision through `ratio`, where fiat `ratio=1`
     * means two decimal places and e.g. `ratio=1000000` means eight places.
     */
    public static function decimalPlacesFromRatio(int $ratio): int
    {
        if ($ratio < 1) {
            throw new InvalidArgumentException('Currency ratio must be a positive power of ten.');
        }

        $extraPlaces = 0;
        while ($ratio > 1 && $ratio % 10 === 0) {
            $extraPlaces++;
            $ratio = intdiv($ratio, 10);
        }

        if ($ratio !== 1 || $extraPlaces > 16) {
            throw new InvalidArgumentException('Currency ratio must be a supported power of ten.');
        }

        return 2 + $extraPlaces;
    }

    public function amount(string $decimal): MoneyAmount
    {
        return MoneyAmount::fromDecimalString($decimal, $this->decimalPlaces, $this->id);
    }

    public function amountFromMinorUnits(int $minorUnits): MoneyAmount
    {
        return MoneyAmount::fromMinorUnits($minorUnits, $this->decimalPlaces, $this->id);
    }

    public function jsonSerialize(): array
    {
        return get_object_vars($this);
    }
}
