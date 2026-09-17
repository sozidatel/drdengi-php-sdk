<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Soz\Drebedengi\ClientOptions;
use Soz\Drebedengi\Exception\AmbiguousMutationException;
use Soz\Drebedengi\Exception\EndpointUnavailableException;
use Soz\Drebedengi\Exception\InvalidArgumentException;
use Soz\Drebedengi\Exception\SoapFaultException;
use Soz\Drebedengi\Exception\UnconfirmedWriteException;
use Soz\Drebedengi\Model\Currency;
use Soz\Drebedengi\Model\ExpenseGroupItem;
use Soz\Drebedengi\Model\MoneyAmount;
use Soz\Drebedengi\Model\PreparedRecordWrite;
use Soz\Drebedengi\Model\RecordWriteToken;
use Soz\Drebedengi\Model\WriteResult;
use Soz\Drebedengi\Service\RecordService;
use Soz\Drebedengi\Support\CurrencyCatalog;
use Soz\Drebedengi\Tests\Support\FakeTransport;

final class PreparedRecordServiceTest extends TestCase
{
    /** @param non-empty-list<array<string, int|string|bool>> $expected */
    #[DataProvider('operationProvider')]
    public function testPreparationAndLegacyCreatePreserveTheExistingPayload(
        string $operation,
        array $expected,
    ): void {
        $response = array_map(
            static fn (int $index): array => ['server_id' => (string)(100 + $index)],
            array_keys($expected),
        );
        $transport = new FakeTransport(['setRecordList' => $response]);
        $service = $this->service($transport);
        $date = new \DateTimeImmutable('2026-06-14 10:00:00', new \DateTimeZone('UTC'));
        $token = new RecordWriteToken(count($expected) === 1 ? [111] : [111, 222], 333);

        $prepared = $this->prepareOperation($service, $operation, $date, $token);

        self::assertSame([], $transport->calls);
        self::assertEquals($expected, $prepared->payloads);
        self::assertCount(count($expected), $prepared);
        foreach ($prepared->amounts as $index => $amount) {
            self::assertSame($expected[$index]['sum'], $amount->minorUnits);
            self::assertSame($expected[$index]['currency_id'], $amount->currencyId);
            self::assertSame(2, $amount->scale);
        }

        $legacyResult = $this->createOperation($service, $operation, $date, $token);
        $preparedResult = $service->submit($prepared);

        self::assertSame($prepared->payloads, $transport->mapListArgument(0));
        self::assertSame($prepared->payloads, $transport->mapListArgument(1));
        self::assertSame(['setRecordList', 'setRecordList'], array_column($transport->calls, 'method'));
        self::assertSame($legacyResult->jsonSerialize(), $preparedResult->jsonSerialize());
    }

    /** @return iterable<string, array{string, non-empty-list<array<string, int|string|bool>>}> */
    public static function operationProvider(): iterable
    {
        $common = [
            'operation_date' => '2026-06-14 12:00:00',
            'comment' => 'Saved operation',
            'currency_id' => '3',
            'is_duty' => false,
        ];

        yield 'expense' => ['expense', [[
            'client_id' => 111, 'place_id' => '1', 'budget_object_id' => '2',
            'sum' => -1234, 'operation_type' => 3,
        ] + $common]];
        yield 'income' => ['income', [[
            'client_id' => 111, 'place_id' => '1', 'budget_object_id' => '2',
            'sum' => 1234, 'operation_type' => 2,
        ] + $common]];
        yield 'transfer' => ['transfer', [
            [
                'client_id' => 111, 'client_move_id' => 222, 'place_id' => '1',
                'budget_object_id' => '2', 'sum' => -1234, 'operation_type' => 4,
            ] + $common,
            [
                'client_id' => 222, 'client_move_id' => 111, 'place_id' => '2',
                'budget_object_id' => '1', 'sum' => 1234, 'operation_type' => 4,
            ] + $common,
        ]];
        yield 'exchange' => ['exchange', [
            [
                'client_id' => 111, 'client_change_id' => 222, 'place_id' => '1',
                'budget_object_id' => '1', 'sum' => -1234, 'operation_type' => 5,
            ] + $common,
            [
                'client_id' => 222, 'client_change_id' => 111, 'place_id' => '1',
                'budget_object_id' => '1', 'sum' => 567, 'operation_type' => 5,
                'currency_id' => '4',
            ] + $common,
        ]];
        yield 'expense group' => ['group', [
            [
                'client_id' => 111, 'place_id' => '1', 'budget_object_id' => '2',
                'sum' => -1234, 'operation_type' => 3, 'group_id' => '333',
            ] + $common,
            [
                'client_id' => 222, 'place_id' => '1', 'budget_object_id' => '6',
                'sum' => -567, 'operation_type' => 3, 'group_id' => '333',
                'comment' => 'Second item',
            ] + $common,
        ]];
    }

    public function testPreparationMayReadCurrencyCatalogButDoesNotWrite(): void
    {
        $transport = new FakeTransport([
            'getCurrencyList' => [['id' => '3', 'name' => 'EUR', 'code' => 'EUR', 'ratio' => '1']],
            'setRecordList' => [['server_id' => '100']],
        ]);
        $service = new RecordService($transport);

        $prepared = $service->prepareExpense(
            '1', '2', MoneyAmount::fromDecimalString('12.34'), '3', new \DateTimeImmutable(),
        );

        self::assertSame(['getCurrencyList'], array_column($transport->calls, 'method'));
        self::assertSame(-1234, $prepared->payloads[0]['sum']);
        $service->submit($prepared);
        self::assertSame(['getCurrencyList', 'setRecordList'], array_column($transport->calls, 'method'));
    }

    public function testMutableDateAndDifferentSubmittingTimezoneCannotChangePreparedRequest(): void
    {
        $date = new \DateTime('2026-06-14 10:00:00', new \DateTimeZone('UTC'));
        $prepared = $this->service(new FakeTransport())->prepareExpense(
            '1', '2', MoneyAmount::fromDecimalString('12.34'), '3', $date,
        );
        $captured = $prepared->payloads;
        $date->modify('+2 days')->setTimezone(new \DateTimeZone('America/New_York'));
        $transport = new FakeTransport([
            'getCurrencyList' => new \LogicException('Submitting must not read the currency catalog.'),
            'setRecordList' => [['server_id' => '100']],
        ]);
        $service = new RecordService($transport, new ClientOptions(new \DateTimeZone('Asia/Tokyo')));

        $service->submit($prepared);

        self::assertSame('2026-06-14 12:00:00', $captured[0]['operation_date']);
        self::assertSame($captured, $transport->mapListArgument(0));
        self::assertSame($captured, $prepared->payloads);
        self::assertCount(1, $transport->calls);
    }

    public function testJsonRestorationKeepsBothExchangeScalesWithoutCatalogReadsOnSubmit(): void
    {
        $btc = Currency::fromSoap(['id' => '7', 'name' => 'Bitcoin', 'code' => 'BTC', 'ratio' => '1000000']);
        $eur = Currency::fromSoap(['id' => '3', 'name' => 'EUR', 'code' => 'EUR', 'ratio' => '1']);
        $preparingTransport = new FakeTransport();
        $preparingService = new RecordService(
            $preparingTransport,
            new ClientOptions(),
            new CurrencyCatalog($preparingTransport, [$btc, $eur]),
        );
        $prepared = $preparingService->prepareExchange(
            '1', $btc->amount('0.00001234'), '7', $eur->amount('0.99'), '3',
            new \DateTimeImmutable('2026-06-14 12:00:00'),
            writeToken: new RecordWriteToken([111, 222], 333),
        );
        $restored = PreparedRecordWrite::fromJson($prepared->toJson());
        $transport = new FakeTransport([
            'getCurrencyList' => new \LogicException('Submitting must not read the currency catalog.'),
            'setRecordList' => [['server_id' => '100'], ['server_id' => '101']],
        ]);

        $result = (new RecordService($transport))->submit($restored);

        self::assertSame([], $preparingTransport->calls);
        self::assertSame($prepared->payloads, $transport->mapListArgument(0));
        self::assertSame([8, 2], array_map(static fn (MoneyAmount $amount): int => $amount->scale, $restored->amounts));
        self::assertSame(['7', '3'], array_map(static fn (MoneyAmount $amount): ?string => $amount->currencyId, $restored->amounts));
        self::assertSame(['-0.00001234', '0.99'], array_map(static fn (MoneyAmount $amount): string => $amount->toDecimalString(), $restored->amounts));
        self::assertSame([111, 222], $result->clientIds);
        self::assertSame(['100', '101'], $result->serverIds);
        self::assertCount(1, $transport->calls);
    }

    public function testChangedCatalogDoesNotReinterpretOrRejectAnAlreadyPreparedAmount(): void
    {
        $transport = new FakeTransport([
            'getCurrencyList' => [['id' => '3', 'name' => 'EUR', 'code' => 'EUR', 'ratio' => '1000000']],
            'setRecordList' => [['server_id' => '100']],
        ]);
        $catalog = new CurrencyCatalog($transport, [
            Currency::fromSoap(['id' => '3', 'name' => 'EUR', 'code' => 'EUR', 'ratio' => '1']),
        ]);
        $service = new RecordService($transport, currencies: $catalog);
        $prepared = $service->prepareIncome(
            '1', '2', MoneyAmount::fromDecimalString('12.34'), '3', new \DateTimeImmutable(),
        );
        $catalog->refresh();

        $service->submit($prepared);

        self::assertSame(2, $prepared->amounts[0]->scale);
        self::assertSame(1234, $transport->mapListArgument(1)[0]['sum']);
        self::assertSame(['getCurrencyList', 'setRecordList'], array_column($transport->calls, 'method'));
    }

    #[DataProvider('transportExceptionProvider')]
    public function testSubmitPropagatesTheOriginalExceptionWithoutRetrying(\Throwable $failure): void
    {
        $transport = new FakeTransport(['setRecordList' => $failure]);
        $service = $this->service($transport);
        $prepared = $service->prepareExpense(
            '1', '2', MoneyAmount::fromDecimalString('12.34'), '3', new \DateTimeImmutable(),
        );

        try {
            $service->submit($prepared);
            self::fail('Expected the original transport exception.');
        } catch (\Throwable $caught) {
            self::assertSame($failure, $caught);
        }

        self::assertCount(1, $transport->calls);
        self::assertSame($prepared->payloads, $transport->mapListArgument(0));
    }

    /** @return iterable<string, array{\Throwable}> */
    public static function transportExceptionProvider(): iterable
    {
        yield 'ambiguous mutation' => [new AmbiguousMutationException('Response timed out.', method: 'setRecordList')];
        yield 'endpoint unavailable' => [new EndpointUnavailableException('Endpoint unavailable.')];
        yield 'business SOAP fault' => [new SoapFaultException('Record rejected.')];
    }

    public function testPartialConfirmationRetainsExactPreparedPayloadAndDoesNotRetry(): void
    {
        $response = [['server_id' => '100', 'client_id' => 111]];
        $transport = new FakeTransport(['setRecordList' => $response]);
        $service = $this->service($transport);
        $prepared = $service->prepareTransfer(
            '1', '2', MoneyAmount::fromDecimalString('12.34'), '3', new \DateTimeImmutable(),
            writeToken: new RecordWriteToken([111, 222], 333),
        );

        try {
            $service->submit($prepared);
            self::fail('Expected an unconfirmed write.');
        } catch (UnconfirmedWriteException $exception) {
            self::assertSame($prepared->payloads, $exception->payloads);
            self::assertSame($response, $exception->response);
            self::assertFalse($exception->retrySafe);
        }

        self::assertCount(1, $transport->calls);
    }

    public function testEmptyGroupCanBeRestoredAndSubmittedWithoutAnyNetworkCalls(): void
    {
        $transport = new FakeTransport();
        $service = new RecordService($transport);
        $prepared = $service->prepareExpenseGroup(
            '1', [], 'unknown', new \DateTimeImmutable(), new RecordWriteToken([111, 222], 333),
        );
        $restored = PreparedRecordWrite::fromJson($prepared->toJson());

        $result = $service->submit($restored);

        self::assertCount(0, $prepared);
        self::assertSame([], $prepared->payloads);
        self::assertSame([], $prepared->amounts);
        self::assertSame(WriteResult::empty()->jsonSerialize(), $result->jsonSerialize());
        self::assertSame([], $transport->calls);
    }

    public function testSingleItemGroupIsTheSameOperationAsOneExpense(): void
    {
        $transport = new FakeTransport(['setRecordList' => [['server_id' => '100']]]);
        $service = $this->service($transport);
        $date = new \DateTimeImmutable('2026-06-14 12:00:00');
        $token = new RecordWriteToken([111], 333);
        $amount = MoneyAmount::fromDecimalString('12.34');
        $group = $service->prepareExpenseGroup(
            '1', [new ExpenseGroupItem('2', $amount, 'Coffee')], '3', $date, $token,
        );
        $expense = $service->prepareExpense('1', '2', $amount, '3', $date, 'Coffee', $token);

        self::assertSame($expense->jsonSerialize(), $group->jsonSerialize());
        self::assertArrayNotHasKey('group_id', $group->payloads[0]);
        self::assertSame([], $transport->calls);
        self::assertSame(['100'], $service->submit($group)->serverIds);
        self::assertCount(1, $transport->calls);
    }

    public function testPreparationPreservesLegacyStringPlaceAndCategoryIds(): void
    {
        $transport = new FakeTransport(['setRecordList' => [['server_id' => '100']]]);
        $service = $this->service($transport);
        $date = new \DateTimeImmutable('2026-06-14 12:00:00');
        $amount = MoneyAmount::fromDecimalString('1.00');
        $token = new RecordWriteToken([111], 333);
        $prepared = $service->prepareExpense('legacy-place', 'category:key', $amount, '3', $date, writeToken: $token);

        $service->createExpense('legacy-place', 'category:key', $amount, '3', $date, writeToken: $token);

        self::assertSame('legacy-place', $prepared->payloads[0]['place_id']);
        self::assertSame('category:key', $prepared->payloads[0]['budget_object_id']);
        self::assertSame($prepared->payloads, $transport->mapListArgument(0));
    }

    #[DataProvider('invalidAmountProvider')]
    public function testCurrencyErrorsAreReportedDuringPreparation(
        MoneyAmount $amount,
        string $currencyId,
        string $message,
    ): void {
        $transport = new FakeTransport();
        $service = $this->service($transport);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        try {
            $service->prepareExpense('1', '2', $amount, $currencyId, new \DateTimeImmutable());
        } finally {
            self::assertSame([], $transport->calls);
        }
    }

    /** @return iterable<string, array{MoneyAmount, string, string}> */
    public static function invalidAmountProvider(): iterable
    {
        yield 'different bound currency' => [MoneyAmount::fromDecimalString('1.00', currencyId: '4'), '3', 'bound to currency ID "4"'];
        yield 'wrong scale' => [MoneyAmount::fromDecimalString('0.00000001', scale: 8), '3', 'does not match currency'];
        yield 'unknown currency' => [MoneyAmount::fromDecimalString('1.00'), '99', 'Unknown currency ID "99"'];
    }

    private function service(FakeTransport $transport): RecordService
    {
        return new RecordService(
            $transport,
            new ClientOptions(new \DateTimeZone('Europe/Podgorica')),
            new CurrencyCatalog($transport, [
                Currency::fromSoap(['id' => '3', 'name' => 'EUR', 'code' => 'EUR', 'ratio' => '1']),
                Currency::fromSoap(['id' => '4', 'name' => 'USD', 'code' => 'USD', 'ratio' => '1']),
            ]),
        );
    }

    private function prepareOperation(
        RecordService $service,
        string $operation,
        \DateTimeInterface $date,
        RecordWriteToken $token,
    ): PreparedRecordWrite {
        $amount = MoneyAmount::fromDecimalString('-12.34');
        $secondAmount = MoneyAmount::fromDecimalString('-5.67');

        return match ($operation) {
            'expense' => $service->prepareExpense('1', '2', $amount, '3', $date, 'Saved operation', $token),
            'income' => $service->prepareIncome('1', '2', $amount, '3', $date, 'Saved operation', $token),
            'transfer' => $service->prepareTransfer('1', '2', $amount, '3', $date, 'Saved operation', $token),
            'exchange' => $service->prepareExchange('1', $amount, '3', $secondAmount, '4', $date, 'Saved operation', $token),
            'group' => $service->prepareExpenseGroup('1', [
                new ExpenseGroupItem('2', $amount, 'Saved operation'),
                ['categoryId' => '6', 'amount' => $secondAmount, 'comment' => 'Second item'],
            ], '3', $date, $token),
            default => throw new \LogicException('Unknown operation.'),
        };
    }

    private function createOperation(
        RecordService $service,
        string $operation,
        \DateTimeInterface $date,
        RecordWriteToken $token,
    ): WriteResult {
        $amount = MoneyAmount::fromDecimalString('-12.34');
        $secondAmount = MoneyAmount::fromDecimalString('-5.67');

        return match ($operation) {
            'expense' => $service->createExpense('1', '2', $amount, '3', $date, 'Saved operation', $token),
            'income' => $service->createIncome('1', '2', $amount, '3', $date, 'Saved operation', $token),
            'transfer' => $service->createTransfer('1', '2', $amount, '3', $date, 'Saved operation', $token),
            'exchange' => $service->createExchange('1', $amount, '3', $secondAmount, '4', $date, 'Saved operation', $token),
            'group' => $service->createExpenseGroup('1', [
                new ExpenseGroupItem('2', $amount, 'Saved operation'),
                ['categoryId' => '6', 'amount' => $secondAmount, 'comment' => 'Second item'],
            ], '3', $date, $token),
            default => throw new \LogicException('Unknown operation.'),
        };
    }
}
