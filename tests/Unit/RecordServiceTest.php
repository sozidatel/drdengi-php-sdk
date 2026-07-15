<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Soz\Drebedengi\ClientOptions;
use Soz\Drebedengi\Exception\InvalidArgumentException;
use Soz\Drebedengi\Exception\UnexpectedResponseException;
use Soz\Drebedengi\Model\Currency;
use Soz\Drebedengi\Model\ExpenseGroupItem;
use Soz\Drebedengi\Model\MoneyAmount;
use Soz\Drebedengi\Model\OperationType;
use Soz\Drebedengi\Model\Record;
use Soz\Drebedengi\Model\RecordQuery;
use Soz\Drebedengi\Service\RecordService;
use Soz\Drebedengi\Support\CurrencyCatalog;
use Soz\Drebedengi\Tests\Support\FakeTransport;

final class RecordServiceTest extends TestCase
{
    public function testCreateExpenseBuildsSetRecordListPayload(): void
    {
        $transport = new FakeTransport(['setRecordList' => [['server_id' => '10']]]);
        $service = $this->service($transport);

        $result = $service->createExpense(
            placeId: '1',
            categoryId: '2',
            amount: MoneyAmount::fromDecimalString('12.34'),
            currencyId: '3',
            date: new \DateTimeImmutable('2026-06-14 12:00:00'),
            comment: 'SDK test',
        );

        self::assertSame([['server_id' => '10']], $result);
        self::assertSame('setRecordList', $transport->calls[0]['method']);

        $payload = $transport->mapListArgument(0)[0];
        self::assertArrayHasKey('client_id', $payload);
        self::assertSame('1', $payload['place_id']);
        self::assertSame('2', $payload['budget_object_id']);
        self::assertSame(-1234, $payload['sum']);
        self::assertSame('2026-06-14 12:00:00', $payload['operation_date']);
        self::assertSame('SDK test', $payload['comment']);
        self::assertSame('3', $payload['currency_id']);
        self::assertSame(3, $payload['operation_type']);
    }

    public function testCreateTransferBuildsPairedRecords(): void
    {
        $transport = new FakeTransport();
        $service = $this->service($transport);

        $service->createTransfer(
            fromPlaceId: '1',
            toPlaceId: '2',
            amount: MoneyAmount::fromDecimalString('5.00'),
            currencyId: '3',
            date: new \DateTimeImmutable('2026-06-14 12:00:00'),
            comment: 'SDK transfer',
        );

        $payloads = $transport->mapListArgument(0);
        self::assertCount(2, $payloads);
        self::assertSame(-500, $payloads[0]['sum']);
        self::assertSame(500, $payloads[1]['sum']);
        self::assertSame($payloads[0]['client_id'], $payloads[1]['client_move_id']);
        self::assertSame($payloads[1]['client_id'], $payloads[0]['client_move_id']);
        self::assertSame(4, $payloads[0]['operation_type']);
        self::assertSame(4, $payloads[1]['operation_type']);
    }

    public function testCreateTransferRejectsSamePlaceBeforeSoapCall(): void
    {
        $transport = new FakeTransport();
        $service = $this->service($transport);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be different');

        try {
            $service->createTransfer(
                fromPlaceId: '1',
                toPlaceId: 1,
                amount: MoneyAmount::fromDecimalString('5.00'),
                currencyId: '3',
                date: new \DateTimeImmutable('2026-06-14 12:00:00'),
            );
        } finally {
            self::assertSame([], $transport->calls);
        }
    }

    public function testDeleteRejectsInvalidRecordIdBeforeSoapCall(): void
    {
        $transport = new FakeTransport();
        $service = $this->service($transport);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Record ID');

        try {
            $service->delete('not-an-id', OperationType::Expense);
        } finally {
            self::assertSame([], $transport->calls);
        }
    }

    public function testDeleteUsesValidatedSoapResult(): void
    {
        $transport = new FakeTransport(['deleteObject' => '1']);

        self::assertTrue($this->service($transport)->delete('10', OperationType::Expense));
        self::assertSame([10, 'waste'], $transport->calls[0]['arguments']);
    }

    #[DataProvider('malformedDeleteResponseProvider')]
    public function testDeleteRejectsMalformedSoapResult(mixed $response, string $message): void
    {
        $service = $this->service(new FakeTransport(['deleteObject' => $response]));

        $this->expectException(UnexpectedResponseException::class);
        $this->expectExceptionMessage($message);

        $service->delete('10', OperationType::Expense);
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function malformedDeleteResponseProvider(): iterable
    {
        yield 'array' => [['unexpected'], 'must be an integer, got array'];
        yield 'non-integer string' => ['broken', 'non-integer field "result"'];
        yield 'unknown integer status' => [2, 'must be 0 or 1'];
        yield 'boolean' => [true, 'must be an integer, got bool'];
        yield 'float' => [1.0, 'must be an integer, got float'];
    }

    public function testCreateExchangeRejectsSameCurrency(): void
    {
        $service = new RecordService(new FakeTransport());

        $this->expectException(\Soz\Drebedengi\Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('two different currency ids');

        $service->createExchange(
            placeId: '1',
            soldAmount: MoneyAmount::fromDecimalString('1.00'),
            soldCurrencyId: '3',
            boughtAmount: MoneyAmount::fromDecimalString('0.50'),
            boughtCurrencyId: '3',
            date: new \DateTimeImmutable('2026-06-14 12:00:00'),
        );
    }

    public function testCreateExpenseFormatsDateInAccountTimezone(): void
    {
        $transport = new FakeTransport();
        $service = $this->service(
            $transport,
            new ClientOptions(new \DateTimeZone('Europe/Podgorica')),
        );

        $service->createExpense(
            placeId: '1',
            categoryId: '2',
            amount: MoneyAmount::fromDecimalString('12.34'),
            currencyId: '3',
            date: new \DateTimeImmutable('2026-06-14 10:00:00', new \DateTimeZone('UTC')),
            comment: 'SDK test',
        );

        $payload = $transport->mapListArgument(0)[0];
        self::assertSame('2026-06-14 12:00:00', $payload['operation_date']);
    }

    public function testCreateExpenseGroupReturnsEmptyResponseForEmptyItems(): void
    {
        $transport = new FakeTransport();
        $service = $this->service($transport);

        self::assertSame([], $service->createExpenseGroup(
            placeId: '1',
            items: [],
            currencyId: '3',
            date: new \DateTimeImmutable('2026-06-14 12:00:00'),
        ));
        self::assertSame([], $transport->calls);
    }

    public function testCreateExpenseGroupWithOneItemUsesSingleExpensePayload(): void
    {
        $transport = new FakeTransport(['setRecordList' => [['server_id' => '10']]]);
        $service = $this->service($transport);

        $result = $service->createExpenseGroup(
            placeId: '1',
            items: [
                new ExpenseGroupItem('2', MoneyAmount::fromDecimalString('12.34'), 'Coffee'),
            ],
            currencyId: '3',
            date: new \DateTimeImmutable('2026-06-14 12:00:00'),
        );

        self::assertSame([['server_id' => '10']], $result);
        self::assertCount(1, $transport->calls);

        $payload = $transport->mapListArgument(0)[0];
        self::assertSame('2', $payload['budget_object_id']);
        self::assertSame(-1234, $payload['sum']);
        self::assertSame('Coffee', $payload['comment']);
        self::assertArrayNotHasKey('group_id', $payload);
    }

    public function testCreateExpenseGroupBuildsOneStepGroupedPayload(): void
    {
        $transport = new FakeTransport([
            'setRecordList' => [['server_id' => '100'], ['server_id' => '101']],
        ]);
        $service = $this->service(
            $transport,
            new ClientOptions(new \DateTimeZone('Europe/Podgorica')),
        );

        $result = $service->createExpenseGroup(
            placeId: '1',
            items: [
                new ExpenseGroupItem('10', MoneyAmount::fromDecimalString('12.34'), 'Coffee'),
                ['categoryId' => '20', 'amount' => MoneyAmount::fromDecimalString('5.67'), 'comment' => 'Cake'],
            ],
            currencyId: '3',
            date: new \DateTimeImmutable('2026-06-14 10:00:00', new \DateTimeZone('UTC')),
        );

        self::assertSame([['server_id' => '100'], ['server_id' => '101']], $result);
        self::assertCount(1, $transport->calls);

        $groupedPayloads = $transport->mapListArgument(0);
        self::assertCount(2, $groupedPayloads);

        self::assertArrayHasKey('client_id', $groupedPayloads[0]);
        self::assertArrayHasKey('client_id', $groupedPayloads[1]);
        self::assertArrayNotHasKey('server_id', $groupedPayloads[0]);
        self::assertArrayNotHasKey('server_id', $groupedPayloads[1]);
        self::assertNotSame('', $groupedPayloads[0]['group_id']);
        self::assertSame($groupedPayloads[0]['group_id'], $groupedPayloads[1]['group_id']);
        self::assertSame('10', $groupedPayloads[0]['budget_object_id']);
        self::assertSame(-1234, $groupedPayloads[0]['sum']);
        self::assertSame('Coffee', $groupedPayloads[0]['comment']);
        self::assertSame(3, $groupedPayloads[0]['operation_type']);
        self::assertSame('2026-06-14 12:00:00', $groupedPayloads[0]['operation_date']);

        self::assertSame('20', $groupedPayloads[1]['budget_object_id']);
        self::assertSame(-567, $groupedPayloads[1]['sum']);
        self::assertSame('Cake', $groupedPayloads[1]['comment']);
        self::assertSame(3, $groupedPayloads[1]['operation_type']);
    }

    /** @param array<string, mixed> $item */
    #[DataProvider('malformedExpenseGroupItemProvider')]
    public function testCreateExpenseGroupValidatesArrayItems(array $item, string $message): void
    {
        $service = new RecordService(new FakeTransport());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        // Cross the documented item-shape boundary to verify defensive runtime validation.
        (new \ReflectionMethod($service, 'createExpenseGroup'))->invoke(
            $service,
            '1',
            [$item],
            '3',
            new \DateTimeImmutable('2026-06-14 12:00:00'),
        );
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function malformedExpenseGroupItemProvider(): iterable
    {
        yield 'missing amount' => [
            ['categoryId' => '10'],
            'must contain categoryId and amount',
        ];
        yield 'invalid category ID' => [
            ['categoryId' => ['10'], 'amount' => MoneyAmount::fromDecimalString('12.34')],
            'categoryId must be an integer or string',
        ];
        yield 'invalid amount' => [
            ['categoryId' => '10', 'amount' => '12.34'],
            'amount must be an instance of MoneyAmount',
        ];
        yield 'invalid comment' => [
            ['categoryId' => '10', 'amount' => MoneyAmount::fromDecimalString('12.34'), 'comment' => 42],
            'comment must be a string',
        ];
    }

    public function testRecordQueryFormatsDateRangeInAccountTimezone(): void
    {
        $transport = new FakeTransport(['getRecordList' => []]);
        $service = $this->service(
            $transport,
            new ClientOptions(new \DateTimeZone('Europe/Podgorica')),
        );

        $service->list(RecordQuery::forDateRange(
            new \DateTimeImmutable('2026-06-13 22:30:00', new \DateTimeZone('UTC')),
            new \DateTimeImmutable('2026-06-14 22:30:00', new \DateTimeZone('UTC')),
        ));

        $params = $transport->mapArgument(0);
        self::assertTrue($params['is_report']);
        self::assertSame('2026-06-14', $params['period_from']);
        self::assertSame('2026-06-15', $params['period_to']);
        self::assertCount(1, $transport->calls);
        self::assertSame('getRecordList', $transport->calls[0]['method']);
    }

    public function testDefaultAndEmptyRecordQueryBothRequestLast20(): void
    {
        $transport = new FakeTransport(['getRecordList' => []]);
        $service = $this->service($transport);

        $service->list();
        $service->list(new RecordQuery());

        self::assertCount(2, $transport->calls);
        self::assertSame(8, $transport->mapArgument(0)['r_period']);
        self::assertSame($transport->mapArgument(0), $transport->mapArgument(1));
    }

    public function testReadsRecordsByIdsWithExplicitSafeMode(): void
    {
        $transport = new FakeTransport(['getRecordList' => []]);
        $service = $this->service($transport);

        $service->byIds(['10', 20]);

        self::assertSame(['is_report' => true], $transport->mapArgument(0));
        self::assertSame(['10', '20'], $transport->calls[0]['arguments'][1]);
    }

    public function testByIdsReturnsEmptyListWithoutSoapCallForEmptyIds(): void
    {
        $transport = new FakeTransport();
        $service = $this->service($transport);

        self::assertSame([], $service->byIds([]));
        self::assertSame([], $transport->calls);
    }

    public function testAddsBalanceAfterToFilteredRecords(): void
    {
        $targetRow = [
            'id' => '2',
            'budget_account_id' => '1',
            'budget_object_id' => '20',
            'difference' => '-200',
            'operation_date' => '2026-01-02 10:00:00',
            'currency_id' => '3',
            'operation_type' => '3',
        ];
        $allRows = [
            [
                'id' => '3',
                'budget_account_id' => '1',
                'budget_object_id' => '30',
                'difference' => '100',
                'operation_date' => '2026-01-02 12:00:00',
                'currency_id' => '3',
                'operation_type' => '2',
            ],
            $targetRow,
            [
                'id' => '1',
                'budget_account_id' => '1',
                'budget_object_id' => '10',
                'difference' => '500',
                'operation_date' => '2026-01-01 09:00:00',
                'currency_id' => '3',
                'operation_type' => '2',
            ],
        ];
        $transport = new FakeTransport([
            'getRecordList' => ['__sequence' => [[$targetRow], $allRows]],
            'getBalance' => [[
                'place_id' => '1',
                'currency_id' => '3',
                'sum' => '400',
            ]],
        ]);
        $service = $this->service($transport);

        $records = $service->list(
            RecordQuery::forDateRange(
                new \DateTimeImmutable('2026-01-01'),
                new \DateTimeImmutable('2026-01-02'),
            )
                ->operationType(OperationType::Expense)
                ->onlyCategories(['20'])
                ->withBalanceAfter(),
        );

        self::assertSame(300, $records[0]->balanceAfter?->minorUnits);
        self::assertSame(2, $records[0]->balanceAfter->scale);
        self::assertSame('3', $records[0]->balanceAfter->currencyId);
        self::assertSame(['getRecordList', 'getRecordList', 'getBalance'], array_column($transport->calls, 'method'));
        self::assertTrue($transport->mapArgument(0)['is_report']);
        self::assertTrue($transport->mapArgument(1)['is_report']);
        self::assertSame(0, $transport->mapArgument(1)['r_is_category']);
        self::assertSame('2026-01-02', $transport->mapArgument(2)['restDate']);
    }

    public function testReusesUnfilteredDateRangeRowsForBalanceCalculation(): void
    {
        $row = [
            'id' => '1',
            'place_id' => '1',
            'budget_object_id' => '10',
            'sum' => '500',
            'operation_date' => '2026-01-01 09:00:00',
            'currency_id' => '3',
            'operation_type' => '2',
        ];
        $transport = new FakeTransport([
            'getRecordList' => [$row],
            'getBalance' => [[
                'place_id' => '1',
                'currency_id' => '3',
                'sum' => '500',
            ]],
        ]);
        $service = $this->service($transport);

        $records = $service->list(
            RecordQuery::forDateRange(
                new \DateTimeImmutable('2026-01-01'),
                new \DateTimeImmutable('2026-01-01'),
            )->withBalanceAfter(),
        );

        self::assertSame(500, $records[0]->balanceAfter?->minorUnits);
        self::assertSame(['getRecordList', 'getBalance'], array_column($transport->calls, 'method'));
        self::assertTrue($transport->mapArgument(0)['is_report']);
    }

    public function testBalanceAfterRejectsBalanceWithoutSum(): void
    {
        $row = [
            'id' => '1',
            'place_id' => '1',
            'budget_object_id' => '10',
            'sum' => '500',
            'operation_date' => '2026-01-01 09:00:00',
            'currency_id' => '3',
            'operation_type' => '2',
        ];
        $transport = new FakeTransport([
            'getRecordList' => [$row],
            'getBalance' => [[
                'place_id' => '1',
                'currency_id' => '3',
            ]],
        ]);

        $this->expectException(UnexpectedResponseException::class);
        $this->expectExceptionMessage('required field "sum"');

        $this->service($transport)->list(
            RecordQuery::forDateRange(
                new \DateTimeImmutable('2026-01-01'),
                new \DateTimeImmutable('2026-01-01'),
            )->withBalanceAfter(),
        );
    }

    public function testBalanceAfterRejectsMissingAccountCurrencyBalance(): void
    {
        $row = [
            'id' => '1',
            'place_id' => '1',
            'budget_object_id' => '10',
            'sum' => '500',
            'operation_date' => '2026-01-01 09:00:00',
            'currency_id' => '3',
            'operation_type' => '2',
        ];
        $transport = new FakeTransport([
            'getRecordList' => [$row],
            'getBalance' => [[
                'place_id' => '2',
                'currency_id' => '3',
                'sum' => '500',
            ]],
        ]);

        $this->expectException(UnexpectedResponseException::class);
        $this->expectExceptionMessage('does not contain place ID "1"');

        $this->service($transport)->list(
            RecordQuery::forDateRange(
                new \DateTimeImmutable('2026-01-01'),
                new \DateTimeImmutable('2026-01-01'),
            )->withBalanceAfter(),
        );
    }

    public function testBalanceAfterRejectsMalformedSupplementalRecord(): void
    {
        $targetRow = [
            'id' => '2',
            'place_id' => '1',
            'budget_object_id' => '20',
            'sum' => '-200',
            'operation_date' => '2026-01-02 10:00:00',
            'currency_id' => '3',
            'operation_type' => '3',
        ];
        $malformedRow = [
            'id' => '3',
            'place_id' => '1',
            'budget_object_id' => '30',
            'operation_date' => '2026-01-02 12:00:00',
            'currency_id' => '3',
            'operation_type' => '2',
        ];
        $transport = new FakeTransport([
            'getRecordList' => ['__sequence' => [[$targetRow], [$malformedRow, $targetRow]]],
            'getBalance' => [[
                'place_id' => '1',
                'currency_id' => '3',
                'sum' => '400',
            ]],
        ]);

        $this->expectException(UnexpectedResponseException::class);
        $this->expectExceptionMessage('record sum');

        $this->service($transport)->list(
            RecordQuery::forDateRange(
                new \DateTimeImmutable('2026-01-01'),
                new \DateTimeImmutable('2026-01-02'),
            )
                ->operationType(OperationType::Expense)
                ->withBalanceAfter(),
        );
    }

    public function testRejectsConvertedRecordCurrencyAndPointsToReportsBeforeCallingSoap(): void
    {
        $transport = new FakeTransport();
        $service = $this->service($transport);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Use ReportQuery');

        try {
            $service->list(
                RecordQuery::forDateRange(
                    new \DateTimeImmutable('2026-01-01'),
                    new \DateTimeImmutable('2026-01-02'),
                )->convertedToCurrency('3'),
            );
        } finally {
            self::assertSame([], $transport->calls);
        }
    }

    public function testRejectsBalanceAfterForPlannedRecordsBeforeCallingSoap(): void
    {
        $transport = new FakeTransport();
        $service = $this->service($transport);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot be combined with planned records');

        try {
            $service->list(
                (new RecordQuery())->allTime()->includePlanned()->withBalanceAfter(),
            );
        } finally {
            self::assertSame([], $transport->calls);
        }
    }

    public function testRejectsUpdatingSyntheticPlannedRecordBeforeCallingSoap(): void
    {
        $transport = new FakeTransport();
        $record = Record::fromSoap([
            'id' => '2248_598',
            'budget_account_id' => '1',
            'budget_object_id' => '2',
            'difference' => '-120000',
            'operation_date' => '2037-12-29 13:59:00',
            'currency_id' => '3',
            'operation_type' => 3,
            'is_planned' => 't',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot update a planned record');

        try {
            $this->service($transport)->update($record);
        } finally {
            self::assertSame([], $transport->calls);
        }
    }

    public function testReadsCryptoRecordWithCurrencyScaleAndBinding(): void
    {
        $transport = new FakeTransport(['getRecordList' => [[
            'id' => '10',
            'place_id' => '1',
            'budget_object_id' => '2',
            'sum' => '1234',
            'operation_date' => '2026-07-14 12:00:00',
            'currency_id' => '7',
            'operation_type' => '3',
        ]]]);
        $btc = Currency::fromSoap([
            'id' => '7',
            'name' => 'Bitcoin',
            'code' => 'BTC',
            'ratio' => '1000000',
        ]);

        $record = $this->service($transport, currencies: [$btc])->list()[0];

        self::assertSame(8, $record->sum->scale);
        self::assertSame('7', $record->sum->currencyId);
        self::assertSame('0.00001234', $record->sum->toDecimalString());
    }

    public function testRejectsAmountScaleThatDoesNotMatchCurrencyBeforeWrite(): void
    {
        $transport = new FakeTransport();
        $btc = Currency::fromSoap([
            'id' => '7',
            'name' => 'Bitcoin',
            'code' => 'BTC',
            'ratio' => '1000000',
        ]);
        $service = $this->service($transport, currencies: [$btc]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Currency::amount()');

        try {
            $service->createExpense(
                placeId: '1',
                categoryId: '2',
                amount: MoneyAmount::fromDecimalString('1.00'),
                currencyId: '7',
                date: new \DateTimeImmutable('2026-07-14 12:00:00'),
            );
        } finally {
            self::assertSame([], $transport->calls);
        }
    }

    public function testCurrencyAmountCreatesValidatedWritePayload(): void
    {
        $transport = new FakeTransport();
        $btc = Currency::fromSoap([
            'id' => '7',
            'name' => 'Bitcoin',
            'code' => 'BTC',
            'ratio' => '1000000',
        ]);
        $service = $this->service($transport, currencies: [$btc]);

        $service->createExpense(
            placeId: '1',
            categoryId: '2',
            amount: $btc->amount('0.00001234'),
            currencyId: $btc->id,
            date: new \DateTimeImmutable('2026-07-14 12:00:00'),
        );

        self::assertSame(-1234, $transport->mapListArgument(0)[0]['sum']);
    }

    public function testRejectsAmountBoundToAnotherCurrencyBeforeWrite(): void
    {
        $transport = new FakeTransport();
        $btc = Currency::fromSoap(['id' => '7', 'name' => 'Bitcoin', 'code' => 'BTC', 'ratio' => '1000000']);
        $eth = Currency::fromSoap(['id' => '8', 'name' => 'Ethereum', 'code' => 'ETH', 'ratio' => '1000000']);
        $service = $this->service($transport, currencies: [$btc, $eth]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('bound to currency ID "7"');

        try {
            $service->createExpense(
                placeId: '1',
                categoryId: '2',
                amount: $btc->amount('0.00001234'),
                currencyId: $eth->id,
                date: new \DateTimeImmutable('2026-07-14 12:00:00'),
            );
        } finally {
            self::assertSame([], $transport->calls);
        }
    }

    /** @param list<Currency>|null $currencies */
    private function service(
        FakeTransport $transport,
        ?ClientOptions $options = null,
        ?array $currencies = null,
    ): RecordService {
        $currencies ??= [Currency::fromSoap([
            'id' => '3',
            'name' => 'EUR',
            'code' => 'EUR',
            'ratio' => '1',
        ])];

        return new RecordService(
            $transport,
            $options ?? new ClientOptions(),
            new CurrencyCatalog($transport, $currencies),
        );
    }
}
