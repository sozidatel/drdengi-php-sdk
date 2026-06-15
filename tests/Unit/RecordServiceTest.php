<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Soz\Drebedengi\ClientOptions;
use Soz\Drebedengi\Exception\InvalidArgumentException;
use Soz\Drebedengi\Model\ExpenseGroupItem;
use Soz\Drebedengi\Model\MoneyAmount;
use Soz\Drebedengi\Model\RecordQuery;
use Soz\Drebedengi\Service\RecordService;
use Soz\Drebedengi\Tests\Support\FakeTransport;

final class RecordServiceTest extends TestCase
{
    public function testCreateExpenseBuildsSetRecordListPayload(): void
    {
        $transport = new FakeTransport(['setRecordList' => [['server_id' => '10']]]);
        $service = new RecordService($transport);

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
        $service = new RecordService($transport);

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
        $service = new RecordService(
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
        $service = new RecordService($transport);

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
        $service = new RecordService($transport);

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
        $service = new RecordService(
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
        $service = new RecordService(
            $transport,
            new ClientOptions(new \DateTimeZone('Europe/Podgorica')),
        );

        $service->list(RecordQuery::forDateRange(
            new \DateTimeImmutable('2026-06-13 22:30:00', new \DateTimeZone('UTC')),
            new \DateTimeImmutable('2026-06-14 22:30:00', new \DateTimeZone('UTC')),
        ));

        $params = $transport->calls[0]['arguments'][0];
        self::assertSame('2026-06-14', $params['period_from']);
        self::assertSame('2026-06-15', $params['period_to']);
    }
}
