<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Support;

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
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $dateTime, $timezone);
        if ($parsed instanceof \DateTimeImmutable) {
            return $parsed;
        }

        return new \DateTimeImmutable($dateTime, $timezone);
    }

    public static function parseDate(string $date, \DateTimeZone $timezone): \DateTimeImmutable
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, $timezone);
        if ($parsed instanceof \DateTimeImmutable) {
            return $parsed;
        }

        return new \DateTimeImmutable($date, $timezone);
    }

    private static function inTimezone(\DateTimeInterface $dateTime, \DateTimeZone $timezone): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($dateTime)->setTimezone($timezone);
    }
}
