<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Soz\Drebedengi\Model\BalanceQuery;
use Soz\Drebedengi\Model\RecordQuery;
use Soz\Drebedengi\Model\ReportQuery;
use Soz\Drebedengi\Tests\Support\LiveClientFactory;

final class LiveReadTest extends TestCase
{
    public function testCanReadCoreDataFromDrebedengi(): void
    {
        $client = LiveClientFactory::readClientOrSkip($this);

        self::assertGreaterThanOrEqual(0, $client->sync()->currentRevision());
        self::assertNotSame('', $client->account()->userId());
        $client->account()->hasAccess();
        $client->account()->rightAccess();
        $client->account()->subscriptionStatus();
        $client->account()->expireDate();
        $client->places()->list();
        $client->categories()->list();
        $client->sources()->list();
        $currencies = $client->currencies()->list();
        self::assertNotEmpty($currencies);
        $currenciesById = [];
        foreach ($currencies as $currency) {
            $currenciesById[$currency->id] = $currency;
        }
        $client->tags()->list();
        $balances = $client->balance()->list();
        foreach ($balances as $balance) {
            self::assertSame($balance->currencyId, $balance->sum->currencyId);
            self::assertSame($currenciesById[$balance->currencyId]->decimalPlaces, $balance->sum->scale);
        }
        $client->balance()->list(
            BalanceQuery::at(new \DateTimeImmutable('today'))
                ->includeHidden()
                ->includeZero()
                ->subtractAccumulations()
                ->subtractDebts(),
        );
        $records = $client->records()->list();
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
        $client->records()->list(
            (new RecordQuery())->today()->includePlanned()->includeDebts(),
        );

        $reportQuery = (new ReportQuery())
            ->thisMonth()
            ->convertedToCurrency($currencies[0]->id);
        $expenseReport = $client->reports()->expensesByCategory($reportQuery);
        $incomeReport = $client->reports()->incomeBySource($reportQuery);
        foreach ([...$expenseReport, ...$incomeReport] as $row) {
            self::assertSame($row->currencyId, $row->amount->currencyId);
            self::assertSame($currenciesById[$row->currencyId]->decimalPlaces, $row->amount->scale);
        }
    }
}
