<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Support;

use Soz\Drebedengi\Exception\InvalidArgumentException;
use Soz\Drebedengi\Exception\UnexpectedResponseException;

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

    /** @param array<string, mixed> $raw */
    public static function requiredString(array $raw, string $field, string $context): string
    {
        if (array_key_exists($field, $raw) && is_scalar($raw[$field])) {
            $value = trim((string)$raw[$field]);
            if ($value !== '') {
                return $value;
            }
        }

        throw new UnexpectedResponseException(sprintf(
            'Drebedengi %s response is missing required field "%s".',
            $context,
            $field,
        ));
    }

    /** @param array<string, mixed> $raw */
    public static function requiredInteger(array $raw, string $field, string $context): int
    {
        $value = self::requiredString($raw, $field, $context);
        $integer = filter_var($value, FILTER_VALIDATE_INT);
        if ($integer === false) {
            throw new UnexpectedResponseException(sprintf(
                'Drebedengi %s response contains non-integer field "%s".',
                $context,
                $field,
            ));
        }

        return $integer;
    }

    public static function positiveIntegerId(int|string $id, string $context): int
    {
        $value = trim((string)$id);
        $integer = filter_var($value, FILTER_VALIDATE_INT);
        if ($integer === false || $integer <= 0 || !preg_match('/^\d+$/', $value)) {
            throw new InvalidArgumentException(sprintf(
                '%s must be a positive integer ID.',
                $context,
            ));
        }

        return $integer;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function listOfArrays(mixed $value): array
    {
        if (!is_array($value)) {
            throw new UnexpectedResponseException(sprintf(
                'Expected Drebedengi SOAP list response, got %s.',
                get_debug_type($value),
            ));
        }

        $result = [];
        foreach ($value as $key => $item) {
            if (!is_array($item) || $item === []) {
                throw new UnexpectedResponseException(sprintf(
                    'Expected Drebedengi SOAP list item at key "%s" to be a non-empty array, got %s.',
                    (string)$key,
                    is_array($item) ? 'empty array' : get_debug_type($item),
                ));
            }

            $result[] = $item;
        }

        return $result;
    }
}
