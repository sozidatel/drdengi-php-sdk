<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Support;

final class DrebedengiNormalizer
{
    public static function bool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string)$value)), ['1', 'true', 't', 'yes', 'y'], true);
    }

    public static function nullableId(mixed $value): ?string
    {
        $value = trim((string)$value);

        return $value === '' || $value === '-1' ? null : $value;
    }

    public static function string(mixed $value): string
    {
        return trim((string)$value);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function listOfArrays(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $item) {
            if (is_array($item)) {
                $result[] = $item;
            }
        }

        return $result;
    }
}
