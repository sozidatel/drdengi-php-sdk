<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Soz\Drebedengi\Exception\UnexpectedResponseException;
use Soz\Drebedengi\Model\BalanceItem;
use Soz\Drebedengi\Model\Currency;
use Soz\Drebedengi\Model\Place;
use Soz\Drebedengi\Model\PlaceType;
use Soz\Drebedengi\Model\Record;

final class DtoMappingTest extends TestCase
{
    public function testMapsLegacyPlaceFlags(): void
    {
        $place = Place::fromSoap([
            'id' => '10',
            'name' => 'Cash',
            'type' => '4',
            'parent_id' => '-1',
            'is_hidden' => 'f',
            'is_for_duty' => 't',
            'is_autohide' => '1',
        ]);

        self::assertSame('10', $place->id);
        self::assertSame(PlaceType::Account, $place->type);
        self::assertNull($place->parentId);
        self::assertNull($place->systemParentId);
        self::assertFalse($place->hidden);
        self::assertTrue($place->forDuty);
        self::assertTrue($place->autoHide);
        self::assertTrue($place->canHaveTransactions());
    }

    public function testMapsPlaceFoldersAndSystemParents(): void
    {
        $folder = Place::fromSoap([
            'id' => '20',
            'name' => 'Crypto',
            'type' => '9',
            'parent_id' => '-1',
        ]);
        $excludedFromTotal = Place::fromSoap([
            'id' => '21',
            'name' => 'Safe',
            'type' => '4',
            'parent_id' => '-3',
        ]);

        self::assertSame(PlaceType::Folder, $folder->type);
        self::assertTrue($folder->isFolder());
        self::assertFalse($folder->canHaveTransactions());

        self::assertNull($excludedFromTotal->parentId);
        self::assertSame(Place::SYSTEM_PARENT_HIDDEN_AMOUNTS, $excludedFromTotal->systemParentId);
        self::assertTrue($excludedFromTotal->isExcludedFromTotal());
        self::assertTrue($excludedFromTotal->canHaveTransactions());
        self::assertFalse($excludedFromTotal->hidden);
    }

    public function testMapsBalanceItemsExcludedFromTotal(): void
    {
        $balance = BalanceItem::fromSoap([
            'place_id' => '21',
            'place_name' => 'Safe',
            'currency_id' => '1',
            'currency_name' => 'RUB',
            'sum' => '5678',
            'parent_id' => '-3',
        ]);

        self::assertSame(Place::SYSTEM_PARENT_HIDDEN_AMOUNTS, $balance->parentId);
        self::assertTrue($balance->isExcludedFromTotal());
    }

    public function testMapsRecordMinorUnitsAndType(): void
    {
        $record = Record::fromSoap([
            'id' => '20',
            'place_id' => '1',
            'budget_object_id' => '2',
            'sum' => '-1234',
            'operation_date' => '2026-06-14 12:00:00',
            'comment' => 'Lunch',
            'currency_id' => '3',
            'is_duty' => 'f',
            'operation_type' => '3',
        ]);

        self::assertSame(-1234, $record->sum->minorUnits);
        self::assertSame('2026-06-14 12:00:00', $record->operationDate->format('Y-m-d H:i:s'));
        self::assertSame(3, $record->operationType->value);
        self::assertNull($record->balanceAfter);

        $withBalance = $record->withBalanceAfter(\Soz\Drebedengi\Model\MoneyAmount::fromMinorUnits(5678));
        self::assertSame(5678, $withBalance->balanceAfter?->minorUnits);
        self::assertNull($record->balanceAfter);
    }

    public function testParsesRecordDateInAccountTimezone(): void
    {
        $record = Record::fromSoap([
            'id' => '20',
            'place_id' => '1',
            'budget_object_id' => '2',
            'sum' => '-1234',
            'operation_date' => '2026-06-14 12:00:00',
            'comment' => 'Lunch',
            'currency_id' => '3',
            'is_duty' => 'f',
            'operation_type' => '3',
        ], new \DateTimeZone('Europe/Podgorica'));

        self::assertSame('Europe/Podgorica', $record->operationDate->getTimezone()->getName());
        self::assertSame('2026-06-14 12:00:00', $record->operationDate->format('Y-m-d H:i:s'));
    }

    public function testMapsDetailReportTransferFieldsWithoutChangingRawPayload(): void
    {
        $raw = [
            'id' => '20',
            'budget_account_id' => '1',
            'budget_object_id' => '2',
            'difference' => '-1234',
            'operation_date' => '2026-06-14 12:00:00',
            'comment' => 'Transfer',
            'currency_id' => '3',
            'is_duty' => 'f',
            'operation_type' => '4',
            'id2' => '21',
        ];

        $record = Record::fromSoap($raw);

        self::assertSame('1', $record->placeId);
        self::assertSame(-1234, $record->sum->minorUnits);
        self::assertSame('21', $record->serverMoveId);
        self::assertNull($record->serverChangeId);
        self::assertSame($raw, $record->raw);
    }

    public function testMapsDetailReportExchangeLink(): void
    {
        $record = Record::fromSoap([
            'id' => '20',
            'budget_account_id' => '1',
            'budget_object_id' => '1',
            'difference' => '-1234',
            'operation_date' => '2026-06-14 12:00:00',
            'currency_id' => '3',
            'operation_type' => '5',
            'id2' => '22',
        ]);

        self::assertNull($record->serverMoveId);
        self::assertSame('22', $record->serverChangeId);
    }

    public function testMapsCurrencyRatioToDecimalPlaces(): void
    {
        $fiat = Currency::fromSoap([
            'id' => '1',
            'name' => '₽',
            'ratio' => '1',
        ]);
        $btc = Currency::fromSoap([
            'id' => '2',
            'name' => 'BTC',
            'code' => 'BTC',
            'is_investing' => 't',
            'ratio' => '1000000',
        ]);

        self::assertSame(2, $fiat->decimalPlaces);
        self::assertSame(8, $btc->decimalPlaces);
        self::assertSame(1_000_000, $btc->ratio);
        self::assertTrue($btc->investing);
    }

    public function testRejectsRecordWithoutRequiredFinancialFields(): void
    {
        $this->expectException(UnexpectedResponseException::class);
        $this->expectExceptionMessage('record ID');

        Record::fromSoap([]);
    }

    public function testRejectsBalanceWithoutRequiredFinancialFields(): void
    {
        $this->expectException(UnexpectedResponseException::class);
        $this->expectExceptionMessage('place_id');

        BalanceItem::fromSoap([]);
    }
}
