<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Soz\Drebedengi\Exception\InvalidArgumentException;
use Soz\Drebedengi\Model\MoneyAmount;
use Soz\Drebedengi\Model\OperationType;
use Soz\Drebedengi\Model\Record;
use Soz\Drebedengi\Model\RecordPatch;

final class RecordPatchTest extends TestCase
{
    public function testPatchPreservesDirectionAndClearsStaleBalance(): void
    {
        $record = $this->record()->withBalanceAfter(MoneyAmount::fromMinorUnits(5000));

        $patched = $record->withPatch(new RecordPatch(
            placeId: 5,
            budgetObjectId: 6,
            amount: MoneyAmount::fromDecimalString('-56.78'),
            operationDate: new \DateTime('2026-06-15 09:30:00'),
            comment: '',
            duty: true,
        ));

        self::assertSame('5', $patched->placeId);
        self::assertSame('6', $patched->budgetObjectId);
        self::assertSame(-5678, $patched->sum->minorUnits);
        self::assertSame('2026-06-15 09:30:00', $patched->operationDate->format('Y-m-d H:i:s'));
        self::assertSame('', $patched->comment);
        self::assertTrue($patched->duty);
        self::assertNull($patched->balanceAfter);

        self::assertSame('1', $record->placeId);
        self::assertSame(-1234, $record->sum->minorUnits);
        self::assertSame('Lunch', $record->comment);
        self::assertNotNull($record->balanceAfter);
    }

    public function testCommentOnlyPatchKeepsBalanceAfter(): void
    {
        $record = $this->record()->withBalanceAfter(MoneyAmount::fromMinorUnits(5000));

        $patched = (new RecordPatch(comment: 'Dinner'))->applyTo($record);

        self::assertSame('Dinner', $patched->comment);
        self::assertSame(5000, $patched->balanceAfter?->minorUnits);
    }

    public function testAmountPatchUsesOperationTypeForZeroExpenseDirection(): void
    {
        $record = Record::fromSoap([
            'id' => '21',
            'place_id' => '1',
            'budget_object_id' => '2',
            'sum' => '0',
            'operation_date' => '2026-06-14 12:00:00',
            'comment' => 'Zero expense',
            'currency_id' => '3',
            'is_duty' => 'f',
            'operation_type' => OperationType::Expense->value,
        ]);

        $patched = $record->withPatch(new RecordPatch(
            amount: MoneyAmount::fromDecimalString('1.23'),
        ));

        self::assertSame(-123, $patched->sum->minorUnits);
    }

    public function testRejectsEmptyPatch(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must change at least one field');

        new RecordPatch();
    }

    private function record(): Record
    {
        return Record::fromSoap([
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
    }
}
