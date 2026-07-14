<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Soz\Drebedengi\ClientOptions;
use Soz\Drebedengi\Exception\InvalidArgumentException;
use Soz\Drebedengi\Exception\UnexpectedResponseException;
use Soz\Drebedengi\Model\Currency;
use Soz\Drebedengi\Model\ExpenseGroupItem;
use Soz\Drebedengi\Model\MoneyAmount;
use Soz\Drebedengi\Model\OperationType;
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

        $payload = $transport->calls[0]['arguments'][0][0];
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

        $payloads = $transport->calls[0]['arguments'][0];
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

        $payload = $transport->calls[0]['arguments'][0][0];
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

        $payload = $transport->calls[0]['arguments'][0][0];
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

        $groupedPayloads = $transport->calls[0]['arguments'][0];
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

    public function testCreateExpenseGroupValidatesArrayItems(): void
    {
        $service = new RecordService(new FakeTransport());

        $this->expectException(InvalidArgumentException::class);

        $service->createExpenseGroup(
            placeId: '1',
            items: [
                ['categoryId' => '10', 'amount' => '12.34'],
            ],
            currencyId: '3',
            date: new \DateTimeImmutable('2026-06-14 12:00:00'),
        );
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

        $params = $transport->calls[0]['arguments'][0];
        self::assertTrue($params['is_report']);
        self::assertSame('2026-06-14', $params['period_from']);
        self::assertSame('2026-06-15', $params['period_to']);
        self::assertCount(1, $transport->calls);
        self::assertSame('getRecordList', $transport->calls[0]['method']);
    }

    public function testReadsRecordsByIdsWithExplicitSafeMode(): void
    {
        $transport = new FakeTransport(['getRecordList' => []]);
        $service = $this->service($transport);

        $service->byIds(['10', 20]);

        self::assertSame(['is_report' => true], $transport->calls[0]['arguments'][0]);
        self::assertSame(['10', '20'], $transport->calls[0]['arguments'][1]);
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
        self::assertSame(2, $records[0]->balanceAfter?->scale);
        self::assertSame('3', $records[0]->balanceAfter?->currencyId);
        self::assertSame(['getRecordList', 'getRecordList', 'getBalance'], array_column($transport->calls, 'method'));
        self::assertTrue($transport->calls[0]['arguments'][0]['is_report']);
        self::assertTrue($transport->calls[1]['arguments'][0]['is_report']);
        self::assertSame(0, $transport->calls[1]['arguments'][0]['r_is_category']);
        self::assertSame('2026-01-02', $transport->calls[2]['arguments'][0]['restDate']);
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
        self::assertTrue($transport->calls[0]['arguments'][0]['is_report']);
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

    public function testRejectsBalanceAfterForConvertedCurrencyBeforeCallingSoap(): void
    {
        $transport = new FakeTransport();
        $service = $this->service($transport);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('original currency');

        try {
            $service->list(
                RecordQuery::forDateRange(
                    new \DateTimeImmutable('2026-01-01'),
                    new \DateTimeImmutable('2026-01-02'),
                )->convertedToCurrency('3')->withBalanceAfter(),
            );
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

        self::assertSame(-1234, $transport->calls[0]['arguments'][0][0]['sum']);
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
