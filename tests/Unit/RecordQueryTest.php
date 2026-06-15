<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Soz\Drebedengi\Model\RecordQuery;

final class RecordQueryTest extends TestCase
{
    public function testBuildsSafeDefaultDateRangePayload(): void
    {
        $params = RecordQuery::forDateRange(
            new \DateTimeImmutable('2026-01-01'),
            new \DateTimeImmutable('2026-01-31'),
        )->onlyPlaces(['11416426'])->toSoapParams();

        self::assertSame(false, $params['is_report']);
        self::assertSame(true, $params['is_show_duty']);
        self::assertSame(0, $params['r_period']);
        self::assertSame('2026-01-01', $params['period_from']);
        self::assertSame('2026-01-31', $params['period_to']);
        self::assertSame(6, $params['r_what']);
        self::assertSame(0, $params['r_currency']);
        self::assertSame(1, $params['r_is_place']);
        self::assertSame(['11416426'], $params['r_place']);
    }

    public function testCanFilterByCategory(): void
    {
        $params = RecordQuery::forDateRange(
            new \DateTimeImmutable('2026-01-01'),
            new \DateTimeImmutable('2026-01-31'),
        )->onlyCategories(['10', 20, '10'])->toSoapParams();

        self::assertSame(1, $params['r_is_category']);
        self::assertSame(['10', '20'], $params['r_category']);
    }
}
