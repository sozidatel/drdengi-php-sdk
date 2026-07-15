<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Soz\Drebedengi\Exception\InvalidArgumentException;
use Soz\Drebedengi\Model\OperationType;
use Soz\Drebedengi\Model\RecordQuery;

final class RecordQueryTest extends TestCase
{
    public function testBuildsSafeDefaultLast20Payload(): void
    {
        $params = (new RecordQuery())->toSoapParams();

        self::assertTrue($params['is_report']);
        self::assertSame(8, $params['r_period']);
        self::assertArrayNotHasKey('period_from', $params);
        self::assertArrayNotHasKey('period_to', $params);
    }

    public function testBuildsSafeDefaultDateRangePayload(): void
    {
        $params = RecordQuery::forDateRange(
            new \DateTimeImmutable('2026-01-01'),
            new \DateTimeImmutable('2026-01-31'),
        )->onlyPlaces(['11416426'])->toSoapParams();

        self::assertSame(true, $params['is_report']);
        self::assertSame(true, $params['is_show_duty']);
        self::assertSame(0, $params['r_period']);
        self::assertSame('2026-01-01', $params['period_from'] ?? null);
        self::assertSame('2026-01-31', $params['period_to'] ?? null);
        self::assertSame(6, $params['r_what']);
        self::assertSame(0, $params['r_who']);
        self::assertSame(0, $params['r_currency']);
        self::assertSame(1, $params['r_is_place']);
        self::assertSame(['11416426'], $params['r_place'] ?? null);
    }

    public function testCanFilterByCategory(): void
    {
        $params = RecordQuery::forDateRange(
            new \DateTimeImmutable('2026-01-01'),
            new \DateTimeImmutable('2026-01-31'),
        )
            ->operationType(OperationType::Expense)
            ->onlyCategories(['10', 20, '10'])
            ->toSoapParams();

        self::assertSame(OperationType::Expense->value, $params['r_what']);
        self::assertSame(1, $params['r_is_category']);
        self::assertSame(['10', '20'], $params['r_category'] ?? null);
    }

    public function testRejectsCategoryFilterForAllOperationTypesBeforeSoapCall(): void
    {
        $query = RecordQuery::forDateRange(
            new \DateTimeImmutable('2026-01-01'),
            new \DateTimeImmutable('2026-01-31'),
        )->onlyCategories(['10']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('OperationType::Expense or OperationType::Income');

        $query->toSoapParams();
    }

    public function testCanRequestBalanceAfterWithoutSendingWebOnlySoapParameter(): void
    {
        $query = RecordQuery::forDateRange(
            new \DateTimeImmutable('2026-01-01'),
            new \DateTimeImmutable('2026-01-31'),
        )->withBalanceAfter();

        self::assertTrue($query->shouldIncludeBalanceAfter());
        self::assertArrayNotHasKey('is_with_rest', $query->toSoapParams());
    }

    public function testCanRequestAllTimeLedgerReport(): void
    {
        $params = (new RecordQuery())->allTime()->toSoapParams();

        self::assertTrue($params['is_report']);
        self::assertSame(6, $params['r_period']);
        self::assertArrayNotHasKey('period_from', $params);
        self::assertArrayNotHasKey('period_to', $params);
    }

    #[DataProvider('namedPeriodProvider')]
    public function testSupportsEveryNamedLedgerPeriod(string $method, int $period): void
    {
        $query = new RecordQuery();

        $query = match ($method) {
            'today' => $query->today(),
            'thisMonth' => $query->thisMonth(),
            'lastMonth' => $query->lastMonth(),
            'thisQuarter' => $query->thisQuarter(),
            'thisYear' => $query->thisYear(),
            'lastYear' => $query->lastYear(),
            'allTime' => $query->allTime(),
            'last20' => $query->last20(),
            default => throw new \LogicException(sprintf('Unknown named period "%s".', $method)),
        };
        $params = $query->toSoapParams();

        self::assertSame($period, $params['r_period']);
        self::assertArrayNotHasKey('period_from', $params);
        self::assertArrayNotHasKey('period_to', $params);
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function namedPeriodProvider(): iterable
    {
        yield 'today' => ['today', 7];
        yield 'this month' => ['thisMonth', 1];
        yield 'last month' => ['lastMonth', 2];
        yield 'this quarter' => ['thisQuarter', 3];
        yield 'this year' => ['thisYear', 4];
        yield 'last year' => ['lastYear', 5];
        yield 'all time' => ['allTime', 6];
        yield 'last 20' => ['last20', 8];
    }

    public function testFormatsRelativeDateInAccountTimezone(): void
    {
        $params = (new RecordQuery())
            ->today()
            ->relativeTo(new \DateTimeImmutable('2026-07-13 22:30:00', new \DateTimeZone('UTC')))
            ->toSoapParams(new \DateTimeZone('Europe/Podgorica'));

        self::assertSame('2026-07-14', $params['relative_date'] ?? null);
    }

    public function testSupportsPlannedDebtsUserAndAllFilterDirections(): void
    {
        $params = (new RecordQuery())
            ->thisMonth()
            ->operationType(OperationType::Expense)
            ->includePlanned()
            ->includeDebts(false)
            ->forUser('42')
            ->exceptPlaces(['10', 20, '10'])
            ->onlyTags(['30', 40, '30'])
            ->exceptCategories(['50', 60, '50'])
            ->toSoapParams();

        self::assertTrue($params['is_with_planned']);
        self::assertFalse($params['is_show_duty']);
        self::assertSame(42, $params['r_who']);
        self::assertSame(2, $params['r_is_place']);
        self::assertSame(['10', '20'], $params['r_place'] ?? null);
        self::assertSame(1, $params['r_is_tag']);
        self::assertSame(['30', '40'], $params['r_tag'] ?? null);
        self::assertSame(2, $params['r_is_category']);
        self::assertSame(['50', '60'], $params['r_category'] ?? null);
    }

    public function testSupportsExceptTagsAndOnlyCategories(): void
    {
        $params = (new RecordQuery())
            ->today()
            ->operationType(OperationType::Income)
            ->exceptTags(['30'])
            ->onlyCategories(['50'])
            ->toSoapParams();

        self::assertSame(2, $params['r_is_tag']);
        self::assertSame(['30'], $params['r_tag'] ?? null);
        self::assertSame(1, $params['r_is_category']);
        self::assertSame(['50'], $params['r_category'] ?? null);
    }

    public function testCanResetUserAndBooleanOptions(): void
    {
        $params = (new RecordQuery())
            ->forUser(42)
            ->forAllUsers()
            ->includePlanned()
            ->includePlanned(false)
            ->includeDebts(false)
            ->includeDebts()
            ->last20()
            ->toSoapParams();

        self::assertSame(0, $params['r_who']);
        self::assertFalse($params['is_with_planned']);
        self::assertTrue($params['is_show_duty']);
    }

    public function testCanResetAllDimensionFilters(): void
    {
        $params = (new RecordQuery())
            ->today()
            ->operationType(OperationType::Expense)
            ->onlyPlaces(['10'])
            ->allPlaces()
            ->exceptTags(['20'])
            ->allTags()
            ->onlyCategories(['30'])
            ->allCategories()
            ->toSoapParams();

        self::assertSame(0, $params['r_is_place']);
        self::assertSame(0, $params['r_is_tag']);
        self::assertSame(0, $params['r_is_category']);
        self::assertArrayNotHasKey('r_place', $params);
        self::assertArrayNotHasKey('r_tag', $params);
        self::assertArrayNotHasKey('r_category', $params);
    }

    public function testRejectsEmptyFilterIds(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('at least one non-empty ID');

        (new RecordQuery())->onlyTags(['', '  ']);
    }

    public function testRejectsInvalidUserId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('positive integer ID');

        (new RecordQuery())->forUser('not-an-id');
    }
}
