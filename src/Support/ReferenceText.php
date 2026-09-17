<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Support;

use Soz\Drebedengi\Exception\InvalidArgumentException;

/** @internal */
final class ReferenceText
{
    public static function normalize(
        mixed $value,
        string $context,
        int $maxLength,
        bool $allowEmpty = false,
        bool $allowPossibleEcho = false,
    ): string {
        if (!is_string($value)) {
            throw new InvalidArgumentException(sprintf('%s must be a string.', $context));
        }

        $value = trim($value);
        if (!$allowEmpty && $value === '') {
            throw new InvalidArgumentException(sprintf('%s cannot be empty.', $context));
        }

        $length = preg_match_all('/./us', $value);
        if ($length === false) {
            throw new InvalidArgumentException(sprintf('%s must be valid UTF-8.', $context));
        }
        // Before fetching the object, an escaped server echo may exceed the
        // limit. Validate again without this allowance after matching the echo.
        if ($allowPossibleEcho && $length > $maxLength) {
            $length = preg_match_all('/./us', self::decodeSoapHtml($value));
        }
        if ($length === false || $length > $maxLength) {
            throw new InvalidArgumentException(sprintf('%s must not exceed %d characters.', $context, $maxLength));
        }

        return $value;
    }

    /**
     * Decode exactly one layer only for unchanged values echoed from the server.
     * Names and currency codes also match after the normal input trimming;
     * descriptions keep exact whitespace and require an exact echo.
     *
     * @param array<string, mixed> $fields
     * @param array<string, mixed> $raw
     * @param list<string> $textFields
     * @return array<string, mixed>
     */
    public static function decodeEchoedFields(array $fields, array $raw, array $textFields): array
    {
        foreach ($textFields as $field) {
            $value = $fields[$field] ?? null;
            $rawValue = $raw[$field] ?? null;
            if (!is_string($value) || !is_scalar($rawValue)) {
                continue;
            }

            $rawValue = (string)$rawValue;
            if (
                $value === $rawValue
                || (in_array($field, ['name', 'code'], true) && trim($value) === $rawValue)
            ) {
                $fields[$field] = self::decodeSoapHtml($value);
            }
        }

        return $fields;
    }

    public static function decodeSoapHtml(string $value): string
    {
        return html_entity_decode($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
