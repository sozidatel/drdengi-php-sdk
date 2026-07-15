<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Support;

use Soz\Drebedengi\Exception\UnexpectedResponseException;

final class DrebedengiDateTime
{
    public static function formatDate(\DateTimeInterface $dateTime, \DateTimeZone $timezone): string
    {
        return self::inTimezone($dateTime, $timezone)->format('Y-m-d');
    }

    public static function formatDateTime(\DateTimeInterface $dateTime, \DateTimeZone $timezone): string
    {
        return self::inTimezone($dateTime, $timezone)->format('Y-m-d H:i:s');
    }

    public static function parseDateTime(string $dateTime, \DateTimeZone $timezone): \DateTimeImmutable
    {
        return self::parseExact('!Y-m-d H:i:s', 'Y-m-d H:i:s', $dateTime, $timezone);
    }

    public static function parseDate(string $date, \DateTimeZone $timezone): \DateTimeImmutable
    {
        return self::parseExact('!Y-m-d', 'Y-m-d', $date, $timezone);
    }

    private static function inTimezone(\DateTimeInterface $dateTime, \DateTimeZone $timezone): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($dateTime)->setTimezone($timezone);
    }

    private static function parseExact(
        string $inputFormat,
        string $outputFormat,
        string $value,
        \DateTimeZone $timezone,
    ): \DateTimeImmutable {
        $parsed = \DateTimeImmutable::createFromFormat($inputFormat, $value, $timezone);
        $errors = \DateTimeImmutable::getLastErrors();

        if (
            !$parsed instanceof \DateTimeImmutable
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $parsed->format($outputFormat) !== $value
        ) {
            throw new UnexpectedResponseException(sprintf(
                'Invalid Drebedengi date value "%s"; expected a real date in format "%s".',
                $value,
                $outputFormat,
            ));
        }

        return $parsed;
    }
}
