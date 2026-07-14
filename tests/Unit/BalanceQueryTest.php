<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Soz\Drebedengi\Model\BalanceQuery;

final class BalanceQueryTest extends TestCase
{
    public function testBuildsTypedBalanceParametersInAccountTimezone(): void
    {
        $params = BalanceQuery::at(new \DateTimeImmutable(
            '2026-07-13 22:30:00',
            new \DateTimeZone('UTC'),
        ))
            ->includeHidden()
            ->includeZero()
            ->subtractAccumulations()
            ->subtractDebts()
            ->toSoapParams(new \DateTimeZone('Europe/Podgorica'));

        self::assertSame([
            'is_with_accum' => true,
            'is_with_duty' => true,
            'is_with_hidden' => true,
            'is_with_null' => true,
            'restDate' => '2026-07-14',
        ], $params);
    }

    public function testUsesServerDefaultsForCurrentDate(): void
    {
        self::assertSame([
            'is_with_accum' => false,
            'is_with_duty' => false,
            'is_with_hidden' => false,
            'is_with_null' => false,
        ], (new BalanceQuery())->toSoapParams());
    }

    public function testCanDisablePreviouslyEnabledOptions(): void
    {
        $params = (new BalanceQuery())
            ->includeHidden()
            ->includeHidden(false)
            ->includeZero()
            ->includeZero(false)
            ->subtractAccumulations()
            ->subtractAccumulations(false)
            ->subtractDebts()
            ->subtractDebts(false)
            ->toSoapParams();

        self::assertSame([
            'is_with_accum' => false,
            'is_with_duty' => false,
            'is_with_hidden' => false,
            'is_with_null' => false,
        ], $params);
    }
}
