<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Soz\Drebedengi\Exception\UnexpectedResponseException;
use Soz\Drebedengi\Model\BalanceItem;
use Soz\Drebedengi\Model\Category;
use Soz\Drebedengi\Model\Change;
use Soz\Drebedengi\Model\Currency;
use Soz\Drebedengi\Model\Place;
use Soz\Drebedengi\Model\PlaceType;
use Soz\Drebedengi\Model\Record;
use Soz\Drebedengi\Model\Source;
use Soz\Drebedengi\Model\Tag;

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

    public function testRejectsCurrencyWithoutRatio(): void
    {
        $this->expectException(UnexpectedResponseException::class);
        $this->expectExceptionMessage('required field "ratio"');

        Currency::fromSoap(['id' => '1', 'name' => 'Euro']);
    }

    public function testRejectsUnsupportedCurrencyRatio(): void
    {
        $this->expectException(UnexpectedResponseException::class);
        $this->expectExceptionMessage('invalid ratio 3');

        Currency::fromSoap(['id' => '1', 'name' => 'Euro', 'ratio' => '3']);
    }

    public function testMapsValidChange(): void
    {
        $change = Change::fromSoap([
            'revision' => '42',
            'action_id' => '2',
            'object_type_id' => '1',
            'object_id' => '100',
            'date' => '2026-07-14 12:00:00',
        ]);

        self::assertSame(42, $change->revision);
        self::assertSame('100', $change->objectId);
        self::assertSame('2026-07-14 12:00:00', $change->date?->format('Y-m-d H:i:s'));
    }

    public function testRejectsChangeWithoutRevision(): void
    {
        $this->expectException(UnexpectedResponseException::class);
        $this->expectExceptionMessage('required field "revision"');

        Change::fromSoap([
            'action_id' => '2',
            'object_type_id' => '1',
            'object_id' => '100',
        ]);
    }

    public function testRejectsChangeWithInvalidDate(): void
    {
        $this->expectException(UnexpectedResponseException::class);
        $this->expectExceptionMessage('invalid field "date"');

        Change::fromSoap([
            'revision' => '42',
            'action_id' => '2',
            'object_type_id' => '1',
            'object_id' => '100',
            'date' => 'definitely not a date',
        ]);
    }

    public function testRejectsChangeWithUnknownAction(): void
    {
        $this->expectException(UnexpectedResponseException::class);
        $this->expectExceptionMessage('unknown action ID 99');

        Change::fromSoap([
            'revision' => '42',
            'action_id' => '99',
            'object_type_id' => '1',
            'object_id' => '100',
        ]);
    }

    /**
     * @param class-string $dtoClass
     * @param array<string, mixed> $raw
     */
    #[DataProvider('incompleteReferenceDtoProvider')]
    public function testRejectsIncompleteReferenceDto(string $dtoClass, array $raw, string $field): void
    {
        $this->expectException(UnexpectedResponseException::class);
        $this->expectExceptionMessage(sprintf('required field "%s"', $field));

        $dtoClass::fromSoap($raw);
    }

    /** @return iterable<string, array{class-string, array<string, string>, string}> */
    public static function incompleteReferenceDtoProvider(): iterable
    {
        foreach ([
            'place' => Place::class,
            'category' => Category::class,
            'source' => Source::class,
            'tag' => Tag::class,
        ] as $label => $class) {
            yield $label . ' without ID' => [$class, ['name' => 'Name'], 'id'];
            yield $label . ' without name' => [$class, ['id' => '10'], 'name'];
        }
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
