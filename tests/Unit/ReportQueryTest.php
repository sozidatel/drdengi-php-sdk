<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Soz\Drebedengi\Exception\InvalidArgumentException;
use Soz\Drebedengi\Model\OperationType;
use Soz\Drebedengi\Model\ReportAveraging;
use Soz\Drebedengi\Model\ReportQuery;

final class ReportQueryTest extends TestCase
{
    public function testBuildsDefaultMonthlyExpenseReport(): void
    {
        $params = (new ReportQuery())->toSoapParams(OperationType::Expense);

        self::assertTrue($params['is_report']);
        self::assertSame(1, $params['r_period']);
        self::assertSame(OperationType::Expense->value, $params['r_what']);
        self::assertSame(3, $params['r_how']);
        self::assertSame(0, $params['r_middle']);
        self::assertSame(0, $params['r_currency']);
    }

    public function testBuildsFilteredAveragedIncomeReportInAccountTimezone(): void
    {
        $params = (new ReportQuery())
            ->today()
            ->relativeTo(new \DateTimeImmutable('2026-07-13 22:30:00', new \DateTimeZone('UTC')))
            ->includeDebts(false)
            ->includePlanned()
            ->forUser('42')
            ->exceptPlaces(['10'])
            ->onlyTags(['20'])
            ->exceptCategories(['30'])
            ->convertedToCurrency('18')
            ->averageBy(ReportAveraging::Weekly)
            ->toSoapParams(OperationType::Income, new \DateTimeZone('Europe/Podgorica'));

        self::assertSame('2026-07-14', $params['relative_date']);
        self::assertFalse($params['is_show_duty']);
        self::assertTrue($params['is_with_planned']);
        self::assertSame(42, $params['r_who']);
        self::assertSame(2, $params['r_is_place']);
        self::assertSame(['10'], $params['r_place']);
        self::assertSame(1, $params['r_is_tag']);
        self::assertSame(['20'], $params['r_tag']);
        self::assertSame(2, $params['r_is_category']);
        self::assertSame(['30'], $params['r_category']);
        self::assertSame('18', $params['r_currency']);
        self::assertSame(OperationType::Income->value, $params['r_what']);
        self::assertSame(2, $params['r_how']);
        self::assertSame(ReportAveraging::Weekly->value, $params['r_middle']);
    }

    public function testAveragingConveniencesAreReversible(): void
    {
        $query = (new ReportQuery())->averageDaily()->averageWeekly()->averageMonthly();
        self::assertSame(
            ReportAveraging::Monthly->value,
            $query->toSoapParams(OperationType::Expense)['r_middle'],
        );

        self::assertSame(
            ReportAveraging::None->value,
            $query->withoutAveraging()->toSoapParams(OperationType::Expense)['r_middle'],
        );
    }

    public function testRejectsUnsupportedOperationType(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new ReportQuery())->toSoapParams(OperationType::All);
    }
}
