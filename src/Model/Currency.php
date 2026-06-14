<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Model;

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
        $ratio = max(1, (int)($raw['ratio'] ?? 1));

        return new self(
            id: DrebedengiNormalizer::string($raw['id'] ?? ''),
            name: (string)($raw['name'] ?? ''),
            code: DrebedengiNormalizer::nullableId($raw['code'] ?? null),
            course: array_key_exists('course', $raw) ? (string)$raw['course'] : null,
            familyId: DrebedengiNormalizer::nullableId($raw['family_id'] ?? null),
            default: DrebedengiNormalizer::bool($raw['is_default'] ?? false),
            autoUpdate: DrebedengiNormalizer::bool($raw['is_autoupdate'] ?? false),
            hidden: DrebedengiNormalizer::bool($raw['is_hidden'] ?? false),
            investing: DrebedengiNormalizer::bool($raw['is_investing'] ?? false),
            ratio: $ratio,
            decimalPlaces: self::decimalPlacesFromRatio($ratio),
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
        $ratio = max(1, $ratio);
        $extraPlaces = 0;
        while ($ratio > 1 && $ratio % 10 === 0) {
            $extraPlaces++;
            $ratio = intdiv($ratio, 10);
        }

        return 2 + $extraPlaces;
    }

    public function jsonSerialize(): array
    {
        return get_object_vars($this);
    }
}
