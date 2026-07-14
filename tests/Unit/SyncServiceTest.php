<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Soz\Drebedengi\Service\SyncService;
use Soz\Drebedengi\Tests\Support\FakeTransport;

final class SyncServiceTest extends TestCase
{
    public function testInitialRecordsUsesExplicitStateChangingSyncMode(): void
    {
        $transport = new FakeTransport([
            'getRecordList' => [[
                'id' => '20',
                'place_id' => '1',
                'budget_object_id' => '2',
                'sum' => '-1234',
                'operation_date' => '2026-06-14 12:00:00',
                'currency_id' => '3',
                'operation_type' => '3',
            ]],
        ]);

        $records = (new SyncService($transport))->initialRecords();

        self::assertCount(1, $records);
        self::assertSame('20', $records[0]->id);
        self::assertSame('getRecordList', $transport->calls[0]['method']);

        $params = $transport->calls[0]['arguments'][0];
        self::assertFalse($params['is_report']);
        self::assertSame(6, $params['r_period']);
        self::assertSame(6, $params['r_what']);
        self::assertSame(1, $params['r_how']);
    }
}
