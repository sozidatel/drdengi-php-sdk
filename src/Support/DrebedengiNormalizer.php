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
        if (is_int($value)) {
            return match ($value) {
                1 => true,
                0 => false,
                default => throw new UnexpectedResponseException(
                    'Expected Drebedengi SOAP boolean token 0 or 1.',
                ),
            };
        }
        if (!is_string($value)) {
            throw new UnexpectedResponseException(sprintf(
                'Expected Drebedengi SOAP boolean token, got %s.',
                get_debug_type($value),
            ));
        }

        return match (strtolower(trim($value))) {
            '1', 'true', 't', 'yes', 'y' => true,
            '0', 'false', 'f', 'no', 'n', '' => false,
            default => throw new UnexpectedResponseException(sprintf(
                'Unexpected Drebedengi SOAP boolean token "%s".',
                $value,
            )),
        };
    }

    public static function nullableId(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = self::string($value);

        return $value === '' || $value === '-1' ? null : $value;
    }

    public static function nullableString(mixed $value): ?string
    {
        return $value === null ? null : self::string($value);
    }

    public static function string(mixed $value): string
    {
        return trim(self::text($value));
    }

    public static function nullableText(mixed $value): ?string
    {
        return $value === null ? null : self::text($value);
    }

    public static function text(mixed $value): string
    {
        if (!is_scalar($value)) {
            throw new UnexpectedResponseException(sprintf(
                'Expected Drebedengi SOAP scalar value, got %s.',
                get_debug_type($value),
            ));
        }

        return (string)$value;
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

            $normalizedItem = [];
            foreach ($item as $field => $fieldValue) {
                if (!is_string($field)) {
                    throw new UnexpectedResponseException(sprintf(
                        'Expected Drebedengi SOAP list item at key "%s" to use string field names.',
                        (string)$key,
                    ));
                }

                $normalizedItem[$field] = $fieldValue;
            }

            $result[] = $normalizedItem;
        }

        return $result;
    }
}
