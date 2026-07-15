<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Soz\Drebedengi\ClientOptions;
use Soz\Drebedengi\DrebedengiClient;
use Soz\Drebedengi\Exception\UnexpectedResponseException;
use Soz\Drebedengi\Model\Currency;
use Soz\Drebedengi\Model\ReportQuery;
use Soz\Drebedengi\Service\ReportService;
use Soz\Drebedengi\Support\CurrencyCatalog;
use Soz\Drebedengi\Tests\Support\FakeTransport;

final class ReportServiceTest extends TestCase
{
    public function testMapsExpenseCategoryReportWithoutRoundingConvertedAmount(): void
    {
        $transport = new FakeTransport(['getRecordList' => [[
            'name' => 'Food',
            'difference' => -9057.3831561769,
            'currency_id' => '18',
            'budget_object_id' => '40001',
            'parent_id' => '-1',
            'nochild' => 'f',
            'nick' => 'Household',
        ]]]);
        $service = $this->service($transport);

        $rows = $service->expensesByCategory(
            (new ReportQuery())->thisMonth()->convertedToCurrency('18'),
        );

        self::assertCount(1, $rows);
        self::assertSame('40001', $rows[0]->objectId);
        self::assertSame('Food', $rows[0]->name);
        self::assertNull($rows[0]->parentId);
        self::assertFalse($rows[0]->leaf);
        self::assertTrue($rows[0]->hasChildren());
        self::assertSame('Household', $rows[0]->nickname);
        self::assertSame('-9057.3831561769', $rows[0]->amount->minorUnits);
        self::assertSame('-90.573831561769', $rows[0]->amount->toDecimalString());

        $params = $transport->mapArgument(0);
        self::assertSame(3, $params['r_what']);
        self::assertSame(3, $params['r_how']);
        self::assertSame('18', $params['r_currency']);
        self::assertSame([], $transport->calls[0]['arguments'][1]);
    }

    public function testMapsIncomeSourceReport(): void
    {
        $transport = new FakeTransport(['getRecordList' => [[
            'name' => 'Salary',
            'difference' => '4946100',
            'currency_id' => '17',
            'budget_object_id' => '40036',
            'parent_id' => '-1',
            'nochild' => 't',
        ]]]);
        $service = $this->service($transport);

        $rows = $service->incomeBySource();

        self::assertSame('49461.00', $rows[0]->amount->toDecimalString());
        self::assertTrue($rows[0]->leaf);
        self::assertFalse($rows[0]->hasChildren());
        self::assertSame(2, $transport->mapArgument(0)['r_what']);
        self::assertSame(2, $transport->mapArgument(0)['r_how']);
    }

    public function testClientExposesReportServiceWithClientTimezone(): void
    {
        $transport = new FakeTransport(['getRecordList' => []]);
        $client = new DrebedengiClient(
            $transport,
            new ClientOptions(new \DateTimeZone('Europe/Podgorica')),
            $this->currencies($transport),
        );

        $client->reports()->expensesByCategory(
            (new ReportQuery())
                ->today()
                ->relativeTo(new \DateTimeImmutable('2026-07-13 22:30:00', new \DateTimeZone('UTC'))),
        );

        self::assertSame('2026-07-14', $transport->mapArgument(0)['relative_date']);
    }

    public function testRejectsUnknownReportCurrency(): void
    {
        $transport = new FakeTransport(['getRecordList' => [[
            'name' => 'Food',
            'difference' => '-100',
            'currency_id' => '999',
            'budget_object_id' => '1',
            'parent_id' => '-1',
            'nochild' => 't',
        ]]]);

        $this->expectException(UnexpectedResponseException::class);
        $this->expectExceptionMessage('unknown currency ID "999"');

        $this->service($transport)->expensesByCategory();
    }

    public function testRejectsMalformedReportDifference(): void
    {
        $transport = new FakeTransport(['getRecordList' => [[
            'name' => 'Food',
            'difference' => 'not-a-number',
            'currency_id' => '17',
            'budget_object_id' => '1',
            'parent_id' => '-1',
            'nochild' => 't',
        ]]]);

        $this->expectException(UnexpectedResponseException::class);
        $this->expectExceptionMessage('invalid numeric difference');

        $this->service($transport)->expensesByCategory();
    }

    private function service(FakeTransport $transport): ReportService
    {
        return new ReportService(
            $transport,
            new ClientOptions(),
            $this->currencies($transport),
        );
    }

    private function currencies(FakeTransport $transport): CurrencyCatalog
    {
        return new CurrencyCatalog($transport, [
            Currency::fromSoap(['id' => '17', 'name' => 'RUB', 'code' => 'RUB', 'ratio' => '1']),
            Currency::fromSoap(['id' => '18', 'name' => 'USD', 'code' => 'USD', 'ratio' => '1']),
        ]);
    }
}
