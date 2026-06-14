<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Soz\Drebedengi\ClientOptions;
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
