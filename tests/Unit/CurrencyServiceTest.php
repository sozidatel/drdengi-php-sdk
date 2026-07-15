<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Soz\Drebedengi\DrebedengiClient;
use Soz\Drebedengi\Exception\UnexpectedResponseException;
use Soz\Drebedengi\Service\CurrencyService;
use Soz\Drebedengi\Tests\Support\FakeTransport;

final class CurrencyServiceTest extends TestCase
{
    public function testFindsCurrenciesAndCachesCatalogUntilRefresh(): void
    {
        $first = [
            ['id' => '3', 'name' => 'Euro', 'code' => 'EUR', 'ratio' => '1', 'is_default' => '1'],
            ['id' => '7', 'name' => 'Bitcoin', 'code' => 'BTC', 'ratio' => '1000000'],
        ];
        $second = [
            ['id' => '3', 'name' => 'Euro', 'code' => 'EUR', 'ratio' => '1', 'is_default' => '1'],
        ];
        $transport = new FakeTransport([
            'getCurrencyList' => ['__sequence' => [$first, $second]],
        ]);
        $service = new CurrencyService($transport);

        self::assertSame(8, $service->require('7')->decimalPlaces);
        self::assertSame('7', $service->requireByCode('btc')->id);
        self::assertSame('3', $service->default()?->id);
        self::assertCount(1, $transport->calls);

        self::assertCount(1, $service->refresh());
        self::assertNull($service->find('7'));
        self::assertCount(2, $transport->calls);
    }

    public function testClientSharesCurrencyCatalogAcrossServices(): void
    {
        $transport = new FakeTransport([
            'getCurrencyList' => [[
                'id' => '7',
                'name' => 'Bitcoin',
                'code' => 'BTC',
                'ratio' => '1000000',
            ]],
            'getBalance' => [[
                'place_id' => '1',
                'currency_id' => '7',
                'sum' => '1234',
            ]],
            'getRecordList' => [[
                'id' => '10',
                'place_id' => '1',
                'budget_object_id' => '2',
                'sum' => '1234',
                'operation_date' => '2026-07-14 12:00:00',
                'currency_id' => '7',
                'operation_type' => '3',
            ]],
        ]);
        $client = new DrebedengiClient($transport);

        self::assertSame(8, $client->currencies()->require('7')->decimalPlaces);
        self::assertSame(8, $client->balance()->list()[0]->sum->scale);
        self::assertSame(8, $client->records()->list()[0]->sum->scale);
        self::assertSame(1, count(array_filter(
            $transport->calls,
            static fn (array $call): bool => $call['method'] === 'getCurrencyList',
        )));
    }

    public function testFailedRefreshKeepsLastValidCatalog(): void
    {
        $valid = [[
            'id' => '7',
            'name' => 'Bitcoin',
            'code' => 'BTC',
            'ratio' => '1000000',
        ]];
        $invalid = [[
            'id' => '3',
            'name' => 'Euro',
            'code' => 'EUR',
            'ratio' => '3',
        ]];
        $transport = new FakeTransport([
            'getCurrencyList' => ['__sequence' => [$valid, $invalid]],
        ]);
        $service = new CurrencyService($transport);
        $cached = $service->require('7');

        try {
            $service->refresh();
            self::fail('Invalid refreshed currency data must be rejected.');
        } catch (UnexpectedResponseException) {
            // Expected: the old cache must remain intact below.
        }

        self::assertSame($cached, $service->require('7'));
        self::assertNull($service->find('3'));
        self::assertCount(2, $transport->calls);
    }

    public function testDuplicateIdRefreshIsRejectedWithoutReplacingValidCatalog(): void
    {
        $valid = [[
            'id' => '7',
            'name' => 'Bitcoin',
            'code' => 'BTC',
            'ratio' => '1000000',
        ]];
        $duplicates = [
            ['id' => '3', 'name' => 'Euro', 'code' => 'EUR', 'ratio' => '1'],
            ['id' => '3', 'name' => 'Other euro', 'code' => 'EUR2', 'ratio' => '100'],
        ];
        $transport = new FakeTransport([
            'getCurrencyList' => ['__sequence' => [$valid, $duplicates]],
        ]);
        $service = new CurrencyService($transport);
        $cached = $service->require('7');

        try {
            $service->refresh();
            self::fail('Duplicate currency IDs must be rejected.');
        } catch (UnexpectedResponseException $exception) {
            self::assertStringContainsString('duplicate ID "3"', $exception->getMessage());
        }

        self::assertSame($cached, $service->require('7'));
        self::assertNull($service->find('3'));
        self::assertCount(2, $transport->calls);
    }
}
