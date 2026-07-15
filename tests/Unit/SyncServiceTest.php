<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Soz\Drebedengi\DrebedengiClient;
use Soz\Drebedengi\Exception\UnexpectedResponseException;
use Soz\Drebedengi\Service\SyncService;
use Soz\Drebedengi\Tests\Support\FakeTransport;

final class SyncServiceTest extends TestCase
{
    public function testReadsCurrentRevisionFromIntegerAndCanonicalString(): void
    {
        $integerTransport = new FakeTransport(['getCurrentRevision' => 42]);
        $stringTransport = new FakeTransport(['getCurrentRevision' => ' 42 ']);

        self::assertSame(42, (new SyncService($integerTransport))->currentRevision());
        self::assertSame(42, (new SyncService($stringTransport))->currentRevision());
    }

    public function testRejectsMalformedCurrentRevisionInsteadOfCoercingItToZero(): void
    {
        $this->expectException(UnexpectedResponseException::class);
        $this->expectExceptionMessage('getCurrentRevision response must be a non-negative integer');

        (new SyncService(new FakeTransport(['getCurrentRevision' => 'broken'])))->currentRevision();
    }

    public function testRejectsNegativeCurrentRevision(): void
    {
        $this->expectException(UnexpectedResponseException::class);
        $this->expectExceptionMessage('getCurrentRevision response must be a non-negative integer');

        (new SyncService(new FakeTransport(['getCurrentRevision' => -1])))->currentRevision();
    }

    public function testRejectsNonScalarCurrentRevision(): void
    {
        $this->expectException(UnexpectedResponseException::class);
        $this->expectExceptionMessage('got array');

        (new SyncService(new FakeTransport(['getCurrentRevision' => []])))->currentRevision();
    }

    public function testInitialRecordsUsesExplicitStateChangingSyncMode(): void
    {
        $transport = new FakeTransport([
            'getCurrencyList' => [[
                'id' => '7',
                'name' => 'Bitcoin',
                'code' => 'BTC',
                'ratio' => '1000000',
            ]],
            'getRecordList' => [[
                'id' => '20',
                'place_id' => '1',
                'budget_object_id' => '2',
                'sum' => '-1234',
                'operation_date' => '2026-06-14 12:00:00',
                'currency_id' => '7',
                'operation_type' => '3',
            ]],
        ]);

        $records = (new SyncService($transport))->initialRecords();

        self::assertCount(1, $records);
        self::assertSame('20', $records[0]->id);
        self::assertSame(8, $records[0]->sum->scale);
        self::assertSame('7', $records[0]->sum->currencyId);
        self::assertSame('-0.00001234', $records[0]->sum->toDecimalString());
        self::assertSame(['getCurrencyList', 'getRecordList'], array_column($transport->calls, 'method'));

        $params = $transport->mapArgument(1);
        self::assertFalse($params['is_report']);
        self::assertSame(6, $params['r_period']);
        self::assertSame(6, $params['r_what']);
        self::assertSame(1, $params['r_how']);
    }

    public function testClientSharesCurrencyCatalogWithInitialSync(): void
    {
        $transport = new FakeTransport([
            'getCurrencyList' => [[
                'id' => '7',
                'name' => 'Bitcoin',
                'code' => 'BTC',
                'ratio' => '1000000',
            ]],
            'getRecordList' => [[
                'id' => '20',
                'place_id' => '1',
                'budget_object_id' => '2',
                'sum' => '1234',
                'operation_date' => '2026-06-14 12:00:00',
                'currency_id' => '7',
                'operation_type' => '3',
            ]],
        ]);
        $client = new DrebedengiClient($transport);

        self::assertSame(8, $client->currencies()->require('7')->decimalPlaces);
        self::assertSame(8, $client->sync()->initialRecords()[0]->sum->scale);
        self::assertSame(['getCurrencyList', 'getRecordList'], array_column($transport->calls, 'method'));
    }

    public function testInitialRecordsRejectsUnknownRecordCurrencyConsistently(): void
    {
        $transport = new FakeTransport([
            'getCurrencyList' => [[
                'id' => '3',
                'name' => 'Euro',
                'code' => 'EUR',
                'ratio' => '1',
            ]],
            'getRecordList' => [[
                'id' => '20',
                'place_id' => '1',
                'budget_object_id' => '2',
                'sum' => '1234',
                'operation_date' => '2026-06-14 12:00:00',
                'currency_id' => '99',
                'operation_type' => '3',
            ]],
        ]);

        $this->expectException(UnexpectedResponseException::class);
        $this->expectExceptionMessage('Record response refers to unknown currency ID "99".');

        (new SyncService($transport))->initialRecords();
    }

    public function testInitialRecordsValidatesCurrencyCatalogBeforeStateChangingRequest(): void
    {
        $transport = new FakeTransport([
            'getCurrencyList' => [[
                'id' => '7',
                'name' => 'Bitcoin',
                'ratio' => '3',
            ]],
            'getRecordList' => [],
        ]);

        $this->expectException(UnexpectedResponseException::class);

        try {
            (new SyncService($transport))->initialRecords();
        } finally {
            self::assertSame(['getCurrencyList'], array_column($transport->calls, 'method'));
        }
    }
}
