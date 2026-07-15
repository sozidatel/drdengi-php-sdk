<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Soz\Drebedengi\Exception\UnexpectedResponseException;
use Soz\Drebedengi\Support\DrebedengiDateTime;

final class DrebedengiDateTimeTest extends TestCase
{
    public function testParsesExactDateAndDateTimeFormats(): void
    {
        $timezone = new \DateTimeZone('Europe/Podgorica');

        self::assertSame(
            '2024-02-29 00:00:00 +01:00',
            DrebedengiDateTime::parseDate('2024-02-29', $timezone)->format('Y-m-d H:i:s P'),
        );
        self::assertSame(
            '2026-07-15 13:14:15 +02:00',
            DrebedengiDateTime::parseDateTime('2026-07-15 13:14:15', $timezone)->format('Y-m-d H:i:s P'),
        );
    }

    #[DataProvider('invalidDateProvider')]
    public function testRejectsInvalidOrNonCanonicalDates(string $method, string $value, string $format): void
    {
        $this->expectException(UnexpectedResponseException::class);
        $this->expectExceptionMessage(sprintf('expected a real date in format "%s"', $format));

        DrebedengiDateTime::{$method}($value, new \DateTimeZone('UTC'));
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function invalidDateProvider(): iterable
    {
        yield 'normalized impossible date' => ['parseDate', '2026-02-30', 'Y-m-d'];
        yield 'non-canonical date' => ['parseDate', '2026-2-03', 'Y-m-d'];
        yield 'free-form date' => ['parseDate', '15 July 2026', 'Y-m-d'];
        yield 'normalized impossible time' => ['parseDateTime', '2026-07-15 25:00:00', 'Y-m-d H:i:s'];
        yield 'datetime without seconds' => ['parseDateTime', '2026-07-15 13:14', 'Y-m-d H:i:s'];
    }
}
