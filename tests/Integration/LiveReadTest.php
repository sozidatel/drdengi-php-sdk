<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Soz\Drebedengi\Model\RecordQuery;
use Soz\Drebedengi\Tests\Support\LiveClientFactory;

final class LiveReadTest extends TestCase
{
    public function testCanReadCoreDataFromDrebedengi(): void
    {
        $client = LiveClientFactory::clientOrSkip($this);

        self::assertGreaterThanOrEqual(0, $client->sync()->currentRevision());
        self::assertNotSame('', $client->account()->userId());
        self::assertIsArray($client->places()->list());
        self::assertIsArray($client->categories()->list());
        self::assertIsArray($client->sources()->list());
        $currencies = $client->currencies()->list();
        self::assertIsArray($currencies);
        $currenciesById = [];
        foreach ($currencies as $currency) {
            $currenciesById[$currency->id] = $currency;
        }
        self::assertIsArray($client->tags()->list());
        $balances = $client->balance()->list();
        self::assertIsArray($balances);
        foreach ($balances as $balance) {
            self::assertSame($balance->currencyId, $balance->sum->currencyId);
            self::assertSame($currenciesById[$balance->currencyId]->decimalPlaces, $balance->sum->scale);
        }
        $records = $client->records()->list();
        self::assertIsArray($records);
        foreach ($records as $record) {
            self::assertSame($record->currencyId, $record->sum->currencyId);
            self::assertSame($currenciesById[$record->currencyId]->decimalPlaces, $record->sum->scale);
        }
        if ($records !== []) {
            $recordsById = $client->records()->byIds([$records[0]->id]);
            self::assertNotSame([], $recordsById);
            self::assertSame($records[0]->id, $recordsById[0]->id);
        }

        $recordsWithBalance = $client->records()->list(
            (new RecordQuery())->last20()->withBalanceAfter(),
        );
        foreach ($recordsWithBalance as $record) {
            self::assertNotNull($record->balanceAfter);
            self::assertSame($record->currencyId, $record->balanceAfter->currencyId);
            self::assertSame($currenciesById[$record->currencyId]->decimalPlaces, $record->balanceAfter->scale);
        }
    }
}
